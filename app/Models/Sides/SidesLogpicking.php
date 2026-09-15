<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesLogpicking extends Model
{
        protected $table = 'sides_logpicking';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['id', 'id_pedido', 'usuario', 'numren', 'numund', 'tiempo_picking', 'fecha_del_picking', 'descripcion'];
}
