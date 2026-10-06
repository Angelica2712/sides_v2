<?php

namespace App\Models\Sides;

use App\Support\MenuSides;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class SidesCfg extends Model
{
    protected $table = 'sides_cfg';
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'codisb';
    protected $fillable = ['codisb', 'nombre', 'nomcorto', 'rif', 'direccion', 'contacto', 'telefono', 'localidad', 'activarPacking', 'fecha', 'pedidoxAprobar', 'valorIva', 'modoAlcabala', 'activarValPicking', 'activarEtiPacking', 'claveValPicking', 'ordenPedSides', 'EtiPieNota', 'ModoCesta', 'pitarPacking', 'activarValPacking', 'EstiloPicking', 'TamLetraMonitor', 'activarVerOperadorMonitor', 'MostrarTituloMonitor', 'formatoPersEtiq', 'mostrarEntrega', 'mostrarDepPiking', 'nomdominio', 'imagenPdfRutaAbsoluta', 'nomsubdominio', 'activarImpTicket', 'mostrarObsMonitor', 'mostrarTranMonitor', 'dominioapiSeped', 'titulopagina', 'mostrarExiRealPick', 'activar_separador_automatico', 'activar_etiqueta_packing', 'latitud', 'longitud', 'activarSincronizacionRutas', 'procAlcabalaPicking', 'pickingOrdenLibre', 'logo', 'logoForma'];

    /** Formas en que se muestra el logo en pantalla (encabezado e inicio de sesión). */
    public const FORMAS_LOGO = ['cuadro' => 'Cuadro', 'circulo' => 'Círculo'];

    /** modulo => activo, leído una vez por instancia. */
    private ?array $estadoModulos = null;

    public function modulos(): HasMany
    {
        return $this->hasMany(SidesModuloSucursal::class, 'codisb', 'codisb');
    }

    /** Sin fila en sides_modulo_sucursal: los básicos están encendidos y los opcionales apagados. */
    public function tieneModulo(string $modulo): bool
    {
        $this->estadoModulos ??= $this->modulos()->pluck('activo', 'modulo')->map(fn ($activo) => (bool) $activo)->all();

        return $this->estadoModulos[$modulo] ?? in_array($modulo, MenuSides::BASICOS, true);
    }

    /** URL pública del logo de la droguería; null si no tiene (se usa el de SIDES). */
    public function urlLogo(): ?string
    {
        return $this->logo ? asset('storage/'.$this->logo) : null;
    }

    /** El logo se muestra recortado en un círculo (logos redondos) en vez de en un cuadro. */
    public function logoCircular(): bool
    {
        return $this->formaLogo() === 'circulo';
    }

    public function formaLogo(): string
    {
        return isset(self::FORMAS_LOGO[$this->logoForma]) ? $this->logoForma : 'cuadro';
    }

    /** Archivo del ícono de pestaña de un logo en una forma: junto al logo, con la forma en el nombre. */
    public static function rutaIcono(string $logo, string $forma): string
    {
        return preg_replace('/\.[^.\/]+$/', '', $logo).'-icono-'.$forma.'.png';
    }

    public function rutaIconoActual(): ?string
    {
        return $this->logo ? self::rutaIcono($this->logo, $this->formaLogo()) : null;
    }

    /** URL del ícono de la pestaña del navegador (el logo con su forma); si aún no se generó, el logo tal cual. */
    public function urlIcono(): ?string
    {
        $ruta = $this->rutaIconoActual();

        return $ruta && Storage::disk('public')->exists($ruta) ? asset('storage/'.$ruta) : $this->urlLogo();
    }

    /** @return list<string> módulos con interruptor que la droguería tiene encendidos */
    public function modulosEncendidos(): array
    {
        return array_values(array_filter(MenuSides::CONTROLABLES, fn (string $modulo) => $this->tieneModulo($modulo)));
    }
}
