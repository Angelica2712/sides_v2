<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesApiKey extends Model
{
    protected $table = 'sides_api_keys';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['codisb', 'nombre', 'token_hash', 'activa', 'notas', 'ultimo_uso_at'];
    protected $hidden = ['token_hash'];

    public function logs()
    {
        return $this->hasMany(SidesApiAccessLog::class, 'api_key_id', 'id');
    }
}
