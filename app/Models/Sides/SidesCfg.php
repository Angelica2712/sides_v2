<?php

namespace App\Models\Sides;

use App\Support\MenuSides;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SidesCfg extends Model
{
    protected $table = 'sides_cfg';
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'codisb';
    protected $fillable = ['codisb', 'nombre', 'nomcorto', 'rif', 'direccion', 'contacto', 'telefono', 'localidad', 'activarPacking', 'fecha', 'pedidoxAprobar', 'valorIva', 'modoAlcabala', 'activarValPicking', 'activarEtiPacking', 'claveValPicking', 'ordenPedSides', 'EtiPieNota', 'ModoCesta', 'pitarPacking', 'activarValPacking', 'EstiloPicking', 'TamLetraMonitor', 'activarVerOperadorMonitor', 'MostrarTituloMonitor', 'formatoPersEtiq', 'mostrarEntrega', 'mostrarDepPiking', 'nomdominio', 'imagenPdfRutaAbsoluta', 'nomsubdominio', 'activarImpTicket', 'mostrarObsMonitor', 'mostrarTranMonitor', 'dominioapiSeped', 'titulopagina', 'mostrarExiRealPick', 'activar_separador_automatico', 'activar_etiqueta_packing', 'latitud', 'longitud', 'activarSincronizacionRutas', 'procAlcabalaPicking', 'pickingOrdenLibre', 'logo'];

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

    /** @return list<string> módulos con interruptor que la droguería tiene encendidos */
    public function modulosEncendidos(): array
    {
        return array_values(array_filter(MenuSides::CONTROLABLES, fn (string $modulo) => $this->tieneModulo($modulo)));
    }
}
