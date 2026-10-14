<?php

namespace App\Services\Packing;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesLogInacPacking;
use App\Models\Sides\SidesLogpacking;
use App\Models\Sides\SidesPedidoOperacion;
use App\Models\Sides\SidesPedrenOperacion;
use App\Models\Sides\SidesUsers;
use App\Services\Despacho\DespachoSeped;
use App\Services\Despacho\RenglonesPedido;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Packing de un pedido: verificar por escaneo lo que salió de picking y cerrar el pedido
 * hacia facturación. Porta AdminpackingController de los tres SIDES; droactiva y dromarko
 * son idénticos y mastranto agregó mejoras que aquí aplican a todas las sucursales:
 *
 * - El pedido queda a nombre de quien lo abre (embalador) y se puede liberar; nadie más
 *   puede verificar, ajustar ni terminar un pedido ajeno.
 * - Terminar bloquea el pedido y exige que siga en PACKING (evita cierres duplicados).
 * - La cantidad verificada (chequeado) se guarda en cada escaneo: recargar la página no
 *   pierde el avance (en droactiva/dromarko solo vivía en el navegador).
 * - La clave de supervisor se valida en el servidor (el legacy la enviaba dentro del HTML).
 *
 * Al terminar, en SEPED se escribe pedido.estado/feccompletado y pedren.cantdesp/estado_desp
 * (DespachoSeped); bultos, cestas y responsables van en sides_pedido_operacion.
 * Las etiquetas al terminar (sides_cfg.activar_etiqueta_packing) las prepara PackingController
 * con EtiquetasService.
 */
class PackingService
{
    public function __construct(private readonly DespachoSeped $despachoSeped)
    {
    }

    /** Abre el pedido para empacar: lo deja a nombre del usuario y marca el inicio (fecpacking2). */
    public function abrir(SidesUsers $usuario, int $pedidoId): Pedido
    {
        return DB::transaction(function () use ($usuario, $pedidoId) {
            $pedido = $this->pedidoEnPacking($usuario, $pedidoId);

            $operacion = SidesPedidoOperacion::query()->whereKey($pedidoId)->lockForUpdate()->first()
                ?? SidesPedidoOperacion::query()->create(['id_pedido' => $pedidoId, 'codisb' => $pedido->codisb]);

            if ($operacion->embalador && $operacion->embalador !== $usuario->name) {
                throw new PackingException("El pedido #{$pedidoId} lo está empacando {$operacion->embalador}.");
            }

            $operacion->update(['embalador' => $usuario->name, 'fecpacking2' => Carbon::now()]);

            return $pedido;
        });
    }

    /**
     * Suma unidades verificadas por escaneo: una por lectura, o varias si el empacador indica
     * la cantidad antes de escanear. @return array{chequeado: int, cantdesp: int}
     */
    public function escanear(SidesUsers $usuario, int $pedidoId, int $item, int $unidades = 1): array
    {
        return DB::transaction(function () use ($usuario, $pedidoId, $item, $unidades) {
            $this->operacionPropia($usuario, $this->pedidoEnPacking($usuario, $pedidoId));

            $renglon = $this->renglonBloqueado($pedidoId, $item);
            $cantdesp = (int) $renglon->cantdesp;
            $chequeado = max(0, (int) $renglon->chequeado);

            if ($cantdesp <= 0) {
                throw new PackingException('Ese producto no lleva unidades para despachar.');
            }

            if ($chequeado >= $cantdesp) {
                throw new PackingException("Ese producto ya está verificado completo ({$cantdesp}).");
            }

            $faltan = $cantdesp - $chequeado;
            if ($unidades < 1 || $unidades > $faltan) {
                throw new PackingException($faltan === 1 ? 'Solo falta 1 unidad de ese producto.' : "Solo faltan {$faltan} unidades de ese producto.");
            }

            $this->actualizarRenglon($pedidoId, $item, ['chequeado' => $chequeado + $unidades]);

            return ['chequeado' => $chequeado + $unidades, 'cantdesp' => $cantdesp];
        });
    }

