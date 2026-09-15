<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesAlcabalaLote extends Model
{
    protected $table = 'sides_alcabala_lote';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['codisb', 'nombre', 'estado', 'estado_packing', 'origen_creacion', 'id_perfil', 'creado_por', 'fecha_creacion', 'fecha_confirmado', 'fecha_terminacion', 'fecha_inicio_packing', 'fecha_terminacion_packing', 'embalador_batch', 'observacion'];

    public function pedidos()
    {
        return $this->hasMany(SidesAlcabalaLotePedido::class, 'id_lote', 'id');
    }
}
