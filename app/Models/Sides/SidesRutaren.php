<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesRutaren extends Model
{
    protected $table = 'sides_rutasren';
    public $timestamps = false;
    protected $primaryKey = 'item';
    protected $fillable = ['id', 'codisb', 'codcli', 'nomcli', 'rif', 'sec', 'zona', 'retiraLocal'];
}
