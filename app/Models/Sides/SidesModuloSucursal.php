<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

/** Módulo opcional activado para una droguería (ver App\Support\MenuSides::OPCIONALES). */
class SidesModuloSucursal extends Model
{
    protected $table = 'sides_modulo_sucursal';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['codisb', 'modulo', 'activo', 'actualizado_por', 'updated_at'];
}
