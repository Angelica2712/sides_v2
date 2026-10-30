<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

/** Carro de la droguería que se asigna a las guías de despacho. */
class SidesVehiculo extends Model
{
    protected $table = 'sides_vehiculo';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['codisb', 'placa', 'descripcion', 'activo'];

    /** Texto que queda en sides_guia.unidad y sale impreso en la guía. */
    public function etiqueta(): string
    {
        return mb_substr(trim($this->placa.($this->descripcion ? ' - '.$this->descripcion : '')), 0, 100);
    }
}
