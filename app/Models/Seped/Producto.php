<?php

namespace App\Models\Seped;

use Illuminate\Database\Eloquent\Model;

/**
 * Sincronizada desde el ERP externo — solo lectura efectiva desde la app.
 * @see arquitectura_sync_erp.md
 */
class Producto extends Model
{
    protected $table = 'producto';
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'codprod';
    protected $fillable = ['barra', 'codprod', 'desprod', 'tipo', 'iva', 'regulado', 'codprov', 'precio1', 'cantidad', 'original', 'da', 'oferta', 'upre', 'ppre', 'psugerido', 'pgris', 'nuevo', 'fechafalla', 'tipocatalogo', 'cuarentena', 'dctoneto', 'lote', 'fecvence', 'marcamodelo', 'pactivo', 'costo', 'ubicacion', 'descorta', 'codisb', 'feccatalogo', 'departamento', 'grupo', 'subgrupo', 'opc1', 'opc2', 'opc3', 'precio2', 'precio3', 'precio4', 'precio5', 'precio6', 'undmin', 'undmax', 'undmultiplo', 'cantpub', 'cantreal', 'manejalote', 'indevolutivo', 'codcolor', 'codtalla', 'psicotropico', 'clase', 'moneda', 'factorcambiario', 'refrigerado', 'FlagFactOM', 'dv', 'dvDetalle', 'SuperOFertaMincp', 'dcredito', 'cantcomp', 'dct', 'codac3'];
}
