<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesLogInacPicking extends Model
{
        protected $table = 'sides_log_inac_picking';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['id', 'id_pedido', 'usuario', 'numren', 'numund', 'tiempo_picking_inac', 'fecha_del_picking', 'id_pedido_anterior'];
}
