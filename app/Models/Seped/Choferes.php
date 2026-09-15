<?php

namespace App\Models\Seped;

use Illuminate\Database\Eloquent\Model;

/**
 * Sincronizada desde el ERP externo — solo lectura efectiva desde la app.
 * @see arquitectura_sync_erp.md
 */
class Choferes extends Model
{
    protected $table = 'choferes';
    public $timestamps = false;
    public $incrementing = false;
    protected $primaryKey = 'chof_co';
    protected $fillable = ['chof_co', 'chof_nom', 'chof_ced', 'chof_tipo', 'codisb'];
}
