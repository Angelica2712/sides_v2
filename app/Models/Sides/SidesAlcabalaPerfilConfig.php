<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesAlcabalaPerfilConfig extends Model
{
    protected $table = 'sides_alcabala_perfil_config';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['codisb', 'nombre', 'min_productos_repetidos', 'max_pedidos_lote', 'mismo_cliente', 'misma_zona', 'ventana_horas', 'usar_mismo_dia', 'agrupar_pocos_renglones', 'max_renglones_pocos', 'max_pedidos_pocos_renglones', 'creado_por', 'fecha_creacion', 'activo'];
}
