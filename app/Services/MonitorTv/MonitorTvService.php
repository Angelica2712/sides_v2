<?php

namespace App\Services\MonitorTv;

use App\Models\Sides\SidesMonitorTv;
use App\Models\Sides\SidesUsers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Vinculación de los televisores de la droguería. Porta el MonitorTvService que el compañero hizo
 * en el SIDES de mastranto, con el mismo contrato para su app:
 *
 * 1. La TV pide un código (POST /api/monitor_solicitar_codigo con su app_uuid) y lo muestra.
 * 2. Quien administra la droguería lo escribe en SIDES (Televisores), le pone un nombre, elige el
 *    usuario al que queda vinculada y qué vistas muestra.
 * 3. La TV consulta POST /api/monitor_estado hasta que la aprueban y recibe su token una sola vez.
 *
 * Diferencias con el legacy: la vista «picking» (Picking en vivo con el Almacén 3D); el usuario
 * puede ser de monitor o de «Pantalla de TV»; el token por entregar va cifrado y, si la TV no lo
 * recoge en CODIGO_VIGENCIA_SEGUNDOS, la vinculación se descarta y la TV pide otro código.
 */
class MonitorTvService
{
    public const CODIGO_VIGENCIA_SEGUNDOS = 600;

    public const CODIGO_LONGITUD = 6;

    // Sin I, L, O ni 0, 1 para evitar confusiones al leer el código en pantalla.
    private const LETRAS = 'ABCDEFGHJKMNPQRSTUVWXYZ';

    private const DIGITOS = '23456789';

    public const ERRORES = [
        'CODIGO_INVALIDO' => 'Código inválido o vencido.',
        'ALIAS_INVALIDO' => 'El nombre de la TV es obligatorio y admite hasta 100 caracteres.',
        'ALIAS_DUPLICADO' => 'Ya hay una TV con ese nombre en esta droguería.',
        'USUARIO_INVALIDO' => 'El usuario elegido no existe, está inactivo, es de otra droguería o no tiene Monitor ni Pantalla de TV.',
        'SIN_VISTAS' => 'Marca al menos una vista para esta TV.',
    ];

