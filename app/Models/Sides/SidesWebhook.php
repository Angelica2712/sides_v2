<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * URL de una aplicación externa que recibe avisos de SIDES (ver App\Services\Webhooks).
 * `secreto` firma cada envío (HMAC-SHA256) y `token`, si la aplicación lo pide, viaja como
 * Authorization: Bearer. Los dos van cifrados con la APP_KEY de SIDES v2.
 */
class SidesWebhook extends Model
{
    protected $table = 'sides_webhooks';
    protected $primaryKey = 'id';
    protected $fillable = ['codisb', 'nombre', 'url', 'secreto', 'token', 'eventos', 'activo', 'creado_por'];
    protected $hidden = ['secreto', 'token'];

    protected function casts(): array
    {
        return [
            'secreto' => 'encrypted',
            'token' => 'encrypted',
            'eventos' => 'array',
            'activo' => 'boolean',
        ];
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(SidesWebhookEntrega::class, 'webhook_id', 'id');
    }

    /** Sin lista de eventos elegidos, el webhook recibe todos. */
    public function escucha(string $evento): bool
    {
        return $evento === 'ping' || empty($this->eventos) || in_array($evento, $this->eventos, true);
    }
}
