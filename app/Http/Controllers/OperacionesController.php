<?php

namespace App\Http\Controllers;

use App\Services\Informes\FallasService;
use App\Services\Informes\InformesService;
use App\Services\Informes\OperacionesService;
use App\Services\Inicio\DesempenoAlmacen;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Reportes de operaciones (OperacionesService) para el jefe de operaciones o del almacén:
 * los ve quien tiene Informes o «Indicadores del almacén».
 */
class OperacionesController extends Controller
{
    public function __construct(private readonly OperacionesService $operaciones)
    {
    }

    public function index(): View
    {
        return view('operaciones.index');
    }

    public function tiempos(Request $request): View
    {
        [$desde, $hasta] = $this->rango($request);
        $conPacking = $this->conPacking($request);

        return view('operaciones.tiempos', [
            'desde' => $desde,
            'hasta' => $hasta,
            'tramos' => OperacionesService::tramos($conPacking),
            'datos' => $this->operaciones->tiempos($request->user()->codisb, $desde, $hasta, $conPacking),
        ]);
    }

    public function excelTiempos(Request $request): BinaryFileResponse
    {
        [$desde, $hasta] = $this->rango($request);
        $archivo = $this->operaciones->excelTiempos($request->user()->codisb, $desde, $hasta, $this->conPacking($request));

        return $this->descargar($archivo, 'tiempos', $desde, $hasta);
    }

    public function clientes(Request $request): View
    {
        [$desde, $hasta] = $this->rango($request);
        $agrupar = $this->agrupar($request);
        $filas = $this->operaciones->clientes($request->user()->codisb, $desde, $hasta, $agrupar);

        return view('operaciones.clientes', [
            'desde' => $desde,
            'hasta' => $hasta,
            'agrupar' => $agrupar,
            'totales' => [
                'grupos' => $filas->count(),
                'pedidos' => $filas->sum('pedidos'),
                'pedidas' => $filas->sum('pedidas'),
                'despachadas' => $filas->sum('despachadas'),
            ],
            'filas' => $this->operaciones->paginar($filas),
        ]);
    }

    public function excelClientes(Request $request): BinaryFileResponse
    {
        [$desde, $hasta] = $this->rango($request);
        $agrupar = $this->agrupar($request);
        $archivo = $this->operaciones->excelClientes($request->user()->codisb, $desde, $hasta, $agrupar);

        return $this->descargar($archivo, $agrupar === 'ruta' ? 'rutas' : 'clientes', $desde, $hasta);
    }

    public function demorados(Request $request): View
    {
        [$desde, $hasta] = $this->rango($request, 7);
        [$etapa, $minutos] = $this->filtroDemora($request);
        $conPacking = $this->conPacking($request);
        $filas = $this->operaciones->demorados($request->user()->codisb, $desde, $hasta, $etapa, $minutos, $conPacking);

        return view('operaciones.demorados', [
            'desde' => $desde,
            'hasta' => $hasta,
            'etapa' => $etapa,
            'minutos' => $minutos,
            'conPacking' => $conPacking,
            'total' => $filas->count(),
            'enCurso' => $filas->where('enCurso', true)->count(),
            'porEtapa' => $filas->countBy('etapa'),
            'filas' => $this->operaciones->paginar($filas),
        ]);
    }

    public function excelDemorados(Request $request): BinaryFileResponse
    {
        [$desde, $hasta] = $this->rango($request, 7);
        [$etapa, $minutos] = $this->filtroDemora($request);
        $archivo = $this->operaciones->excelDemorados($request->user()->codisb, $desde, $hasta, $etapa, $minutos, $this->conPacking($request));

        return $this->descargar($archivo, 'demorados', $desde, $hasta);
    }

    /** Una página para imprimir o guardar en PDF: el mes contra el mes anterior. */
    public function mensual(Request $request, DesempenoAlmacen $desempeno, FallasService $fallas): View
    {
        $mes = $this->mes($request);
        $siguiente = $mes->copy()->addMonth();
        $anterior = $mes->copy()->subMonth();
        $codisb = $request->user()->codisb;
        $conPacking = $this->conPacking($request);
        // Hasta hoy si es el mes en curso, así el mes anterior se compara con los mismos días.
        $fin = $siguiente->isFuture() ? Carbon::tomorrow() : $siguiente;
        $finAnterior = $siguiente->isFuture()
            ? $anterior->copy()->addDays((int) $mes->diffInDays($fin))->min($mes)
            : $mes->copy();

        return view('operaciones.mensual', [
            'mes' => $mes,
            'enCurso' => $siguiente->isFuture(),
            'anterior' => $anterior,
            'tramos' => OperacionesService::tramos($conPacking),
            'actual' => $desempeno->resumen($codisb, $mes, $fin, $conPacking),
            'previo' => $desempeno->resumen($codisb, $anterior, $finAnterior, $conPacking),
            'fallas' => $fallas->totales($codisb, $mes, $fin->copy()->subDay()),
            'fallasPrevio' => $fallas->totales($codisb, $anterior, $finAnterior->copy()->subDay()),
            'demorados' => $this->operaciones->totalDemorados($codisb, $mes, $fin->copy()->subDay(), $conPacking),
            'clientes' => $this->operaciones->mejoresClientes($codisb, $mes, $fin->copy()->subDay()),
            'conPacking' => $conPacking,
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rango(Request $request, int $diasPorDefecto = InformesService::DIAS_POR_DEFECTO): array
    {
        $datos = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d', 'required_with:hasta'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'required_with:desde', 'after_or_equal:desde'],
        ], [
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
            '*.date_format' => 'Fecha no válida.',
            '*.required_with' => 'Elige las dos fechas.',
        ]);

        if (empty($datos['desde'])) {
            return [Carbon::today()->subDays($diasPorDefecto - 1), Carbon::today()];
        }

        $desde = Carbon::createFromFormat('Y-m-d', $datos['desde'])->startOfDay();
        $hasta = Carbon::createFromFormat('Y-m-d', $datos['hasta'])->startOfDay();
        if ($desde->diffInDays($hasta) > InformesService::DIAS_MAXIMOS) {
            throw ValidationException::withMessages(['desde' => 'El rango no puede pasar de un año.']);
        }

        return [$desde, $hasta];
    }

    private function mes(Request $request): Carbon
    {
        $mes = (string) $request->query('mes', '');

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)
            ? Carbon::createFromFormat('Y-m-d', $mes.'-01')->startOfDay()
            : Carbon::today()->startOfMonth();
    }

    private function agrupar(Request $request): string
    {
        return $request->query('agrupar') === 'ruta' ? 'ruta' : 'cliente';
    }

    /** @return array{0: ?string, 1: int} */
    private function filtroDemora(Request $request): array
    {
        $etapa = (string) $request->query('etapa', '');
        $minutos = (int) $request->query('minutos', OperacionesService::MINUTOS_DEMORA);

        return [
            array_key_exists($etapa, OperacionesService::ETAPAS) ? $etapa : null,
            max(1, min($minutos, 24 * 60)),
        ];
    }

    private function conPacking(Request $request): bool
    {
        return (bool) ($request->user()->cfg?->activarPacking ?? true);
    }

    private function descargar(string $archivo, string $nombre, Carbon $desde, Carbon $hasta): BinaryFileResponse
    {
        return response()
            ->download($archivo, "reporte_{$nombre}_{$desde->format('Y-m-d')}_{$hasta->format('Y-m-d')}.xlsx")
            ->deleteFileAfterSend();
    }
}
