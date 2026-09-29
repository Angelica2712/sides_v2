<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesAuditoria;
use App\Models\Sides\SidesCfg;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Auditoría de SIDES v2 (ver App\Services\Auditoria\Auditoria). Solo la ve el administrador de
 * SIDES, el usuario de la empresa, y abarca todas las droguerías.
 */
class AuditoriaController extends Controller
{
    /** Días que muestra si no se eligen fechas. */
    public const DIAS_POR_DEFECTO = 7;

    public const RESULTADOS = ['OK', 'FALLIDO', 'DENEGADO'];

    public function __invoke(Request $request): View
    {
        $filtros = $request->validate([
            'codisb' => ['nullable', 'string', 'max:20'],
            'usuario' => ['nullable', 'string', 'max:150'],
            'modulo' => ['nullable', 'string', 'max:40'],
            'resultado' => ['nullable', 'in:'.implode(',', self::RESULTADOS)],
            'buscar' => ['nullable', 'string', 'max:100'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ], [
            'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
            '*.date_format' => 'Fecha no válida.',
        ]);

        $desde = isset($filtros['desde']) ? Carbon::parse($filtros['desde']) : Carbon::today()->subDays(self::DIAS_POR_DEFECTO);
        $hasta = isset($filtros['hasta']) ? Carbon::parse($filtros['hasta']) : Carbon::today();

        $registros = SidesAuditoria::query()
            ->whereBetween('fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->when($filtros['codisb'] ?? null, fn ($q, $codisb) => $q->where('codisb', $codisb))
            ->when($filtros['modulo'] ?? null, fn ($q, $modulo) => $q->where('modulo', $modulo))
            ->when($filtros['resultado'] ?? null, fn ($q, $resultado) => $q->where('resultado', $resultado))
            ->when($filtros['usuario'] ?? null, fn ($q, $usuario) => $q->where(
                fn ($q) => $q->where('usuario', 'like', "%{$usuario}%")->orWhere('nombre', 'like', "%{$usuario}%")
            ))
            ->when($filtros['buscar'] ?? null, fn ($q, $texto) => $q->where(
                fn ($q) => $q->where('descripcion', 'like', "%{$texto}%")->orWhere('referencia', $texto)
            ))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.auditoria', [
            'registros' => $registros,
            'filtros' => $filtros,
            'desde' => $desde,
            'hasta' => $hasta,
            'droguerias' => SidesCfg::query()->orderBy('nombre')->pluck('nombre', 'codisb'),
            'modulos' => SidesAuditoria::query()->distinct()->orderBy('modulo')->pluck('modulo'),
        ]);
    }
}
