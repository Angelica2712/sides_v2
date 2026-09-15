<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesLogInacPacking extends Model
{
        protected $table = 'sides_log_inac_packing';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['id', 'id_pedido', 'id_pedido_anterior', 'usuario', 'numren', 'numund', 'tiempo_packing_inac', 'fecha_del_packing'];
}
