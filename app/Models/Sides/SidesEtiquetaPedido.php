<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesEtiquetaPedido extends Model
{
        protected $table = 'sides_etiqueta_pedido';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['id', 'numepedi', 'etiqueta', 'nomcli', 'ruta', 'estado', 'codcli', 'fecentregado', 'guia', 'feccargado'];
}
