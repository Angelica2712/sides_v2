<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesRuta;
use App\Services\Rutas\RutasException;
use App\Services\Rutas\RutasService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RutasController extends Controller
{
    public function __construct(private readonly RutasService $rutas)
    {
    }

    public function index(Request $request): View
    {
        $codisb = $request->user()->codisb;
        $buscar = trim((string) $request->query('buscar', ''));

        return view('rutas.index', [
            'rutas' => $this->rutas->listar($codisb, $buscar),
            'buscar' => $buscar,
            'sincronizada' => $this->sincronizada($request),
            'rutasSeped' => $this->rutas->rutasSeped($codisb),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate(['nombre' => ['required', 'string', 'max:100']], $this->mensajes());

        return $this->creacion($request, function () use ($request, $datos) {
            $ruta = $this->rutas->crear($request->user()->codisb, $datos['nombre']);

            return redirect()->route('rutas.agregar', $ruta->id)->with('mensaje', "Ruta {$ruta->nombre} creada. Ahora agrega sus clientes.");
        });
    }

    public function importar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'archivo' => ['required', 'file', 'extensions:xlsx', 'max:5120'],
        ], [
            ...$this->mensajes(),
            'archivo.required' => 'Elige el archivo Excel.',
            'archivo.extensions' => 'El archivo debe ser Excel .xlsx. Si es .xls, ábrelo y guárdalo como .xlsx.',
            'archivo.max' => 'El archivo no puede pasar de 5 MB.',
        ]);

        return $this->creacion($request, function () use ($request, $datos) {
            $resultado = $this->rutas->importar($request->user()->codisb, $datos['nombre'], $request->file('archivo')->getRealPath());

            return redirect()->route('rutas.show', $resultado->ruta->id)->with('mensaje', $resultado->mensaje("Ruta {$resultado->ruta->nombre} creada desde Excel:"));
        });
    }

    public function desdeSeped(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'ruta_seped' => ['required', 'string'],
            'nombre' => ['nullable', 'string', 'max:100'],
        ], [...$this->mensajes(), 'ruta_seped.required' => 'Elige la ruta de SEPED.']);

        return $this->creacion($request, function () use ($request, $datos) {
            $resultado = $this->rutas->crearDesdeSeped($request->user()->codisb, $datos['ruta_seped'], ($datos['nombre'] ?? null) ?: $datos['ruta_seped']);

            return redirect()->route('rutas.show', $resultado->ruta->id)->with('mensaje', $resultado->mensaje("Ruta {$resultado->ruta->nombre} creada desde SEPED:"));
        });
    }

    public function sincronizar(Request $request): RedirectResponse
    {
        if (! $this->sincronizada($request)) {
            return back()->with('error', 'La sincronización automática de rutas está apagada en Configuración.');
        }

        $resultado = $this->rutas->sincronizar($request->user()->codisb);

        return back()->with('mensaje', "Rutas sincronizadas con SEPED: {$resultado['rutas']} rutas nuevas, {$resultado['agregados']} clientes agregados y {$resultado['actualizados']} actualizados.");
    }

    public function show(Request $request, int $ruta): View
    {
        $registro = $this->ruta($request, $ruta);
        $buscar = trim((string) $request->query('buscar', ''));

        return view('rutas.show', [
            'ruta' => $registro,
            'totalClientes' => $registro->clientes()->count(),
            'clientes' => $this->rutas->clientesDeRuta($registro, $buscar),
            'buscar' => $buscar,
        ]);
    }

    public function update(Request $request, int $ruta): RedirectResponse
    {
        $registro = $this->ruta($request, $ruta);
        $datos = $request->validate(['nombre' => ['required', 'string', 'max:100']], $this->mensajes());

        return $this->ejecutar($registro, fn () => $this->rutas->renombrar($registro, $datos['nombre']), 'Nombre de la ruta actualizado.');
    }

    public function zona(Request $request, int $ruta): RedirectResponse
    {
        $registro = $this->ruta($request, $ruta);
        $datos = $request->validate(['zona' => ['required', 'string', 'max:100']], ['zona.required' => 'Escribe la zona.']);

        $cantidad = $this->rutas->renombrarZona($registro, $datos['zona']);

        return redirect()->route('rutas.show', $registro->id)->with('mensaje', "Zona cambiada en {$cantidad} ".($cantidad === 1 ? 'cliente' : 'clientes').'.');
    }

    public function descargar(Request $request, int $ruta): BinaryFileResponse
    {
        $registro = $this->ruta($request, $ruta);

        return response()
            ->download($this->rutas->exportar($registro), 'ruta_'.Str::slug($registro->nombre, '_').'.xlsx')
            ->deleteFileAfterSend();
    }

    public function destroy(Request $request, int $ruta): RedirectResponse
    {
        $registro = $this->ruta($request, $ruta);
        $this->rutas->eliminar($registro);

        return redirect()->route('rutas.index')->with('mensaje', "Ruta {$registro->nombre} eliminada.");
    }

    public function agregar(Request $request, int $ruta): View
    {
        $registro = $this->ruta($request, $ruta);
        $buscar = trim((string) $request->query('buscar', ''));

        return view('rutas.agregar', [
            'ruta' => $registro,
            'buscar' => $buscar,
            'clientes' => $this->rutas->clientesDisponibles($registro->codisb, $buscar),
        ]);
    }

    public function agregarClientes(Request $request, int $ruta): RedirectResponse
    {
        $registro = $this->ruta($request, $ruta);
        $datos = $request->validate([
            'clientes' => ['required', 'array', 'max:500'],
            'clientes.*' => ['string', 'max:100'],
        ], ['clientes.required' => 'Marca al menos un cliente.']);

        $resultado = $this->rutas->agregarClientes($registro, $datos['clientes']);

        return redirect()->route('rutas.show', $registro->id)->with('mensaje', $resultado->mensaje('Listo:'));
    }

    public function actualizarCliente(Request $request, int $ruta, int $item): RedirectResponse
    {
        $registro = $this->ruta($request, $ruta);
        $datos = $request->validate([
            'zona' => ['nullable', 'string', 'max:100'],
            'sec' => ['required', 'integer', 'min:1', 'max:999999'],
        ], [
            'sec.required' => 'Escribe la secuencia.',
            'sec.*' => 'La secuencia debe ser un número entero mayor que 0.',
        ]);

        return $this->ejecutar(
            $registro,
            fn () => $this->rutas->actualizarCliente($registro, $item, (string) ($datos['zona'] ?? ''), (int) $datos['sec'], $request->boolean('retiraLocal')),
            'Cliente actualizado.',
        );
    }

    public function quitarCliente(Request $request, int $ruta, int $item): RedirectResponse
    {
        $registro = $this->ruta($request, $ruta);

        return $this->ejecutar($registro, function () use ($registro, $item) {
            $cliente = $this->rutas->quitarCliente($registro, $item);

            return "{$cliente->nomcli} salió de la ruta.";
        }, '');
    }

    private function ruta(Request $request, int $id): SidesRuta
    {
        try {
            return $this->rutas->buscar($request->user()->codisb, $id);
        } catch (RutasException $e) {
            abort(404, $e->getMessage());
        }
    }

    /** Crear rutas a mano se desactiva cuando las rutas vienen de SEPED automáticamente (como el legacy). */
    private function creacion(Request $request, Closure $accion): RedirectResponse
    {
        if ($this->sincronizada($request)) {
            return redirect()->route('rutas.index')->with('error', 'Las rutas se sincronizan automáticamente con SEPED. Para crearlas a mano, apaga la sincronización en Configuración.');
        }

        try {
            return $accion();
        } catch (RutasException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    private function ejecutar(SidesRuta $ruta, Closure $accion, string $mensaje): RedirectResponse
    {
        try {
            $mensaje = $accion() ?? $mensaje;
        } catch (RutasException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('mensaje', $mensaje);
    }

    private function sincronizada(Request $request): bool
    {
        return (bool) SidesCfg::query()->find($request->user()->codisb)?->activarSincronizacionRutas;
    }

    private function mensajes(): array
    {
        return [
            'nombre.required' => 'Escribe el nombre de la ruta.',
            'nombre.max' => 'El nombre de la ruta no puede pasar de 100 caracteres.',
        ];
    }
}
