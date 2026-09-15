<?php

namespace App\Models\Seped;

use Illuminate\Database\Eloquent\Model;

class Pedido extends Model
{
    protected $table = 'pedido';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['id', 'codcli', 'fecha', 'estado', 'fecenviado', 'fecprocesado', 'origen', 'origen_ref', 'usuario', 'codvend', 'tipedido', 'nomcli', 'rif', 'dcredito', 'di', 'dc', 'pp', 'subrenglon', 'descuento', 'subtotal', 'impuesto', 'total', 'numren', 'numund', 'destino', 'documento', 'codisb', 'ruta', 'fecpicking', 'fecpacking', 'feccompletado', 'recipiente', 'despachador', 'observacion', 'fecrecibido', 'embalador', 'fecfacturado', 'mantenerNegociacion', 'sincronizado', 'pedfiscal', 'factorcambiario', 'codtransp', 'cantBultos', 'fecentregado', 'despasignado', 'sincAlterno', 'entrega', 'idori', 'telefono', 'contacto', 'fecpicking2', 'codac3', 'num_cesta_ped', 'comprometeunidades', 'fecpacking2', 'pedido_recibido'];
}
