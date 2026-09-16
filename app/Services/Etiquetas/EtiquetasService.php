<?php

namespace App\Services\Etiquetas;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesEtiquetaPedido;
use App\Models\Sides\SidesPedidoOperacion;
use App\Models\Sides\SidesUsers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Etiquetas de bultos y ticket de despacho. Porta AdminetiquetasController, idéntico en los tres
 * SIDES legacy, con estos cambios:
 *
 * - Además del número de pedido y el recipiente, se busca por el código impreso en la etiqueta
 *   ("{pedido}-{bulto}"), así se reimprime escaneando un bulto.
 * - Por recipiente se toma el pedido imprimible más reciente (el legacy tomaba el más antiguo,
 *   pero los recipientes se reutilizan).
 * - Los bultos van en sides_pedido_operacion.cantBultos, igual que al terminar Packing.
 * - Reimprimir un pedido ya ENTREGADO no toca sides_etiqueta_pedido (el legacy duplicaba filas).
 */
class EtiquetasService
{
    public const ESTADOS_IMPRIMIBLES = ['PEND-FACTURA', 'FACTURANDO', 'FACTURADO', 'PROCESADO'];

    public const MAX_BULTOS = 999;

    /** Código de barras de cada bulto; lo leen las guías de carga y descarga. */
    public static function codigo(int $pedidoId, int $bulto): string
    {
        return $pedidoId.'-'.str_pad((string) $bulto, 2, '0', STR_PAD_LEFT);
    }

    /** Busca por número de pedido, código de etiqueta (12345-01) o recipiente. */
    public function buscar(string $codisb, string $filtro): Pedido
    {
        $filtro = trim($filtro);
        if ($filtro === '') {
            throw new EtiquetasException('Escribe o escanea el número de pedido, la etiqueta o el recipiente.');
        }

        if (preg_match('/^(\d+)(?:-\d{1,3})?$/', $filtro, $partes)) {
            $pedido = $this->consulta($codisb)->where('pedido.id', (int) $partes[1])->first();
            if ($pedido) {
                return $this->exigirImprimible($pedido);
            }
        }

        $delRecipiente = $this->consulta($codisb)->where('op.recipiente', $filtro)->orderByDesc('pedido.fecprocesado')->get();
        if ($delRecipiente->isEmpty()) {
            throw new EtiquetasException("No se encontró ningún pedido con el número o recipiente {$filtro}.");
        }

        return $delRecipiente->first(fn (Pedido $pedido) => $this->esImprimible($pedido))
            ?? $this->exigirImprimible($delRecipiente->first());
    }

    public function pedido(string $codisb, int $pedidoId): Pedido
    {
        $pedido = $this->consulta($codisb)->where('pedido.id', $pedidoId)->first();
        if (! $pedido) {
            throw new EtiquetasException("No se encontró el pedido #{$pedidoId} en tu sucursal.");
        }

        return $this->exigirImprimible($pedido);
    }

    /** Guarda los bultos y deja registrada una etiqueta por bulto para el control de guías. */
    public function generar(SidesUsers $usuario, int $pedidoId, int $bultos): Pedido
    {
        if ($bultos < 1 || $bultos > self::MAX_BULTOS) {
            throw new EtiquetasException('Los bultos deben estar entre 1 y '.self::MAX_BULTOS.'.');
        }

        return DB::transaction(function () use ($usuario, $pedidoId, $bultos) {
            $pedido = $this->pedido($usuario->codisb, $pedidoId);

            SidesPedidoOperacion::query()->updateOrCreate(
                ['id_pedido' => $pedido->id],
                ['codisb' => $usuario->codisb, 'cantBultos' => (string) $bultos]
            );
            $this->registrarEtiquetas($pedido, $bultos);

            $pedido->cantBultos = (string) $bultos;

            return $pedido;
        });
    }

    /** @return Collection<int, object{codprod: string, desprod: string, cantdesp: int}> */
    public function renglonesDespachados(int $pedidoId): Collection
    {
        return DB::table('pedren')->where('id', $pedidoId)->orderBy('item')->get(['codprod', 'desprod', 'cantdesp']);
    }

    private function registrarEtiquetas(Pedido $pedido, int $bultos): void
    {
        $actuales = SidesEtiquetaPedido::query()->where('numepedi', (string) $pedido->id)->lockForUpdate()->get();

        // Ya cargado en el camión o entregado: se puede reimprimir, pero los bultos quedan como están.
        if ($actuales->contains(fn (SidesEtiquetaPedido $etiqueta) => in_array($etiqueta->estado, ['CARGADO', 'ENTREGADO'], true))) {
            return;
        }

        // Si ya estaba cargado en una guía, las etiquetas nuevas siguen en esa guía.
        $enGuia = $actuales->firstWhere('estado', 'EN GUIA');

        SidesEtiquetaPedido::query()->where('numepedi', (string) $pedido->id)->whereIn('estado', ['NUEVO', 'EN GUIA'])->delete();
        SidesEtiquetaPedido::query()->insert(array_map(fn (int $bulto) => [
            'numepedi' => (string) $pedido->id,
            'etiqueta' => self::codigo($pedido->id, $bulto),
            'codcli' => (string) $pedido->codcli,
            'nomcli' => (string) $pedido->nomcli,
            'ruta' => (string) $pedido->ruta,
            'estado' => $enGuia ? 'EN GUIA' : 'NUEVO',
            'guia' => $enGuia?->guia,
        ], range(1, $bultos)));
    }

    private function consulta(string $codisb)
    {
        return Pedido::query()
            ->leftJoin('sides_pedido_operacion as op', 'op.id_pedido', '=', 'pedido.id')
            ->where('pedido.codisb', $codisb)
            ->select([
                'pedido.id', 'pedido.codcli', 'pedido.nomcli', 'pedido.ruta', 'pedido.estado', 'pedido.fecha',
                'pedido.fecprocesado', 'pedido.entrega', 'op.recipiente', 'op.cantBultos', 'op.despachador', 'op.embalador',
            ]);
    }

    private function esImprimible(Pedido $pedido): bool
    {
        return in_array($pedido->estado, self::ESTADOS_IMPRIMIBLES, true);
    }

    private function exigirImprimible(Pedido $pedido): Pedido
    {
        if (! $this->esImprimible($pedido)) {
            throw new EtiquetasException("El pedido #{$pedido->id} está en {$pedido->estado}: solo se imprimen etiquetas de pedidos con packing terminado.");
        }

        return $pedido;
    }
}
