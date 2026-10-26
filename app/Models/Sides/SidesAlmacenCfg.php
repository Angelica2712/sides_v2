<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

/** Acomodo del almacén de una droguería para el Almacén 3D (ver App\Support\ConfigAlmacen). */
class SidesAlmacenCfg extends Model
{
    protected $table = 'sides_almacen_cfg';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'codisb';
    protected $fillable = ['codisb', 'config', 'actualizado_por'];
    protected $casts = ['config' => 'array'];
}
