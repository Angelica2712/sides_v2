<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesAlcabalaLotePedido extends Model
{
    protected $table = 'sides_alcabala_lote_pedido';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['id_lote', 'numped', 'num_caja', 'codcli', 'fecha_agregado'];
}
