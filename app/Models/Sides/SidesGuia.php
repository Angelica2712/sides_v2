<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesGuia extends Model
{
        protected $table = 'sides_guia';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['id', 'fecha', 'chofer', 'estado', 'nomchofer', 'codisb', 'ruta', 'chofer_aux_id', 'chof_aux_nom', 'fecha_salida', 'unidad', 'ordenarPor', 'latitud', 'longitud'];
}
