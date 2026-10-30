<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

/** Cesta de la droguería con la que se hace el picking. */
class SidesCesta extends Model
{
    protected $table = 'sides_cesta';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['codisb', 'codigo', 'activo'];
}
