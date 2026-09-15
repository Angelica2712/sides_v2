<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesAlcabalaPedidoLiberado extends Model
{
    protected $table = 'sides_alcabala_pedido_liberado';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['numped', 'codisb', 'fecha_liberado', 'liberado_por'];
}
