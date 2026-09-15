<?php

namespace App\Models\Sides;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Usuario de SIDES (tabla sides_users, compartida con SEPED v2). Es el modelo de login
 * de esta app; los usuarios de SEPED (`users`) no entran a SIDES.
 */
class SidesUsers extends Authenticatable
{
    use Notifiable;

    protected $table = 'sides_users';
    protected $primaryKey = 'id';
    protected $fillable = ['name', 'email', 'password', 'estado', 'clave', 'activarMonitor', 'activarPicking', 'activarPacking', 'activarUsuario', 'activarConfig', 'eliminarPedido', 'activarResetear', 'activarPedido', 'activarResumen', 'codisb', 'activarInformes', 'activarGuiaCarga', 'activarGuiaDescarga', 'activarLiberarAlcabala'];
    protected $hidden = ['password', 'remember_token', 'clave'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
