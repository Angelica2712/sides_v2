<?php

namespace App\Services\Informes;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Informe de fallas: los productos que la farmacia pidió y el almacén despachó de menos.
 * Porta AdminprodfallaController ("Producto en fallas") de dromarko: renglones con
 * 0 <= despachado < solicitado, por fecha de procesado del pedido.
 *
 * Diferencias intencionales con el legacy:
 * - Solo cuentan pedidos con el despacho cerrado (ESTADOS). El legacy miraba cualquier estado, y
 *   un pedido a medio picking salía con todo lo que aún no se había recogido como falla.
 * - Además de la lista renglón por renglón hay un resumen por producto (cuánto faltó en total y
 *   en cuántos pedidos), que es lo que sirve para reponer.
 * - Excel con las dos hojas en vez de CSV.
 */
class FallasService
{
    /** Estados en los que lo despachado ya no cambia. */
    public const ESTADOS = ['PEND-FACTURA', 'FACTURANDO', 'FACTURADO'];

    public const VISTAS = ['producto' => 'Por producto', 'pedido' => 'Por pedido'];

    public const POR_PAGINA = 50;

    /** Lo despachado vive en sides_pedren_operacion mientras SIDES trabaja el pedido (-1 = sin revisar). */
    private const DESPACHADO = 'CASE WHEN op.cantdesp >= 0 THEN op.cantdesp ELSE pr.cantdesp END';

