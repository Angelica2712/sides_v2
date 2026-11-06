<?php

namespace App\Services\MonitorTv;

use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesUsers;
use App\Services\Packing\RetencionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Vista «Alertas» de los televisores: pedidos en retraso y semáforo de rendimiento de cada
 * despachador contra su propio promedio. Porta el MonitorAlertasService del SIDES de mastranto
 * con la misma respuesta; los parámetros están en sides_cfg.alerta* (Configuración).
 *
 * En v2 el despachador y el embalador están en sides_pedido_operacion (por nombre) y los tiempos
 * en sides_logpicking / sides_logpacking (por correo, sin codisb: se filtra uniendo con pedido).
 */
class MonitorAlertasService
{
    private const CACHE_SEGUNDOS = 600;

    // Las etapas que no ocurrieron no son NULL en pedido: quedan como 2020-01-01.
    private const FECHA_MINIMA_VALIDA = '2021-01-01 00:00:00';

    private const ETAPA_POR_ESTADO = ['RECIBIDO' => 'ESPERA', 'PICKING' => 'PICKING', 'PACKING' => 'PACKING'];

    private const ORDEN_MOTIVOS = ['LIMITE' => 0, 'PROMEDIO' => 1];

    private const ORDEN_ESTADOS = ['ROJO' => 0, 'AMARILLO' => 1, 'VERDE' => 2, 'SIN_DATOS' => 3];

    /** etapa => [tabla, columna de segundos, columna de fecha] */
    private const LOGS = [
        'PICKING' => ['sides_logpicking', 'tiempo_picking', 'fecha_del_picking'],
        'PACKING' => ['sides_logpacking', 'tiempo_packing', 'fecha_del_packing'],
    ];

    /** @return array<string, mixed> */
    public function generar(string $codisb): array
    {
        $p = $this->parametros($codisb);
        $ahora = Carbon::now();

        $activos = DB::table('pedido')
            ->leftJoin('sides_pedido_operacion as op', 'op.id_pedido', '=', 'pedido.id')
            ->where('pedido.codisb', $codisb)
            ->whereIn('pedido.estado', array_keys(self::ETAPA_POR_ESTADO))
            // Un pedido retenido ya terminó el packing: su espera no es un retraso de nadie.
            ->whereNotIn('pedido.id', app(RetencionService::class)->retenidos($codisb))
            ->get([
                'pedido.id', 'pedido.ruta', 'pedido.numren', 'pedido.numund', 'pedido.estado',
                'pedido.fecprocesado', 'pedido.fecpicking', 'pedido.fecpacking', 'op.despachador', 'op.embalador',
            ]);

        return [
            'config' => [
                'umbralAvisoPct' => $p['umbralAviso'],
                'umbralAlertaPct' => $p['umbralAlerta'],
                'ventanaDias' => $p['ventanaDias'],
                'limiteEsperaMin' => $p['limiteEspera'],
                'limitePickingMin' => $p['limitePicking'],
                'limitePackingMin' => $p['limitePacking'],
            ],
            'retrasos' => $this->retrasos($codisb, $p, $activos, $ahora),
            'rendimiento' => $this->rendimiento($codisb, $p, $activos, $ahora),
        ];
    }

    /** @return array<string, int> */
    private function parametros(string $codisb): array
    {
        $cfg = SidesCfg::query()->find($codisb);

        return [
            'limiteEspera' => (int) ($cfg->alertaLimiteEsperaMin ?? 0),
            'limitePicking' => (int) ($cfg->alertaLimitePickingMin ?? 0),
            'limitePacking' => (int) ($cfg->alertaLimitePackingMin ?? 0),
            'tolerancia' => (int) ($cfg->alertaTolerancia ?? 50),
            'umbralAviso' => (int) ($cfg->alertaUmbralAviso ?? 10),
            'umbralAlerta' => (int) ($cfg->alertaUmbralAlerta ?? 20),
            'ventanaDias' => max(1, (int) ($cfg->alertaVentanaDias ?? 30)),
            'muestraMinima' => (int) ($cfg->alertaMuestraMinima ?? 20),
            'muestraMinimaHoy' => (int) ($cfg->alertaMuestraMinimaHoy ?? 3),
        ];
    }

