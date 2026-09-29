<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un aviso a un webhook: PENDIENTE hasta que responde 2xx (ENTREGADO) o se agotan los reintentos (FALLIDO). */
class SidesWebhookEntrega extends Model
{
    public const PENDIENTE = 'PENDIENTE';
    public const ENTREGADO = 'ENTREGADO';
    public const FALLIDO = 'FALLIDO';

    public const UPDATED_AT = null;

    protected $table = 'sides_webhook_entregas';
    protected $primaryKey = 'id';
    protected $fillable = ['uuid', 'webhook_id', 'evento', 'payload', 'estado', 'intentos', 'ultimo_codigo', 'ultimo_error', 'proximo_intento_at', 'entregado_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'proximo_intento_at' => 'datetime',
            'entregado_at' => 'datetime',
        ];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(SidesWebhook::class, 'webhook_id', 'id');
    }
}
