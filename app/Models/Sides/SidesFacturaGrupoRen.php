<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesFacturaGrupoRen extends Model
{
    protected $table = 'sides_factura_grupo_ren';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['id', 'id_grupo', 'id_pedido'];
}
