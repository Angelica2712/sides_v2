<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesFacturaGrupo extends Model
{
    protected $table = 'sides_factura_grupo';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['id', 'codisb', 'codcli', 'nomcli', 'estado', 'usuario', 'fecha'];

    public function renglones()
    {
        return $this->hasMany(SidesFacturaGrupoRen::class, 'id_grupo');
    }
}
