<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesLogpacking extends Model
{
        protected $table = 'sides_logpacking';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['id', 'id_pedido', 'usuario', 'numren', 'numund', 'tiempo_packing', 'fecha_del_packing'];
}