    /** Cambia la cantidad a despachar y la da por verificada (legacy: "Modificar cantidad"). */
    public function ajustar(SidesUsers $usuario, int $pedidoId, int $item, int $cantidad, ?string $clave): int
    {
        return DB::transaction(function () use ($usuario, $pedidoId, $item, $cantidad, $clave) {
            $this->operacionPropia($usuario, $this->pedidoEnPacking($usuario, $pedidoId));

            $solicitado = DB::table('pedren')->where('id', $pedidoId)->where('item', $item)->value('cantidad');
            if ($solicitado === null) {
                throw new PackingException('Ese producto no pertenece al pedido.');
            }

            if ($cantidad < 0 || $cantidad > (int) $solicitado) {
                throw new PackingException("La cantidad debe estar entre 0 y {$solicitado}.");
            }

            $cfg = SidesCfg::query()->find($usuario->codisb);
            if ($cfg?->activarValPacking) {
                $this->verificarClave($cfg, $clave);
            }

            $this->renglonBloqueado($pedidoId, $item);
            $this->actualizarRenglon($pedidoId, $item, ['cantdesp' => $cantidad, 'chequeado' => $cantidad]);

            return $cantidad;
        });
    }

    /** Clave de supervisor para continuar tras leer un código que no corresponde (legacy: bValidar). */
    public function validarClave(SidesUsers $usuario, int $pedidoId, ?string $clave): void
    {
        $this->operacionPropia($usuario, $this->pedidoEnPacking($usuario, $pedidoId));
        $this->verificarClave(SidesCfg::query()->find($usuario->codisb), $clave);
    }

    /** @return array{valor: string, lote: string, vence: string, cantidad: int, deposito: string} */
    public function cambiarLote(SidesUsers $usuario, int $pedidoId, int $item, string $opcion): array
    {
        return DB::transaction(function () use ($usuario, $pedidoId, $item, $opcion) {
            $this->operacionPropia($usuario, $this->pedidoEnPacking($usuario, $pedidoId));

            $listalote = DB::table('pedren')->where('id', $pedidoId)->where('item', $item)->value('listalote');
            $lote = collect(RenglonesPedido::lotes($listalote))->firstWhere('valor', $opcion);
            if (! $lote) {
                throw new PackingException('Ese lote no está disponible para el producto.');
            }

            $renglon = $this->renglonBloqueado($pedidoId, $item);
            if ((int) $renglon->cantdesp > $lote['cantidad']) {
                throw new PackingException("La cantidad a despachar ({$renglon->cantdesp}) supera la existencia del lote {$lote['lote']} ({$lote['cantidad']}).");
            }

            $this->actualizarRenglon($pedidoId, $item, [
                'lote' => $lote['lote'],
                'feclote' => $lote['vence'],
                'deposito' => $lote['deposito'],
            ]);

            return $lote;
        });
    }

    /**
     * @param  array{cantBultos: string, despachador: ?string, embalador: ?string, cestas: ?string}  $datos
     * @return 'PEND-FACTURA'|'ANULADO'
     */
    public function terminar(SidesUsers $usuario, int $pedidoId, array $datos): string
    {
        return DB::transaction(function () use ($usuario, $pedidoId, $datos) {
            $pedido = $this->pedidoDeSucursal($usuario, $pedidoId);
            if ($pedido->estado === 'PEND-FACTURA') {
                throw new PackingException("El pedido #{$pedidoId} ya fue enviado a facturar.");
            }
            $this->exigirPacking($pedido);
            $operacion = $this->operacionPropia($usuario, $pedido);

            $renglones = SidesPedrenOperacion::query()->where('id_pedido', $pedidoId)->get(['item', 'cantdesp', 'chequeado']);

            if ($renglones->contains(fn ($renglon) => $renglon->cantdesp < 0)) {
                throw new PackingException('Hay productos que no pasaron por picking. Revisa el pedido en picking.');
            }

            $faltan = $renglones->filter(fn ($renglon) => $renglon->cantdesp > 0 && max(0, (int) $renglon->chequeado) < $renglon->cantdesp)->count();
            if ($faltan > 0) {
                throw new PackingException($faltan === 1 ? 'Falta 1 producto por verificar.' : "Faltan {$faltan} productos por verificar.");
            }

            $unidades = (int) $renglones->sum('cantdesp');
            $ahora = Carbon::now();

            if ($unidades <= 0) {
                Pedido::query()->whereKey($pedidoId)->update(['estado' => 'ANULADO']);
                SidesPedrenOperacion::query()->where('id_pedido', $pedidoId)->update(['packing' => 0]);

                return 'ANULADO';
            }

            // Se calcula antes de insertar el log de este pedido.
            $inicioSesion = $operacion->fecpacking2 ? Carbon::parse($operacion->fecpacking2) : $ahora;
            $anterior = $this->ultimoLogDelDia($usuario, $inicioSesion);

            Pedido::query()->whereKey($pedidoId)->update(['estado' => 'PEND-FACTURA', 'feccompletado' => $ahora]);

            $operacion->update([
                'despachador' => trim((string) $datos['despachador']) ?: $operacion->despachador,
                'embalador' => trim((string) $datos['embalador']) ?: $usuario->name,
                'cantBultos' => trim($datos['cantBultos']),
                'num_cesta_ped' => trim((string) $datos['cestas']) ?: null,
            ]);

            SidesPedrenOperacion::query()->where('id_pedido', $pedidoId)->update(['packing' => 1]);
            $this->despachoSeped->escribir($pedidoId);

            SidesLogpacking::query()->create([
                'id_pedido' => $pedidoId,
                'usuario' => $usuario->email,
                'numren' => $renglones->count(),
                'numund' => $unidades,
                'tiempo_packing' => $this->segundos($inicioSesion, $ahora),
                'fecha_del_packing' => $ahora,
            ]);

            if ($anterior) {
                SidesLogInacPacking::query()->create([
                    'id_pedido' => $pedidoId,
                    'id_pedido_anterior' => $anterior->id_pedido,
                    'usuario' => $usuario->email,
                    'numren' => $renglones->count(),
                    'numund' => $unidades,
                    'tiempo_packing_inac' => $this->segundos(Carbon::parse($anterior->fecha_del_packing), $inicioSesion),
                    'fecha_del_packing' => $inicioSesion,
                ]);
            }

            return 'PEND-FACTURA';
        });
    }

