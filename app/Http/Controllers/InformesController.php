<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesUsers;
use App\Services\Informes\InformesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Informes de productividad e inactividad de picking y packing. Ver InformesService. */
class InformesController extends Controller
{
    public function __construct(private readonly InformesService $informes)
    {
    }

    public function index(): View
    {
        return view('informes.index');
    }

    public function reporte(Request $request, string $tipo, string $vista): View
    {
        [$desde, $hasta] = $this->rango($request);
        $ranking = $this->informes->ranking($request->user()->codisb, $tipo, $vista, $desde, $hasta);

        return view('informes.reporte', [
            'tipo' => $tipo,
            'vista' => $vista,
            'desde' => $desde,
            'hasta' => $hasta,
            'ranking' => $ranking,
        ]);
    }

    public function operario(Request $request, string $tipo, string $vista, int $usuario): View
    {
        [$desde, $hasta] = $this->rango($request);
        $operario = $this->operarioDeSucursal($request, $usuario);

        return view('informes.operario', [
            'tipo' => $tipo,
            'vista' => $vista,
            'desde' => $desde,
            'hasta' => $hasta,
            'operario' => $operario,
            'registros' => $this->informes->detalle($request->user()->codisb, $tipo, $vista, $operario->email, $desde, $hasta),
        ]);
    }

    public function excel(Request $request, string $tipo, string $vista): BinaryFileResponse
    {
        [$desde, $hasta] = $this->rango($request);
        $archivo = $this->informes->excelResumen($request->user()->codisb, $tipo, $vista, $desde, $hasta);

        return response()->download($archivo, $this->nombreArchivo($tipo, $vista, $desde, $hasta))->deleteFileAfterSend();
    }

    public function excelOperario(Request $request, string $tipo, string $vista, int $usuario): BinaryFileResponse
    {
        [$desde, $hasta] = $this->rango($request);
        $operario = $this->operarioDeSucursal($request, $usuario);
        $archivo = $this->informes->excelDetalle($request->user()->codisb, $tipo, $vista, $operario->email, $desde, $hasta);

        return response()
            ->download($archivo, $this->nombreArchivo($tipo, $vista, $desde, $hasta, Str::slug($operario->name, '_')))
            ->deleteFileAfterSend();
    }

    public function quitarInactividad(Request $request, string $tipo, int $registro): RedirectResponse
    {
        $quitado = $this->informes->quitarInactividad($request->user()->codisb, $tipo, $registro);

        return back()->with(
            $quitado ? 'mensaje' : 'error',
            $quitado ? 'Registro de inactividad quitado del informe.' : 'Ese registro ya no existe.'
        );
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rango(Request $request): array
    {
        $datos = $request->validate([
            'desde' => ['nullable', 'date_format:Y-m-d', 'required_with:hasta'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'required_with:desde', 'after_or_equal:desde'],
        ], [
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
            '*.date_format' => 'Fecha no válida.',
            '*.required_with' => 'Elige las dos fechas.',
        ]);

        [$desde, $hasta] = InformesService::rango($datos['desde'] ?? null, $datos['hasta'] ?? null);

        if ($desde->diffInDays($hasta) > InformesService::DIAS_MAXIMOS) {
            throw ValidationException::withMessages(['desde' => 'El rango no puede pasar de un año.']);
        }

        return [$desde, $hasta];
    }

    /** Solo operarios de la misma sucursal: la ficha no expone usuarios de otras droguerías. */
    private function operarioDeSucursal(Request $request, int $usuario): SidesUsers
    {
        return SidesUsers::query()->deLaDrogueria()
            ->whereKey($usuario)
            ->where('codisb', $request->user()->codisb)
            ->firstOrFail();
    }

    private function nombreArchivo(string $tipo, string $vista, Carbon $desde, Carbon $hasta, ?string $operario = null): string
    {
        return collect(['informe', $vista, $tipo, $operario, $desde->format('Y-m-d'), $hasta->format('Y-m-d')])
            ->filter()
            ->implode('_').'.xlsx';
    }
}
