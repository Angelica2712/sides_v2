<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesCestasig extends Model
{
        protected $table = 'sides_cestasig';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['id', 'codcesta', 'cestanum', 'status', 'ruta', 'fecha', 'fecpicking', 'fecpacking', 'operadorPick', 'operadorPack'];
}