    /** @return object{productos: int, pedidos: int, renglones: int, solicitado: int, despachado: int, faltante: int} */
    public function totales(string $codisb, Carbon $desde, Carbon $hasta, string $buscar = ''): object
    {
        $d = self::DESPACHADO;

        return $this->base($codisb, $desde, $hasta, $buscar)
            ->selectRaw("COUNT(DISTINCT pr.codprod) as productos,
                COUNT(DISTINCT p.id) as pedidos,
                COUNT(*) as renglones,
                COALESCE(SUM(pr.cantidad), 0) as solicitado,
                COALESCE(SUM({$d}), 0) as despachado,
                COALESCE(SUM(pr.cantidad - ({$d})), 0) as faltante")
            ->first();
    }

    /** Un renglón por producto, del que más unidades faltaron al que menos. */
    public function porProducto(string $codisb, Carbon $desde, Carbon $hasta, string $buscar = ''): LengthAwarePaginator
    {
        return $this->consultaProducto($codisb, $desde, $hasta, $buscar)->paginate(self::POR_PAGINA)->withQueryString();
    }

    /** Un renglón por producto de cada pedido, del pedido más nuevo al más antiguo. */
    public function porPedido(string $codisb, Carbon $desde, Carbon $hasta, string $buscar = ''): LengthAwarePaginator
    {
        return $this->consultaPedido($codisb, $desde, $hasta, $buscar)->paginate(self::POR_PAGINA)->withQueryString();
    }

    /** Excel con la hoja "Por producto" y la hoja "Por pedido". Devuelve la ruta del archivo temporal. */
    public function excel(string $codisb, Carbon $desde, Carbon $hasta, string $buscar = ''): string
    {
        $negrita = new Style(fontBold: true);
        $base = tempnam(sys_get_temp_dir(), 'fal');
        @unlink($base);
        $archivo = $base.'.xlsx';
        $writer = new Writer();
        $writer->openToFile($archivo);

        $writer->getCurrentSheet()->setName('Por producto');
        $writer->addRow(Row::fromValuesWithStyle(['CODIGO', 'PRODUCTO', 'BARRA', 'MARCA', 'PEDIDOS', 'SOLICITADO', 'DESPACHADO', 'FALTANTE'], $negrita));
        foreach ($this->consultaProducto($codisb, $desde, $hasta, $buscar)->cursor() as $fila) {
            $writer->addRow(Row::fromValues([
                (string) $fila->codprod, (string) $fila->desprod, (string) $fila->barra, (string) $fila->marcamodelo,
                (int) $fila->pedidos, (int) $fila->solicitado, (int) $fila->despachado, (int) $fila->faltante,
            ]));
        }

        $writer->addNewSheetAndMakeItCurrent()->setName('Por pedido');
        $writer->addRow(Row::fromValuesWithStyle(['PEDIDO', 'PROCESADO', 'ESTADO', 'CODIGO CLIENTE', 'CLIENTE', 'RUTA', 'DESPACHADOR', 'CODIGO', 'PRODUCTO', 'BARRA', 'MARCA', 'SOLICITADO', 'DESPACHADO', 'FALTANTE'], $negrita));
        foreach ($this->consultaPedido($codisb, $desde, $hasta, $buscar)->cursor() as $fila) {
            $writer->addRow(Row::fromValues([
                (int) $fila->id, $fila->fecprocesado ? Carbon::parse($fila->fecprocesado)->format('d-m-Y H:i') : '', (string) $fila->estado,
                (string) $fila->codcli, (string) $fila->nomcli, (string) $fila->ruta, (string) $fila->despachador,
                (string) $fila->codprod, (string) $fila->desprod, (string) $fila->barra, (string) $fila->marcamodelo,
                (int) $fila->solicitado, (int) $fila->despachado, (int) $fila->faltante,
            ]));
        }
        $writer->close();

        return $archivo;
    }

    private function consultaProducto(string $codisb, Carbon $desde, Carbon $hasta, string $buscar): Builder
    {
        $d = self::DESPACHADO;

        return $this->base($codisb, $desde, $hasta, $buscar)
            ->groupBy('pr.codprod')
            ->selectRaw("pr.codprod,
                MAX(pr.desprod) as desprod,
                MAX(pr.barra) as barra,
                MAX(pr.marcamodelo) as marcamodelo,
                COUNT(DISTINCT p.id) as pedidos,
                SUM(pr.cantidad) as solicitado,
                SUM({$d}) as despachado,
                SUM(pr.cantidad - ({$d})) as faltante")
            ->orderByDesc('faltante')
            ->orderBy('pr.codprod');
    }

    private function consultaPedido(string $codisb, Carbon $desde, Carbon $hasta, string $buscar): Builder
    {
        $d = self::DESPACHADO;

        return $this->base($codisb, $desde, $hasta, $buscar)
            ->leftJoin('sides_pedido_operacion as po', 'po.id_pedido', '=', 'p.id')
            ->select([
                'p.id', 'p.codcli', 'p.nomcli', 'p.ruta', 'p.estado', 'p.fecprocesado',
                'pr.item', 'pr.codprod', 'pr.desprod', 'pr.barra', 'pr.marcamodelo', 'pr.cantidad as solicitado',
                DB::raw("COALESCE(NULLIF(op.despachador, ''), NULLIF(po.despachador, ''), '') as despachador"),
                DB::raw("{$d} as despachado"),
                DB::raw("pr.cantidad - ({$d}) as faltante"),
            ])
            ->orderByDesc('p.id')
            ->orderBy('pr.item');
    }

    /** Renglones con falla de la sucursal en el rango (fechas inclusivas, día completo). */
    private function base(string $codisb, Carbon $desde, Carbon $hasta, string $buscar): Builder
    {
        $d = self::DESPACHADO;
        $buscar = trim($buscar);

        return DB::table('pedido as p')
            ->join('pedren as pr', 'pr.id', '=', 'p.id')
            ->leftJoin('sides_pedren_operacion as op', function ($join) {
                $join->on('op.id_pedido', '=', 'pr.id')->on('op.item', '=', 'pr.item');
            })
            ->where('p.codisb', $codisb)
            ->whereIn('p.estado', self::ESTADOS)
            ->whereBetween('p.fecprocesado', [
                $desde->copy()->startOfDay()->toDateTimeString(),
                $hasta->copy()->endOfDay()->toDateTimeString(),
            ])
            ->whereRaw("({$d}) >= 0")
            ->whereRaw("({$d}) < pr.cantidad")
            ->when($buscar !== '', function (Builder $q) use ($buscar) {
                $texto = '%'.addcslashes($buscar, '%_\\').'%';
                $q->where(function (Builder $q) use ($texto, $buscar) {
                    $q->where('pr.desprod', 'like', $texto)
                        ->orWhere('pr.codprod', 'like', $texto)
                        ->orWhere('pr.barra', 'like', $texto)
                        ->orWhere('pr.marcamodelo', 'like', $texto)
                        ->orWhere('p.nomcli', 'like', $texto)
                        ->orWhere('p.codcli', 'like', $texto);
                    if (ctype_digit($buscar)) {
                        $q->orWhere('p.id', (int) $buscar);
                    }
                });
            });
    }
}
