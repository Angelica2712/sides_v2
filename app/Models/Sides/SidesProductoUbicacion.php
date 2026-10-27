<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

/**
 * Ubicación de un producto registrada desde SIDES (Ubicaciones), en el formato de
 * App\Support\UbicacionAlmacen. Gana sobre producto.ubicacion, la que manda el ERP.
 */
class SidesProductoUbicacion extends Model
{
    protected $table = 'sides_producto_ubicacion';
    protected $fillable = ['codisb', 'codprod', 'ubicacion', 'usuario_id', 'usuario'];
}