    // ------------------------------------------------------------------ retrasos

    private function retrasos(string $codisb, array $p, Collection $activos, Carbon $ahora): array
    {
        $prom = $this->promediosPorUnidad($codisb, $p);
        $alertas = [];
        $porEtapa = ['espera' => 0, 'picking' => 0, 'packing' => 0];

        foreach ($activos as $ped) {
            $etapa = self::ETAPA_POR_ESTADO[$ped->estado];
            $unidades = (int) $ped->numund;
            [$inicio, $limiteMin] = match ($etapa) {
                'ESPERA' => [$this->fecha($ped->fecprocesado), $p['limiteEspera']],
                'PICKING' => [$this->fecha($ped->fecpicking), $p['limitePicking']],
                default => [$this->fecha($ped->fecpacking), $p['limitePacking']],
            };

            // Cada criterio: [motivo, segundos transcurridos, segundos de umbral].
            $criterios = [];
            $seg = $inicio ? $this->segundosDesde($inicio, $ahora) : 0;

            if ($limiteMin > 0 && $inicio && $seg > $limiteMin * 60) {
                $criterios[] = ['LIMITE', $seg, $limiteMin * 60];
            }

            $promEtapa = $etapa === 'PICKING' ? $prom['picking'] : ($etapa === 'PACKING' ? $prom['packing'] : null);
            if ($p['tolerancia'] > 0 && $unidades > 0 && $inicio && $promEtapa) {
                $umbral = $promEtapa * $unidades * (1 + $p['tolerancia'] / 100);
                if ($seg > $umbral) {
                    $criterios[] = ['PROMEDIO', $seg, $umbral];
                }
            }

            if (! $criterios) {
                continue;
            }

            // Se reporta el criterio con mayor exceso; los motivos van todos, en orden fijo.
            $principal = collect($criterios)->sortByDesc(fn ($c) => $c[1] - $c[2])->first();
            usort($criterios, fn ($a, $b) => self::ORDEN_MOTIVOS[$a[0]] <=> self::ORDEN_MOTIVOS[$b[0]]);

            $alertas[] = [
                'pedido' => (string) $ped->id,
                'ruta' => empty($ped->ruta) ? 'S/RUTA' : $ped->ruta,
                'unidad' => $unidades,
                'etapa' => $etapa,
                'transcurridoMin' => (int) floor($principal[1] / 60),
                'umbralMin' => (int) round($principal[2] / 60),
                'excesoMin' => max(0, (int) floor(($principal[1] - $principal[2]) / 60)),
                'motivos' => array_column($criterios, 0),
                'exceso_seg' => $principal[1] - $principal[2],
            ];

            $porEtapa[strtolower($etapa)]++;
        }

        usort($alertas, fn ($a, $b) => $b['exceso_seg'] <=> $a['exceso_seg']);
        $alertas = array_map(function ($alerta) {
            unset($alerta['exceso_seg']);

            return $alerta;
        }, $alertas);

        return [
            'promedios' => $prom['picking'] || $prom['packing'] ? [
                'picking' => $prom['picking'] ? ['segPorUnidad' => round($prom['picking'], 1)] : null,
                'packing' => $prom['packing'] ? ['segPorUnidad' => round($prom['packing'], 1)] : null,
                'muestra' => $prom['muestra'],
            ] : null,
            'resumen' => [
                'enRetraso' => count($alertas),
                'enTiempo' => $activos->count() - count($alertas),
                'alertasPorEtapa' => $porEtapa,
            ],
            'pedidos' => $alertas,
        ];
    }

