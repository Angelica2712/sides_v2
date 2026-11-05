<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Usuario de SIDES (tabla sides_users, compartida con SEPED v2). Es el modelo de login
 * de esta app; los usuarios de SEPED (`users`) no entran a SIDES, salvo el FT (FULLTECH360):
 * SEPED lo copia aquí con seped_user_id, su mismo correo y su misma contraseña. Esa
 * columna, su correo, su clave y su estado los escribe solo SEPED.
 */
class SidesUsers extends Authenticatable
{
    use Notifiable;

    protected $table = 'sides_users';
    protected $primaryKey = 'id';
    protected $fillable = ['name', 'email', 'password', 'estado', 'clave', 'activarMonitor', 'activarPicking', 'activarPacking', 'activarUsuario', 'activarConfig', 'eliminarPedido', 'activarResetear', 'activarPedido', 'activarResumen', 'codisb', 'activarInformes', 'activarGuiaCarga', 'activarGuiaDescarga', 'activarLiberarAlcabala', 'activarUbicaciones', 'activarAlmacen', 'activarIndicadores', 'activarAdmin', 'esAdmin'];
    protected $hidden = ['password', 'remember_token', 'clave'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /**
     * Usuarios de la droguería: sin el FT, que no aparece en ninguna lista ni se puede abrir
     * cambiando el id en la URL (igual que en SEPED).
     */
    public function scopeDeLaDrogueria(Builder $consulta): void
    {
        $consulta->whereNull($consulta->qualifyColumn('seped_user_id'));
    }

    /**
     * Puede entrar a Administración de esa droguería: FULLTECH360 (esAdmin) a todas; quien tiene
     * el permiso «Administración» (activarAdmin), solo a la suya.
     */
    public function administraDrogueria(string $codisb): bool
    {
        return (bool) $this->esAdmin || ($this->activarAdmin && $this->codisb === $codisb);
    }

    /** Puede dar o quitar el permiso «Administración» en Usuarios: quien ya lo tiene, o FULLTECH360. */
    public function puedeDarAdministracion(): bool
    {
        return (bool) ($this->esAdmin || $this->activarAdmin);
    }

    /** Es el FT que viene de SEPED (puede pasar entre SEPED y SIDES con un botón). */
    public function esFt(): bool
    {
        return filled($this->seped_user_id);
    }

    /** Configuración de la sucursal del usuario. */
    public function cfg(): BelongsTo
    {
        return $this->belongsTo(SidesCfg::class, 'codisb', 'codisb');
    }
}
