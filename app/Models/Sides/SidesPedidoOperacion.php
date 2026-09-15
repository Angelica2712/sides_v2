<?php

namespace App\Models\Sides;

use App\Models\Seped\Pedido;
use Illuminate\Database\Eloquent\Model;

/**
 * Datos de trabajo de SIDES por pedido. El pedido en sí es `pedido` de SEPED.
 */
class SidesPedidoOperacion extends Model
{
    protected $table = 'sides_pedido_operacion';
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'id_pedido';
    protected $fillable = ['id_pedido', 'codisb', 'despachador', 'embalador', 'recipiente', 'cantBultos', 'despasignado', 'num_cesta_ped', 'comprometeunidades', 'fecpicking2', 'fecpacking2'];

    public function pedido()
    {
        return $this->belongsTo(Pedido::class, 'id_pedido', 'id');
    }
}
