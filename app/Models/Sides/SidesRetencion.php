<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesRetencion extends Model
{
    protected $table = 'sides_retencion';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['codisb', 'codcli', 'nomcli', 'estado', 'usuario', 'fecha', 'usuario_liberado', 'fec_liberada', 'id_grupo'];

    public function renglones()
    {
        return $this->hasMany(SidesRetencionRen::class, 'id_retencion');
    }
}
