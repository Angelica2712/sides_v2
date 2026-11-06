<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesMonitorTv;
use App\Services\MonitorTv\MonitorTvService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Televisores de la droguería (legacy de mastranto: AdminmonitortvController). Quien administra la
 * droguería (permiso de Configuración) escribe el código que muestra la TV, le pone un nombre,
 * elige el usuario y las vistas; también los cambia, desvincula la TV y ve los accesos rechazados.
 * La API que usa la TV está en MonitorTvApiController.
 */
class TelevisoresController extends Controller
{
    /** Sin consultar la API en este tiempo, la TV se muestra como desconectada. */
    private const MINUTOS_SIN_CONEXION = 3;

    private const DIAS_PURGA = [1, 7, 15, 30];

    public function __construct(private readonly MonitorTvService $servicio) {}

    public function index(Request $request): View
    {
        $codisb = (string) $request->user()->codisb;

        return view('televisores.index', [
            'tvs' => SidesMonitorTv::query()
                ->with('usuario:id,name')
                ->where('codisb', $codisb)
                ->where('estado', 'ACTIVO')
                ->orderBy('alias')
                ->get(),
            'limiteConexion' => Carbon::now()->subMinutes(self::MINUTOS_SIN_CONEXION),
            'fallos' => DB::table('sides_monitor_tv_log as l')
                ->join('sides_monitor_tv as t', 't.id', '=', 'l.monitor_tv_id')
                ->where('t.codisb', $codisb)
                ->orderByDesc('l.id')
                ->limit(20)
                ->get(['l.status_code', 'l.ip', 'l.created_at', 't.alias']),
            'diasPurga' => self::DIAS_PURGA,
        ]);
    }

    /** Paso 2: con el código que se ve en la TV, sus datos para confirmar y el formulario. */
    public function create(Request $request): View|RedirectResponse
    {
        $codigo = trim((string) $request->query('codigo'));
        $tv = $this->servicio->buscarPendiente($codigo);

        if (! $tv) {
            return redirect()->route('televisores.index')
                ->withInput(['codigo' => $codigo])
                ->with('error', MonitorTvService::ERRORES['CODIGO_INVALIDO']);
        }

        return view('televisores.form', [
            'tv' => $tv,
            'codigo' => $this->servicio->formatearCodigo($tv->codigo),
            'usuarios' => $this->servicio->usuariosCandidatos((string) $request->user()->codisb),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $alias = trim((string) $request->input('alias'));
        $resultado = $this->servicio->aprobar(
            $request->input('codigo'),
            $alias,
            (int) $request->input('usuario_id'),
            $this->vistas($request),
            $request->user(),
        );

        if ($resultado['ok']) {
            return redirect()->route('televisores.index')
                ->with('mensaje', "TV vinculada: {$alias}. En unos segundos empieza a mostrar SIDES.");
        }

        $mensaje = MonitorTvService::ERRORES[$resultado['error']];

        return $resultado['error'] === 'CODIGO_INVALIDO'
            ? redirect()->route('televisores.index')->with('error', $mensaje)
            : redirect()->route('televisores.create', ['codigo' => $request->input('codigo')])->withInput()->with('error', $mensaje);
    }

    public function edit(Request $request, int $tv): View
    {
        $registro = $this->buscar($request, $tv);

        return view('televisores.form', [
            'tv' => $registro,
            'codigo' => null,
            'usuarios' => $this->servicio->usuariosCandidatos((string) $registro->codisb, $registro->usuario_id),
        ]);
    }

    public function update(Request $request, int $tv): RedirectResponse
    {
        $registro = $this->buscar($request, $tv);
        $resultado = $this->servicio->actualizar(
            $registro,
            $request->input('alias'),
            (int) $request->input('usuario_id'),
            $this->vistas($request),
        );

        return $resultado['ok']
            ? redirect()->route('televisores.index')->with('mensaje', "TV actualizada: {$registro->alias}.")
            : redirect()->route('televisores.edit', $registro->id)->withInput()->with('error', MonitorTvService::ERRORES[$resultado['error']]);
    }

    /** Desvincular: se borra el registro; la TV recibe 401 y vuelve a pedir un código. */
    public function destroy(Request $request, int $tv): RedirectResponse
    {
        $registro = $this->buscar($request, $tv);
        $registro->delete();

        return redirect()->route('televisores.index')
            ->with('mensaje', "TV desvinculada: {$registro->alias}. Para volver a usarla tiene que pedir un código nuevo.");
    }

    /** Borra los accesos rechazados de las TVs de esta droguería con más de N días. */
    public function purgar(Request $request): RedirectResponse
    {
        $dias = in_array((int) $request->input('dias'), self::DIAS_PURGA, true) ? (int) $request->input('dias') : 7;

        $borrados = DB::table('sides_monitor_tv_log')
            ->where('created_at', '<', Carbon::now()->subDays($dias))
            ->whereIn('monitor_tv_id', SidesMonitorTv::query()->where('codisb', $request->user()->codisb)->select('id'))
            ->delete();

        return redirect()->route('televisores.index')
            ->with('mensaje', "Se borraron {$borrados} accesos rechazados de más de {$dias} ".($dias === 1 ? 'día' : 'días').'.');
    }

    private function buscar(Request $request, int $id): SidesMonitorTv
    {
        return SidesMonitorTv::query()
            ->whereKey($id)
            ->where('codisb', $request->user()->codisb)
            ->where('estado', 'ACTIVO')
            ->firstOrFail();
    }

    /** @return array{monitor: bool, alertas: bool, picking: bool} */
    private function vistas(Request $request): array
    {
        return [
            'monitor' => $request->boolean('vistaMonitor'),
            'alertas' => $request->boolean('vistaAlertas'),
            'picking' => $request->boolean('vistaPicking'),
        ];
    }
}