    /**
     * Segundos por unidad de cada etapa (razón de sumas) en la ventana de días. Cada promedio es
     * null si su muestra no alcanza el mínimo. Los totales se guardan en caché.
     */
    private function promediosPorUnidad(string $codisb, array $p): array
    {
        $totales = Cache::remember(
            sprintf('monitor_alertas:prom:%s:%d', $codisb, $p['ventanaDias']),
            self::CACHE_SEGUNDOS,
            function () use ($codisb, $p) {
                $desde = Carbon::now()->subDays($p['ventanaDias'])->toDateTimeString();

                return [
                    'picking' => $this->totales($this->consultaLog('PICKING', $codisb, $desde)->first()),
                    'packing' => $this->totales($this->consultaLog('PACKING', $codisb, $desde)->first()),
                ];
            }
        );

        $prom = ['muestra' => $totales['picking']['pedidos']];
        foreach (['picking', 'packing'] as $etapa) {
            $t = $totales[$etapa];
            $prom[$etapa] = $t['pedidos'] >= $p['muestraMinima'] && $t['und'] > 0 && $t['seg'] > 0 ? $t['seg'] / $t['und'] : null;
        }

        return $prom;
    }

    // --------------------------------------------------------------- rendimiento

    private function rendimiento(string $codisb, array $p, Collection $activos, Carbon $ahora): array
    {
        $inicioHoy = $ahora->copy()->startOfDay();

        $nombrePorEmail = [];
        $emailsPorNombre = [];
        foreach (SidesUsers::query()->where('codisb', $codisb)->get(['name', 'email']) as $u) {
            $nombrePorEmail[strtolower($u->email)] = $u->name;
            $emailsPorNombre[mb_strtoupper(trim((string) $u->name))][] = strtolower($u->email);
        }

        $filas = [];
        foreach (['PICKING' => 'despachador', 'PACKING' => 'embalador'] as $etapa => $campo) {
            $base = $this->lineaBase($etapa, $codisb, $p, $inicioHoy);

            $hoy = [];
            foreach ($this->consultaLog($etapa, $codisb, $inicioHoy->toDateTimeString(), null, true)->get() as $f) {
                $hoy[strtolower($f->usuario)] = $this->totales($f);
            }

            // Pedido que cada usuario tiene en curso en esta etapa (el nombre debe ser único en la droguería).
            $enCurso = [];
            foreach ($activos as $ped) {
                $nombre = mb_strtoupper(trim((string) $ped->{$campo}));
                if ($ped->estado !== $etapa || $nombre === '' || count($emailsPorNombre[$nombre] ?? []) !== 1) {
                    continue;
                }
                $enCurso[$emailsPorNombre[$nombre][0]][] = $ped;
            }

            foreach (array_unique([...array_keys($hoy), ...array_keys($enCurso)]) as $email) {
                $filas[] = $this->filaUsuario(
                    $etapa,
                    $nombrePorEmail[$email] ?? $email,
                    $base[$email] ?? null,
                    $hoy[$email] ?? null,
                    $enCurso[$email] ?? [],
                    $p,
                    $ahora
                );
            }
        }

        usort($filas, function ($a, $b) {
            if ($a['estado'] !== $b['estado']) {
                return self::ORDEN_ESTADOS[$a['estado']] <=> self::ORDEN_ESTADOS[$b['estado']];
            }

            return ($b['desviacionPct'] ?? -INF) <=> ($a['desviacionPct'] ?? -INF);
        });

        $resumen = ['rojo' => 0, 'amarillo' => 0, 'verde' => 0, 'sinDatos' => 0];
        $claves = ['ROJO' => 'rojo', 'AMARILLO' => 'amarillo', 'VERDE' => 'verde', 'SIN_DATOS' => 'sinDatos'];
        foreach ($filas as $fila) {
            $resumen[$claves[$fila['estado']]]++;
        }

        return ['resumen' => $resumen, 'despachadores' => $filas];
    }

