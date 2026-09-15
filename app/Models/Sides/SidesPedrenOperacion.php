<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

/**
 * Datos de trabajo de SIDES por renglón. PK compuesta (id_pedido, item): Eloquent no
 * la soporta en save()/find(), usar where('id_pedido', ..)->where('item', ..)->update().
 */
class SidesPedrenOperacion extends Model
{
    protected $table = 'sides_pedren_operacion';
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'id_pedido';
    protected $fillable = ['id_pedido', 'item', 'codisb', 'cantdesp', 'chequeado', 'bulto', 'packing', 'recipiente', 'despachador', 'ubicacion', 'deposito', 'lote', 'feclote', 'alertalote', 'ExiRealPick', 'marcarDelete'];
}
