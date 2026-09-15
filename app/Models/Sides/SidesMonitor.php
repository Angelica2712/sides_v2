<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesMonitor extends Model
{
        protected $table = 'sides_monitor';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['id', 'descrip', 'criterio', 'caracterLogo', 'codisb'];
}
