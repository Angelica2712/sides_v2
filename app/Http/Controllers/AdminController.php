<?php

namespace App\Http\Controllers;

use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesUsers;
use App\Services\Admin\LogoDrogueria;
use App\Services\Admin\ModulosDrogueria;
use App\Support\FormatosEtiqueta;
use App\Support\MenuSides;
use App\Support\PermisosUsuario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administración de SIDES v2 (solo el usuario de FULLTECH360): los datos de cada droguería, su
 * logo y los módulos que usa. La droguería no cambia su nombre, RIF ni logo desde Configuración.
 */
class AdminController extends Controller
{
    /** Datos de la droguería que salen en etiquetas, ticket y guías. */
    private const DATOS = ['nombre', 'nomcorto', 'rif', 'direccion', 'localidad', 'contacto', 'telefono'];

    public function __construct(
        private readonly ModulosDrogueria $modulos,
        private readonly LogoDrogueria $logo,
    ) {
    }

    public function index(): View
    {
        $droguerias = SidesCfg::query()->orderBy('nombre')->get();

        return view('admin.index', [
            'droguerias' => $droguerias,
            'modulosActivos' => $droguerias->mapWithKeys(fn (SidesCfg $cfg) => [$cfg->codisb => $cfg->modulosEncendidos()]),
            'usuarios' => DB::table('sides_users')->whereNull('seped_user_id')->selectRaw('codisb, COUNT(*) as total')->groupBy('codisb')->pluck('total', 'codisb'),
        ]);
    }

