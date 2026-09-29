<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

/** Un registro de auditoría (ver App\Services\Auditoria\Auditoria). */
class SidesAuditoria extends Model
{
    protected $table = 'sides_auditoria';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'fecha' => 'datetime',
        'datos' => 'array',
    ];
}