    /** Promedio propio de cada usuario en la ventana, sin contar hoy. Se guarda en caché. */
    private function lineaBase(string $etapa, string $codisb, array $p, Carbon $inicioHoy): array
    {
        return Cache::remember(
            sprintf('monitor_alertas:base:%s:%s:%d', $codisb, $etapa, $p['ventanaDias']),
            self::CACHE_SEGUNDOS,
            function () use ($etapa, $codisb, $p, $inicioHoy) {
                $desde = $inicioHoy->copy()->subDays($p['ventanaDias'])->toDateTimeString();
                $base = [];
                foreach ($this->consultaLog($etapa, $codisb, $desde, $inicioHoy->toDateTimeString(), true)->get() as $f) {
                    $base[strtolower($f->usuario)] = $this->totales($f);
                }

                return $base;
            }
        );
    }

    private function filaUsuario(string $etapa, string $nombre, ?array $base, ?array $hoy, array $pedidosEnCurso, array $p, Carbon $ahora): array
    {
        $baseValida = $base && $base['pedidos'] >= $p['muestraMinima'] && $base['und'] > 0 && $base['ren'] > 0 && $base['seg'] > 0;
        $spu = $baseValida ? $base['seg'] / $base['und'] : null;
        $spr = $baseValida ? $base['seg'] / $base['ren'] : null;

        $senalHoy = null;
        $senalCurso = null;

        if ($baseValida && $hoy && $hoy['pedidos'] >= $p['muestraMinimaHoy'] && $hoy['und'] > 0 && $hoy['ren'] > 0) {
            $hoyUnd = $hoy['seg'] / $hoy['und'];
            $hoyRen = $hoy['seg'] / $hoy['ren'];
            $dU = ($hoyUnd / $spu - 1) * 100;
            $dR = ($hoyRen / $spr - 1) * 100;

            $senalHoy = [
                'pct' => ($dU + $dR) / 2,
                'salida' => [
                    'pedidos' => $hoy['pedidos'],
                    'segPorUnidad' => round($hoyUnd, 1),
                    'promedioSegPorUnidad' => round($spu, 1),
                    'segPorRenglon' => round($hoyRen, 1),
                    'promedioSegPorRenglon' => round($spr, 1),
                    'desviacionUnidadPct' => (int) round($dU),
                    'desviacionRenglonPct' => (int) round($dR),
                    'desviacionPct' => (int) round(($dU + $dR) / 2),
                ],
            ];
        }

        if ($baseValida) {
            foreach ($pedidosEnCurso as $ped) {
                $inicio = $this->fecha($etapa === 'PICKING' ? $ped->fecpicking : $ped->fecpacking);
                if (! $inicio || (int) $ped->numund <= 0 || (int) $ped->numren <= 0) {
                    continue;
                }

                $seg = $this->segundosDesde($inicio, $ahora);
                $espU = $spu * (int) $ped->numund;
                $espR = $spr * (int) $ped->numren;
                $dU = ($seg / $espU - 1) * 100;
                $dR = ($seg / $espR - 1) * 100;
                $pct = ($dU + $dR) / 2;

                if ($senalCurso === null || $pct > $senalCurso['pct']) {
                    $senalCurso = [
                        'pct' => $pct,
                        'pedido' => (string) $ped->id,
                        'salida' => [
                            'pedido' => (string) $ped->id,
                            'transcurridoMin' => (int) floor($seg / 60),
                            'esperadoUnidadMin' => (int) round($espU / 60),
                            'esperadoRenglonMin' => (int) round($espR / 60),
                            'desviacionUnidadPct' => (int) round($dU),
                            'desviacionRenglonPct' => (int) round($dR),
                            'desviacionPct' => (int) round($pct),
                        ],
                    ];
                }
            }
        }

        $pct = $senalHoy['pct'] ?? null;
        $origen = $senalHoy ? 'HOY' : null;
        $pedidoAlerta = null;
        if ($senalCurso && ($pct === null || $senalCurso['pct'] > $pct)) {
            $pct = $senalCurso['pct'];
            $origen = 'EN_CURSO';
            $pedidoAlerta = $senalCurso['pedido'];
        }

        $estado = match (true) {
            $pct === null => 'SIN_DATOS',
            $pct >= $p['umbralAlerta'] => 'ROJO',
            $pct >= $p['umbralAviso'] => 'AMARILLO',
            default => 'VERDE',
        };

        return [
            'nombre' => mb_strtoupper($nombre),
            'etapa' => $etapa,
            'estado' => $estado,
            'desviacionPct' => $pct === null ? null : (int) round($pct),
            'origen' => $origen,
            'hoy' => $senalHoy['salida'] ?? null,
            'enCurso' => $senalCurso['salida'] ?? null,
            'mensaje' => $this->mensaje($estado, $origen, $pct, $etapa, $pedidoAlerta),
        ];
    }

