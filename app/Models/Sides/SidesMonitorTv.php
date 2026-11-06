<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un televisor de la droguería vinculado a SIDES (app de TV del compañero, otra aplicación).
 * Ver App\Services\MonitorTv\MonitorTvService.
 *
 * estado: PENDIENTE (la TV muestra su código y espera) o ACTIVO (aprobado). Las vistas son de la
 * TV y no del usuario: un mismo usuario puede tener varias TVs, cada una con lo suyo.
 */
class SidesMonitorTv extends Model
{
    protected $table = 'sides_monitor_tv';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'token_entrega'];

    protected function casts(): array
    {
        return [
            'codigo_expira' => 'datetime',
            'aprobado_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'vistaMonitor' => 'boolean',
            'vistaAlertas' => 'boolean',
            'vistaPicking' => 'boolean',
            'token_entrega' => 'encrypted',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(SidesUsers::class, 'usuario_id');
    }

    /** @return list<string> claves de las vistas habilitadas, en el orden de monitor_config. */
    public function vistas(): array
    {
        return array_keys(array_filter([
            'monitor' => $this->vistaMonitor,
            'alertas' => $this->vistaAlertas,
            'picking' => $this->vistaPicking,
        ]));
    }
}
