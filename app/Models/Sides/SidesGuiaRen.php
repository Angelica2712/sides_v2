<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesGuiaRen extends Model
{
        protected $table = 'sides_guia_ren';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['id', 'codcli', 'nomcli', 'orden', 'terminado', 'cargado'];
}
