<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesCfg extends Model
{
        protected $table = 'sides_cfg';
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'codisb';
    protected $fillable = ['codisb', 'nombre', 'nomcorto', 'rif', 'direccion', 'contacto', 'telefono', 'localidad', 'activarPacking', 'fecha', 'pedidoxAprobar', 'valorIva', 'modoAlcabala', 'activarValPicking', 'activarEtiPacking', 'claveValPicking', 'ordenPedSides', 'EtiPieNota', 'ModoCesta', 'pitarPacking', 'activarValPacking', 'EstiloPicking', 'TamLetraMonitor', 'activarVerOperadorMonitor', 'MostrarTituloMonitor', 'formatoPersEtiq', 'mostrarEntrega', 'mostrarDepPiking', 'nomdominio', 'imagenPdfRutaAbsoluta', 'nomsubdominio', 'activarImpTicket', 'mostrarObsMonitor', 'mostrarTranMonitor', 'dominioapiSeped', 'titulopagina', 'mostrarExiRealPick', 'activar_separador_automatico', 'activar_etiqueta_packing', 'latitud', 'longitud', 'activarSincronizacionRutas', 'procAlcabalaPicking'];
}
