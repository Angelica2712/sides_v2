<?php

namespace App\Models\Sides;

use Illuminate\Database\Eloquent\Model;

class SidesApiAccessLog extends Model
{
    protected $table = 'sides_api_access_logs';
    public $timestamps = false;
    protected $primaryKey = 'id';
    protected $fillable = ['api_key_id', 'ip', 'status_code'];

    public function apiKey()
    {
        return $this->belongsTo(SidesApiKey::class, 'api_key_id', 'id');
    }
}