    private function mensaje(string $estado, ?string $origen, ?float $pct, string $etapa, ?string $pedido): ?string
    {
        if ($estado !== 'ROJO' && $estado !== 'AMARILLO') {
            return null;
        }

        $porcentaje = (int) round($pct);
        $etapaTexto = strtolower($etapa);

        return $origen === 'EN_CURSO'
            ? "Su pedido {$pedido} lleva {$porcentaje} % más de lo esperado según su promedio en {$etapaTexto}"
            : "Hoy se está tardando {$porcentaje} % más de su promedio en {$etapaTexto}";
    }

    // ------------------------------------------------------------------- consultas

    /**
     * Sumas del log de una etapa (seg, und, ren, pedidos) de la droguería en [$desde, $hasta),
     * de todos juntos o por usuario (correo).
     */
    private function consultaLog(string $etapa, string $codisb, string $desde, ?string $hasta = null, bool $porUsuario = false)
    {
        [$tabla, $tiempo, $fecha] = self::LOGS[$etapa];

        return DB::table($tabla)
            ->join('pedido as p', 'p.id', '=', "{$tabla}.id_pedido")
            ->where('p.codisb', $codisb)
            ->where("{$tabla}.{$fecha}", '>=', $desde)
            ->when($hasta, fn ($consulta) => $consulta->where("{$tabla}.{$fecha}", '<', $hasta))
            ->when($porUsuario, fn ($consulta) => $consulta->groupBy("{$tabla}.usuario")->addSelect("{$tabla}.usuario"))
            ->addSelect(DB::raw(
                "COALESCE(SUM({$tabla}.{$tiempo}), 0) as seg, ".
                "COALESCE(SUM({$tabla}.numund), 0) as und, ".
                "COALESCE(SUM({$tabla}.numren), 0) as ren, ".
                "COUNT(DISTINCT {$tabla}.id_pedido) as pedidos"
            ));
    }

    /** @return array{seg: float, und: float, ren: float, pedidos: int} */
    private function totales(?object $fila): array
    {
        return [
            'seg' => (float) ($fila->seg ?? 0),
            'und' => (float) ($fila->und ?? 0),
            'ren' => (float) ($fila->ren ?? 0),
            'pedidos' => (int) ($fila->pedidos ?? 0),
        ];
    }

    /** Fecha válida o null (las etapas que no ocurrieron traen la fecha centinela 2020-01-01). */
    private function fecha(mixed $valor): ?Carbon
    {
        if (empty($valor) || (string) $valor <= self::FECHA_MINIMA_VALIDA) {
            return null;
        }

        return Carbon::parse($valor);
    }

    private function segundosDesde(Carbon $inicio, Carbon $ahora): int
    {
        return max(0, $ahora->timestamp - $inicio->timestamp);
    }
}
