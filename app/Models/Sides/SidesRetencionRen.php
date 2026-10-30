<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesRetencionRen extends Model
{
    protected $table = 'sides_retencion_ren';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['id_retencion', 'id_pedido', 'usuario', 'fecha', 'fec_liberado'];
}