    public function create(): View
    {
        // Una droguería nueva arranca con los módulos básicos; los opcionales se encienden a mano.
        return view('admin.form', ['drogueria' => new SidesCfg(['activarPacking' => 1]), 'activos' => MenuSides::BASICOS]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'codisb' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('sides_cfg', 'codisb')],
            ...$this->reglas(),
        ], $this->mensajes(), $this->atributos());

        $drogueria = new SidesCfg(['codisb' => $datos['codisb'], 'titulopagina' => 'SIDES', 'logoForma' => $datos['logoForma'] ?? 'cuadro', ...$this->datos($datos)]);
        $this->modulos->guardar($drogueria, $datos['modulos'] ?? [], $this->opciones($request), $request->user());
        $this->logo->aplicar($drogueria, $request->file('logo'), false);

        return redirect()->route('admin.edit', $drogueria->codisb)
            ->with('mensaje', "Droguería {$drogueria->nombre} creada. Ahora crea su usuario encargado para que pueda entrar.");
    }

    public function edit(string $codisb): View
    {
        $drogueria = SidesCfg::query()->findOrFail($codisb);

        return view('admin.form', [
            'drogueria' => $drogueria,
            'activos' => $drogueria->modulosEncendidos(),
            'usuarios' => SidesUsers::query()->deLaDrogueria()->where('codisb', $codisb)->orderByDesc('activarUsuario')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, string $codisb): RedirectResponse
    {
        $drogueria = SidesCfg::query()->findOrFail($codisb);
        $datos = $request->validate($this->reglas(), $this->mensajes(), $this->atributos());

        $drogueria->fill([...$this->datos($datos), 'logoForma' => $datos['logoForma'] ?? ($drogueria->logoForma ?: 'cuadro')]);
        $this->modulos->guardar($drogueria, $datos['modulos'] ?? [], $this->opciones($request), $request->user());
        $this->logo->aplicar($drogueria, $request->file('logo'), $request->boolean('quitarLogo'));

        return redirect()->route('admin.index')->with('mensaje', "Droguería {$drogueria->nombre} actualizada.");
    }

    /**
     * Primer usuario de una droguería: con todos los permisos de su sucursal (sin esAdmin), así
     * crea a los demás desde Usuarios. La contraseña la escribe FULLTECH360 (la que pidió la
     * droguería) o, si la deja vacía, se genera; en ambos casos se muestra una sola vez.
     */
    public function encargado(Request $request, string $codisb): RedirectResponse
    {
        $drogueria = SidesCfg::query()->findOrFail($codisb);
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $datos = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('sides_users', 'email')],
            ...self::REGLAS_CLAVE,
        ], [
            'name.required' => 'Escribe el nombre del encargado.',
            'email.required' => 'Escribe el correo con el que va a entrar.',
            'email.email' => 'El correo no es válido.',
            'email.unique' => 'Ya hay un usuario con ese correo.',
            ...self::MENSAJES_CLAVE,
        ]);

        // Guía de carga/descarga quitan Etiquetas del menú: son permisos de chofer, no de encargado.
        $permisos = array_fill_keys(array_diff(PermisosUsuario::columnas(), ['activarGuiaCarga', 'activarGuiaDescarga']), 1);
        $clave = $this->claveElegida($datos);
        $usuario = new SidesUsers();
        $usuario->forceFill([
            ...$permisos,
            'name' => $datos['name'],
            'email' => $datos['email'],
            'password' => $clave,
            'clave' => '',
            'estado' => 'ACTIVO',
            'codisb' => $drogueria->codisb,
            'esAdmin' => 0,
        ])->save();

        return redirect()->route('admin.edit', $codisb)
            ->with('mensaje', "Usuario encargado {$usuario->name} creado.")
            ->with('credenciales', ['correo' => $usuario->email, 'clave' => $clave]);
    }

    /** Contraseña nueva para un usuario de la droguería (por ejemplo, el encargado la olvidó). */
    public function clave(Request $request, string $codisb, int $usuario): RedirectResponse
    {
        $registro = SidesUsers::query()->deLaDrogueria()->where('codisb', $codisb)->where('esAdmin', 0)->findOrFail($usuario);
        $datos = $request->validateWithBag("clave{$registro->id}", self::REGLAS_CLAVE, self::MENSAJES_CLAVE);
        $clave = $this->claveElegida($datos);
        $registro->forceFill(['password' => $clave])->save();

        return redirect()->route('admin.edit', $codisb)
            ->with('mensaje', "Contraseña nueva para {$registro->name}.")
            ->with('credenciales', ['correo' => $registro->email, 'clave' => $clave]);
    }

    /** Contraseña escrita a mano: opcional, mismo mínimo que en Usuarios. */
    private const REGLAS_CLAVE = ['password' => ['nullable', 'string', 'min:6', 'max:100']];

    private const MENSAJES_CLAVE = [
        'password.min' => 'La contraseña debe tener al menos 6 caracteres.',
        'password.max' => 'La contraseña es demasiado larga.',
    ];

    /** La que escribió FULLTECH360, o una generada si dejó el campo vacío. */
    private function claveElegida(array $datos): string
    {
        $escrita = (string) ($datos['password'] ?? '');

        return $escrita !== '' ? $escrita : $this->claveNueva();
    }

    /** Fácil de dictar: sin símbolos ni caracteres que se confunden (0/O, 1/l/I). */
    private function claveNueva(): string
    {
        $letras = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';

        return collect(range(1, 10))->map(fn () => $letras[random_int(0, strlen($letras) - 1)])->implode('');
    }

    private function reglas(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:150'],
            'nomcorto' => ['nullable', 'string', 'max:20'],
            'rif' => ['nullable', 'string', 'max:20'],
            'direccion' => ['nullable', 'string', 'max:150'],
            'localidad' => ['nullable', 'string', 'max:100'],
            'contacto' => ['nullable', 'string', 'max:50'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'logo' => LogoDrogueria::REGLAS,
            'logoForma' => ['nullable', Rule::in(array_keys(SidesCfg::FORMAS_LOGO))],
            'modulos' => ['array'],
            'modulos.*' => ['string'],
            'formatoPersEtiq' => ['nullable', Rule::in([...array_keys(FormatosEtiqueta::FORMATOS), FormatosEtiqueta::PERSONALIZADO])],
            'etiquetaAncho' => ['required_if:formatoPersEtiq,'.FormatosEtiqueta::PERSONALIZADO, 'nullable', 'integer', 'between:'.FormatosEtiqueta::MIN_MM.','.FormatosEtiqueta::MAX_MM],
            'etiquetaAlto' => ['required_if:formatoPersEtiq,'.FormatosEtiqueta::PERSONALIZADO, 'nullable', 'integer', 'between:'.FormatosEtiqueta::MIN_MM.','.FormatosEtiqueta::MAX_MM],
            'ticketAncho' => ['nullable', 'integer', 'between:'.FormatosEtiqueta::TICKET_MIN.','.FormatosEtiqueta::TICKET_MAX],
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
            'etiquetaAncho.required_if' => 'Escribe el ancho de la etiqueta en milímetros.',
            'etiquetaAlto.required_if' => 'Escribe el alto de la etiqueta en milímetros.',
            'etiquetaAncho.*' => 'El ancho de la etiqueta debe ser un número entero entre '.FormatosEtiqueta::MIN_MM.' y '.FormatosEtiqueta::MAX_MM.' mm.',
            'etiquetaAlto.*' => 'El alto de la etiqueta debe ser un número entero entre '.FormatosEtiqueta::MIN_MM.' y '.FormatosEtiqueta::MAX_MM.' mm.',
            'ticketAncho.*' => 'El ancho del ticket debe ser un número entero entre '.FormatosEtiqueta::TICKET_MIN.' y '.FormatosEtiqueta::TICKET_MAX.' mm.',
            'logoForma.in' => 'Elige si el logo va en cuadro o en círculo.',
            // Los del logo van antes que *.max, que si no se queda con logo.max.
            ...LogoDrogueria::MENSAJES,
            '*.max' => 'El campo :attribute es demasiado largo.',
        ];
    }

    private function atributos(): array
    {
        return ['nomcorto' => 'nombre corto', 'direccion' => 'dirección', 'telefono' => 'teléfono'];
    }

    /** @return array<string, ?string> */
    private function datos(array $validados): array
    {
        return collect(self::DATOS)->mapWithKeys(fn (string $campo) => [$campo => $validados[$campo] ?? null])->all();
    }

    /** @return array{activarPacking: bool, procAlcabalaPicking: bool, formatoPersEtiq: ?string, etiquetaAncho: ?int, etiquetaAlto: ?int, ticketAncho: ?int, activarImpTicket: bool, activar_etiqueta_packing: bool, mostrarEntrega: bool} */
    private function opciones(Request $request): array
    {
        return [
            'activarPacking' => $request->boolean('activarPacking'),
            'procAlcabalaPicking' => $request->boolean('procAlcabalaPicking'),
            'formatoPersEtiq' => $request->input('formatoPersEtiq'),
            'etiquetaAncho' => $request->filled('etiquetaAncho') ? $request->integer('etiquetaAncho') : null,
            'etiquetaAlto' => $request->filled('etiquetaAlto') ? $request->integer('etiquetaAlto') : null,
            'ticketAncho' => $request->filled('ticketAncho') ? $request->integer('ticketAncho') : null,
            'activarImpTicket' => $request->boolean('activarImpTicket'),
            'activar_etiqueta_packing' => $request->boolean('activar_etiqueta_packing'),
            'mostrarEntrega' => $request->boolean('mostrarEntrega'),
        ];
    }
}
