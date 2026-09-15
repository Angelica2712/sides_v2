<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SidesCfg extends Model
{
    protected $table = 'sides_cfg';
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'codisb';
    protected $fillable = ['codisb', 'nombre', 'nomcorto', 'rif', 'direccion', 'contacto', 'telefono', 'localidad', 'activarPacking', 'fecha', 'pedidoxAprobar', 'valorIva', 'modoAlcabala', 'activarValPicking', 'activarEtiPacking', 'claveValPicking', 'ordenPedSides', 'EtiPieNota', 'ModoCesta', 'pitarPacking', 'activarValPacking', 'EstiloPicking', 'TamLetraMonitor', 'activarVerOperadorMonitor', 'MostrarTituloMonitor', 'formatoPersEtiq', 'mostrarEntrega', 'mostrarDepPiking', 'nomdominio', 'imagenPdfRutaAbsoluta', 'nomsubdominio', 'activarImpTicket', 'mostrarObsMonitor', 'mostrarTranMonitor', 'dominioapiSeped', 'titulopagina', 'mostrarExiRealPick', 'activar_separador_automatico', 'activar_etiqueta_packing', 'latitud', 'longitud', 'activarSincronizacionRutas', 'procAlcabalaPicking'];

    /** Módulos opcionales activos, leídos una vez por instancia. */
    private ?array $modulosActivos = null;

    public function modulos(): HasMany
    {
        return $this->hasMany(SidesModuloSucursal::class, 'codisb', 'codisb');
    }

    public function tieneModulo(string $modulo): bool
    {
        $this->modulosActivos ??= $this->modulos()->where('activo', 1)->pluck('modulo')->all();

        return in_array($modulo, $this->modulosActivos, true);
    }
}
