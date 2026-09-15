<?php

namespace App\Models\Seped;

use Illuminate\Database\Eloquent\Model;

class Pedren extends Model
{
    protected $table = 'pedren';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['id', 'item', 'codprod', 'desprod', 'cantidad', 'precio', 'barra', 'tipocatalogo', 'regulado', 'tipo', 'pvp', 'iva', 'da', 'di', 'dc', 'pp', 'neto', 'subtotal', 'codisb', 'cantdesp', 'bulto', 'ubicacion', 'packing', 'costo', 'lote', 'feclote', 'manejalote', 'marcamodelo', 'deposito', 'coddpto', 'codgrupo', 'codsubgrupo', 'psicotropico', 'listalote', 'alertalote', 'refrigerado', 'FlagFactOM', 'dp', 'dv', 'codcli', 'agregado_por_tipo', 'agregado_por_usuario', 'despachador', 'recipiente', 'chequeado', 'dvp', 'dcredito', 'da2', 'marcarDelete', 'dct', 'estado_desp', 'codac3', 'ExiRealPick', 'dn_ct', 'dnv_ct', 'dnv_Detalle_ct', 'codnct', 'escala_und', 'dvDetalle', 'da3'];
}
