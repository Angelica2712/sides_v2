<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesModuloSucursal;
use App\Services\Admin\ModulosDrogueria;
use App\Support\FormatosEtiqueta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Administración de SIDES v2: droguerías y los módulos opcionales que usa cada una. */
class AdminController extends Controller
{
    public function __construct(private readonly ModulosDrogueria $modulos)
    {
    }

    public function index(): View
    {
        return view('admin.index', [
            'droguerias' => SidesCfg::query()->orderBy('nombre')->get(),
            'modulosActivos' => SidesModuloSucursal::query()->where('activo', 1)->get()->groupBy('codisb')
                ->map(fn ($filas) => $filas->pluck('modulo')->all()),
            'usuarios' => DB::table('sides_users')->selectRaw('codisb, COUNT(*) as total')->groupBy('codisb')->pluck('total', 'codisb'),
        ]);
    }

    public function create(): View
    {
        return view('admin.form', ['drogueria' => new SidesCfg(['activarPacking' => 1]), 'activos' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'codisb' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('sides_cfg', 'codisb')],
            ...$this->reglas(),
        ], $this->mensajes());

        $drogueria = new SidesCfg([
            'codisb' => $datos['codisb'],
            'nombre' => $datos['nombre'],
            'nomcorto' => $datos['nomcorto'] ?? null,
            'titulopagina' => 'SIDES',
        ]);
        $this->modulos->guardar($drogueria, $datos['modulos'] ?? [], $this->opciones($request), $request->user());

        return redirect()->route('admin.index')->with('mensaje', "Droguería {$drogueria->nombre} creada.");
    }

    public function edit(string $codisb): View
    {
        $drogueria = SidesCfg::query()->findOrFail($codisb);

        return view('admin.form', [
            'drogueria' => $drogueria,
            'activos' => $drogueria->modulos()->where('activo', 1)->pluck('modulo')->all(),
        ]);
    }

    public function update(Request $request, string $codisb): RedirectResponse
    {
        $drogueria = SidesCfg::query()->findOrFail($codisb);
        $datos = $request->validate($this->reglas(), $this->mensajes());

        $drogueria->fill(['nombre' => $datos['nombre'], 'nomcorto' => $datos['nomcorto'] ?? null]);
        $this->modulos->guardar($drogueria, $datos['modulos'] ?? [], $this->opciones($request), $request->user());

        return redirect()->route('admin.index')->with('mensaje', "Módulos de {$drogueria->nombre} actualizados.");
    }

    private function reglas(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'nomcorto' => ['nullable', 'string', 'max:20'],
            'modulos' => ['array'],
            'modulos.*' => ['string'],
            'formatoPersEtiq' => ['nullable', Rule::in(array_keys(FormatosEtiqueta::FORMATOS))],
        ];
    }

    private function mensajes(): array
    {
        return [
            'codisb.required' => 'Escribe el código de la droguería.',
            'codisb.unique' => 'Ya existe una droguería con ese código.',
            'codisb.regex' => 'El código solo puede tener letras, números, guion y guion bajo.',
            'nombre.required' => 'Escribe el nombre de la droguería.',
            'formatoPersEtiq.in' => 'Elige un tamaño de etiqueta de la lista.',
        ];
    }

    /** @return array{activarPacking: bool, procAlcabalaPicking: bool, formatoPersEtiq: ?string, activarImpTicket: bool, activar_etiqueta_packing: bool, mostrarEntrega: bool} */
    private function opciones(Request $request): array
    {
        return [
            'activarPacking' => $request->boolean('activarPacking'),
            'procAlcabalaPicking' => $request->boolean('procAlcabalaPicking'),
            'formatoPersEtiq' => $request->input('formatoPersEtiq'),
            'activarImpTicket' => $request->boolean('activarImpTicket'),
            'activar_etiqueta_packing' => $request->boolean('activar_etiqueta_packing'),
            'mostrarEntrega' => $request->boolean('mostrarEntrega'),
        ];
    }
}