    public function normalizarCodigo(?string $codigo): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $codigo));
    }

    public function formatearCodigo(string $codigo): string
    {
        return substr($codigo, 0, 3).'-'.substr($codigo, 3);
    }

    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * La TV pide un código. Si su app_uuid ya está vinculada (y su usuario sigue activo) devuelve
     * YA_VINCULADA; si no, la deja en PENDIENTE con un código nuevo.
     *
     * @return array{ok: true, codigo: string, expiraEn: int, expiraAt: string}|array{ok: false, error: string}
     */
    public function solicitarCodigo(string $appUuid, ?string $plataforma = null, ?string $appVersion = null): array
    {
        $this->purgarVencidos();

        return DB::transaction(function () use ($appUuid, $plataforma, $appVersion) {
            $tv = SidesMonitorTv::query()->where('app_uuid', $appUuid)->lockForUpdate()->first();

            if ($tv && $tv->estado === 'ACTIVO' && $this->usuarioVinculado($tv)) {
                return ['ok' => false, 'error' => 'YA_VINCULADA'];
            }

            $tv ??= new SidesMonitorTv(['app_uuid' => $appUuid]);
            $expira = Carbon::now()->addSeconds(self::CODIGO_VIGENCIA_SEGUNDOS);

            $tv->fill([
                'estado' => 'PENDIENTE',
                'codigo' => $this->generarCodigoUnico(),
                'codigo_expira' => $expira,
                'plataforma' => $plataforma,
                'app_version' => $appVersion,
                'codisb' => null,
                'alias' => null,
                'usuario_id' => null,
                'token_hash' => null,
                'token_entrega' => null,
                'aprobado_por' => null,
                'aprobado_at' => null,
                'last_seen_at' => null,
            ])->save();

            return [
                'ok' => true,
                'codigo' => $this->formatearCodigo($tv->codigo),
                'expiraEn' => self::CODIGO_VIGENCIA_SEGUNDOS,
                'expiraAt' => $expira->toIso8601String(),
            ];
        });
    }

    /**
     * Estado de la vinculación para la TV. El token se entrega una sola vez: en la primera
     * consulta después de la aprobación.
     *
     * @return array<string, mixed>
     */
    public function estado(string $appUuid): array
    {
        return DB::transaction(function () use ($appUuid) {
            $tv = SidesMonitorTv::query()->where('app_uuid', $appUuid)->lockForUpdate()->first();

            if (! $tv) {
                return ['estado' => 'DESCONOCIDO'];
            }

            if ($tv->estado === 'ACTIVO') {
                if (! $this->usuarioVinculado($tv) || $this->entregaVencida($tv)) {
                    // Sin usuario o sin recoger su token a tiempo: la TV vuelve a pedir un código.
                    if ($this->entregaVencida($tv)) {
                        $tv->delete();
                    }

                    return ['estado' => 'DESCONOCIDO'];
                }

                $datos = ['estado' => 'ACTIVO', 'alias' => $tv->alias];

                if (filled($tv->token_entrega)) {
                    $datos['token'] = $tv->token_entrega;
                    $tv->forceFill(['token_entrega' => null])->save();
                }

                return $datos;
            }

            $restante = $tv->codigo_expira ? (int) Carbon::now()->diffInSeconds($tv->codigo_expira, false) : 0;

            return $restante <= 0 ? ['estado' => 'VENCIDO'] : ['estado' => 'PENDIENTE', 'expiraEn' => $restante];
        });
    }

    /** La TV pendiente con el código que escribió quien administra, o null si no existe o venció. */
    public function buscarPendiente(?string $codigoEscrito): ?SidesMonitorTv
    {
        $codigo = $this->normalizarCodigo($codigoEscrito);

        return $codigo === '' ? null : SidesMonitorTv::query()
            ->where('estado', 'PENDIENTE')
            ->where('codigo', $codigo)
            ->where('codigo_expira', '>', Carbon::now())
            ->first();
    }

    /**
     * Aprobación desde SIDES: el código que se ve en la TV, su nombre, el usuario de la misma
     * droguería al que queda vinculada y las vistas que muestra ESTA TV.
     *
     * @param  array{monitor?: bool, alertas?: bool, picking?: bool}  $vistas
     * @return array{ok: true, tv: SidesMonitorTv}|array{ok: false, error: string}
     */
    public function aprobar(?string $codigoEscrito, ?string $alias, int $usuarioId, array $vistas, SidesUsers $admin): array
    {
        $codigo = $this->normalizarCodigo($codigoEscrito);

        return DB::transaction(function () use ($codigo, $alias, $usuarioId, $vistas, $admin) {
            $tv = $codigo === '' ? null : SidesMonitorTv::query()
                ->where('estado', 'PENDIENTE')
                ->where('codigo', $codigo)
                ->where('codigo_expira', '>', Carbon::now())
                ->lockForUpdate()
                ->first();

            if (! $tv) {
                return ['ok' => false, 'error' => 'CODIGO_INVALIDO'];
            }

            $asignacion = $this->validarAsignacion($alias, $usuarioId, $vistas, (string) $admin->codisb, $tv->id);
            if (isset($asignacion['error'])) {
                return ['ok' => false, 'error' => $asignacion['error']];
            }

            $token = Str::random(64);

            $tv->forceFill([
                ...$asignacion,
                'estado' => 'ACTIVO',
                'codigo' => null,
                'codigo_expira' => null,
                'codisb' => $admin->codisb,
                'token_hash' => $this->hashToken($token),
                'token_entrega' => $token,
                'aprobado_por' => $admin->id,
                'aprobado_at' => Carbon::now(),
            ])->save();

            return ['ok' => true, 'tv' => $tv];
        });
    }

    /**
     * Cambia el nombre, el usuario o las vistas de una TV ya aprobada. La TV lo toma en su
     * siguiente consulta a monitor_config, sin volver a vincularla.
     *
     * @param  array{monitor?: bool, alertas?: bool, picking?: bool}  $vistas
     * @return array{ok: true, tv: SidesMonitorTv}|array{ok: false, error: string}
     */
    public function actualizar(SidesMonitorTv $tv, ?string $alias, int $usuarioId, array $vistas): array
    {
        $asignacion = $this->validarAsignacion($alias, $usuarioId, $vistas, (string) $tv->codisb, $tv->id);
        if (isset($asignacion['error'])) {
            return ['ok' => false, 'error' => $asignacion['error']];
        }

        $tv->forceFill($asignacion)->save();

        return ['ok' => true, 'tv' => $tv];
    }

    /** @return array{tv: SidesMonitorTv, usuario: SidesUsers}|null */
    public function autenticar(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }

        $tv = SidesMonitorTv::query()
            ->where('token_hash', $this->hashToken($token))
            ->where('estado', 'ACTIVO')
            ->first();
        $usuario = $tv ? $this->usuarioVinculado($tv) : null;

        return $usuario ? ['tv' => $tv, 'usuario' => $usuario] : null;
    }

    public function usuarioVinculado(SidesMonitorTv $tv): ?SidesUsers
    {
        return $tv->usuario_id
            ? SidesUsers::query()->whereKey($tv->usuario_id)->where('estado', 'ACTIVO')->first()
            : null;
    }

    /**
     * Usuarios de la droguería a los que se puede vincular una TV: activos, con Monitor o con
     * «Pantalla de TV». Al editar se incluye el actual aunque ya no cumpla, para no perderlo.
     */
    public function usuariosCandidatos(string $codisb, ?int $incluirId = null)
    {
        return SidesUsers::query()
            ->where('codisb', $codisb)
            ->where('estado', 'ACTIVO')
            ->where(function ($consulta) use ($incluirId) {
                $consulta->where('activarMonitor', 1)->orWhere('activarTv', 1);
                if ($incluirId) {
                    $consulta->orWhere('id', $incluirId);
                }
            })
            ->orderBy('name')
            ->get();
    }

    public function registrarFallo(?int $monitorTvId, ?string $ip, int $codigoHttp): void
    {
        try {
            DB::table('sides_monitor_tv_log')->insert([
                'monitor_tv_id' => $monitorTvId,
                'ip' => $ip,
                'status_code' => $codigoHttp,
                'created_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('MONITOR TV -> NO SE PUDO REGISTRAR EL INTENTO FALLIDO: '.$e->getMessage());
        }
    }

    /** @return array<string, mixed> columnas a guardar, o ['error' => clave de ERRORES] */
    private function validarAsignacion(?string $alias, int $usuarioId, array $vistas, string $codisb, int $tvId): array
    {
        $alias = trim((string) $alias);
        if ($alias === '' || mb_strlen($alias) > 100) {
            return ['error' => 'ALIAS_INVALIDO'];
        }

        $usuario = SidesUsers::query()
            ->whereKey($usuarioId)
            ->where('codisb', $codisb)
            ->where('estado', 'ACTIVO')
            ->where(fn ($consulta) => $consulta->where('activarMonitor', 1)->orWhere('activarTv', 1))
            ->first();
        if (! $usuario) {
            return ['error' => 'USUARIO_INVALIDO'];
        }

        $aliasEnUso = SidesMonitorTv::query()
            ->where('codisb', $codisb)
            ->where('alias', $alias)
            ->whereKeyNot($tvId)
            ->exists();
        if ($aliasEnUso) {
            return ['error' => 'ALIAS_DUPLICADO'];
        }

        $columnas = [
            'vistaMonitor' => ! empty($vistas['monitor']),
            'vistaAlertas' => ! empty($vistas['alertas']),
            'vistaPicking' => ! empty($vistas['picking']),
        ];
        if (! in_array(true, $columnas, true)) {
            return ['error' => 'SIN_VISTAS'];
        }

        return ['alias' => $alias, 'usuario_id' => $usuario->id, ...$columnas];
    }

    /** Aprobada pero la TV no recogió su token a tiempo. */
    private function entregaVencida(SidesMonitorTv $tv): bool
    {
        return filled($tv->getRawOriginal('token_entrega'))
            && $tv->aprobado_at?->lt(Carbon::now()->subSeconds(self::CODIGO_VIGENCIA_SEGUNDOS));
    }

    /** Códigos vencidos hace más de un día y aprobaciones cuyo token nadie recogió. */
    private function purgarVencidos(): void
    {
        SidesMonitorTv::query()
            ->where('estado', 'PENDIENTE')
            ->where('codigo_expira', '<', Carbon::now()->subDay())
            ->delete();

        SidesMonitorTv::query()
            ->where('estado', 'ACTIVO')
            ->whereNotNull('token_entrega')
            ->where('aprobado_at', '<', Carbon::now()->subSeconds(self::CODIGO_VIGENCIA_SEGUNDOS))
            ->delete();
    }

    private function generarCodigoUnico(): string
    {
        for ($intento = 0; $intento < 20; $intento++) {
            $codigo = $this->generarCodigo();

            $enUso = SidesMonitorTv::query()
                ->where('estado', 'PENDIENTE')
                ->where('codigo', $codigo)
                ->where('codigo_expira', '>', Carbon::now())
                ->exists();

            if (! $enUso) {
                return $codigo;
            }
        }

        throw new RuntimeException('No fue posible generar un código de vinculación único.');
    }

    /** 6 caracteres con al menos una letra y un número. */
    private function generarCodigo(): string
    {
        $alfabeto = self::LETRAS.self::DIGITOS;
        $max = strlen($alfabeto) - 1;

        do {
            $codigo = '';
            for ($i = 0; $i < self::CODIGO_LONGITUD; $i++) {
                $codigo .= $alfabeto[random_int(0, $max)];
            }
        } while (! preg_match('/[A-Z]/', $codigo) || ! preg_match('/[0-9]/', $codigo));

        return $codigo;
    }
}
