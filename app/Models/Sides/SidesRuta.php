<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesRuta extends Model
{
    protected $table = 'sides_rutas';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['nombre', 'fecha', 'codisb'];

    public function clientes()
    {
        return $this->hasMany(SidesRutaren::class, 'id', 'id');
    }
}