    public function liberar(SidesUsers $usuario, int $pedidoId): void
    {
        DB::transaction(function () use ($usuario, $pedidoId) {
            $operacion = $this->operacionPropia($usuario, $this->pedidoEnPacking($usuario, $pedidoId));
            $operacion->update(['embalador' => '']);
        });
    }

    private function pedidoDeSucursal(SidesUsers $usuario, int $pedidoId): Pedido
    {
        $pedido = Pedido::query()->where('codisb', $usuario->codisb)->whereKey($pedidoId)->lockForUpdate()->first();

        if (! $pedido) {
            throw new PackingException("No se encontró el pedido #{$pedidoId} en tu sucursal.");
        }

        return $pedido;
    }

    private function pedidoEnPacking(SidesUsers $usuario, int $pedidoId): Pedido
    {
        $pedido = $this->pedidoDeSucursal($usuario, $pedidoId);
        $this->exigirPacking($pedido);

        return $pedido;
    }

    private function exigirPacking(Pedido $pedido): void
    {
        if ($pedido->estado !== 'PACKING') {
            throw new PackingException("El pedido #{$pedido->id} no está en packing (estado: {$pedido->estado}).");
        }
    }

    private function operacionPropia(SidesUsers $usuario, Pedido $pedido): SidesPedidoOperacion
    {
        $operacion = SidesPedidoOperacion::query()->whereKey($pedido->id)->lockForUpdate()->first();

        if (! $operacion || $operacion->embalador !== $usuario->name) {
            $quien = $operacion?->embalador ? " Lo está empacando {$operacion->embalador}." : '';
            throw new PackingException("No tienes abierto el pedido #{$pedido->id}.{$quien}");
        }

        return $operacion;
    }

    private function renglonBloqueado(int $pedidoId, int $item): SidesPedrenOperacion
    {
        $renglon = SidesPedrenOperacion::query()->where('id_pedido', $pedidoId)->where('item', $item)->lockForUpdate()->first();

        if (! $renglon) {
            throw new PackingException('Ese producto no pertenece al pedido.');
        }

        return $renglon;
    }

    private function actualizarRenglon(int $pedidoId, int $item, array $valores): void
    {
        SidesPedrenOperacion::query()->where('id_pedido', $pedidoId)->where('item', $item)->update($valores);
    }

    private function verificarClave(?SidesCfg $cfg, ?string $clave): void
    {
        if (! $cfg || ! hash_equals((string) $cfg->claveValPicking, (string) $clave)) {
            throw new PackingException('La clave de autorización no es correcta.');
        }
    }

    /** Último packing registrado por el usuario el mismo día, antes de esta sesión (para la inactividad). */
    private function ultimoLogDelDia(SidesUsers $usuario, CarbonInterface $inicioSesion): ?SidesLogpacking
    {
        return SidesLogpacking::query()
            ->where('usuario', $usuario->email)
            ->whereBetween('fecha_del_packing', [
                $inicioSesion->copy()->startOfDay()->toDateTimeString(),
                $inicioSesion->toDateTimeString(),
            ])
            ->orderByDesc('fecha_del_packing')
            ->first();
    }

    private function segundos(CarbonInterface $desde, CarbonInterface $hasta): int
    {
        return abs($hasta->getTimestamp() - $desde->getTimestamp());
    }
}
