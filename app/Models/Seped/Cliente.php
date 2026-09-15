<?php

namespace App\Models\Seped;

use Illuminate\Database\Eloquent\Model;

/**
 * Sincronizada desde el ERP externo — solo lectura efectiva desde la app.
 * @see arquitectura_sync_erp.md
 */
class Cliente extends Model
{
    protected $table = 'cliente';
    public $timestamps = false;
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'codcli';
    protected $fillable = ['codcli', 'nombre', 'rif', 'direccion', 'entrega', 'telefono', 'contacto', 'zona', 'usuario', 'clave', 'ppago', 'dcredito', 'estado', 'canal', 'limite', 'tipo', 'dcorte', 'dcomercial', 'cadena', 'agenda', 'dinternet', 'ruta', 'cb', 'especial', 'mpermiso', 'dotro', 'saldo', 'email', 'tipocatalogo', 'codisb', 'usaprecio', 'nuevo', 'vencido', 'saldoDs', 'vencidoDs', 'limiteDs', 'critSepMoneda', 'DctoPreferencial', 'codisbactivo', 'codac3', 'coderp', 'orden'];
}
