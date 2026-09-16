<?php

namespace App\Services\Guias;

use App\Models\Sides\SidesGuia;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Guía de despacho impresa (legacy PDF con mPDF) y guía de ruta en Excel (legacy
 * GuiaDeRutaExporter). Las dos usan los mismos datos por cliente.
 */
class DocumentoGuia
{
    public const COLUMNAS_EXCEL = ['CODIGO', 'DIRECCION', 'NOMBRE', 'SEC', 'BULTOS', 'NEV', 'FACT', 'NOTAS', 'DEV'];

    public function __construct(private readonly GuiasService $guias)
    {
    }

    /**
     * Un registro por cliente, en orden de visita.
     *
     * @return Collection<int, object{codcli: string, nombre: string, rif: string, direccion: string, bultos: int, refrigerados: int, facturados: int, notas: int, devoluciones: int, pedidos: Collection}>
     */
    public function clientes(SidesGuia $guia): Collection
    {
        $clientes = $this->guias->clientes($guia);
        $seped = $this->guias->datosClientes($guia->codisb, $clientes->pluck('codcli')->all());
        $pedidos = $clientes->flatMap(fn ($c) => $c->pedidos);
        $refrigerados = DB::table('pedren')->whereIn('id', $pedidos->pluck('id')->all())->where('refrigerado', '>', 0)
            ->selectRaw('id, COUNT(*) as total')->groupBy('id')->pluck('total', 'id');
        $facturas = $pedidos->flatMap(fn ($p) => $p->facturas)->pluck('factnum')->unique()->values()->all();
        ['notas' => $notas, 'devoluciones' => $devoluciones] = $this->guias->notasYDevoluciones($guia->codisb, $facturas);

        return $clientes->map(function ($cliente) use ($seped, $refrigerados, $notas, $devoluciones) {
            $datos = $seped->get($cliente->codcli);

            return (object) [
                'codcli' => $cliente->codcli,
                'nombre' => $datos->nombre ?? $cliente->nomcli,
                'rif' => $datos->rif ?? '',
                'direccion' => trim((string) ($datos->entrega ?? '')) ?: trim((string) ($datos->direccion ?? '')),
                'bultos' => $cliente->totalBultos,
                'refrigerados' => $cliente->pedidos->sum(fn ($p) => (int) ($refrigerados[$p->id] ?? 0)),
                'facturados' => $cliente->pedidos->filter(fn ($p) => $p->facturas->isNotEmpty())->count(),
                'notas' => $notas->get($cliente->codcli, 0),
                'devoluciones' => $devoluciones->get($cliente->codcli, 0),
                'pedidos' => $cliente->pedidos,
            ];
        });
    }

    /** Genera el Excel en un archivo temporal y devuelve su ruta. */
    public function excel(SidesGuia $guia): string
    {
        $clientes = $this->clientes($guia);
        $base = tempnam(sys_get_temp_dir(), 'guia');
        @unlink($base);
        $archivo = $base.'.xlsx';

        $negrita = new Style(fontBold: true);
        $writer = new Writer();
        $writer->openToFile($archivo);
        $writer->addRows([
            Row::fromValuesWithStyle(['GUIA DE RUTA', 'GUIA: '.$guia->id, 'FECHA: '.now()->format('d-m-Y H:i')], $negrita),
            Row::fromValues(['RUTA: '.$guia->ruta, 'UNIDAD: '.($guia->unidad ?? ''), 'H. SALIDA: '.($guia->fecha_salida ?? '')]),
            Row::fromValues(['CHOFER: '.$guia->nomchofer, 'AUXILIAR: '.($guia->chof_aux_nom ?? '')]),
            Row::fromValuesWithStyle(self::COLUMNAS_EXCEL, $negrita),
        ]);
        foreach ($clientes as $posicion => $cliente) {
            $writer->addRow(Row::fromValues([
                $cliente->codcli, $cliente->direccion, $cliente->nombre, $posicion + 1, $cliente->bultos,
                $cliente->refrigerados, $cliente->facturados, $cliente->notas, $cliente->devoluciones,
            ]));
        }
        $writer->addRow(Row::fromValuesWithStyle(['', 'TOTAL CLIENTES: '.$clientes->count(), 'PIEZAS: '.$clientes->sum('bultos')], $negrita));
        $writer->close();

        return $archivo;
    }
}
