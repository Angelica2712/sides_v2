<?php

namespace App\Services\Admin;

use App\Models\Sides\SidesAlcabalaLote;
use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesModuloSucursal;
use App\Models\Sides\SidesUsers;
use App\Support\FormatosEtiqueta;
use App\Support\MenuSides;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Guarda qué módulos opcionales usa una droguería (administración de SIDES v2). */
class ModulosDrogueria
{
    /**
     * @param  list<string>  $activos  claves de MenuSides::OPCIONALES
     * @param  array{activarPacking: bool, procAlcabalaPicking: bool, formatoPersEtiq?: ?string, activarImpTicket?: bool, activar_etiqueta_packing?: bool, mostrarEntrega?: bool}  $opciones
     */
    public function guardar(SidesCfg $cfg, array $activos, array $opciones, SidesUsers $admin): void
    {
        $activos = array_values(array_intersect(MenuSides::OPCIONALES, $activos));
        $batchActivo = in_array('batch', $activos, true);
        $etiquetasActivo = in_array('etiquetas', $activos, true);

        if (! $batchActivo && $cfg->exists) {
            $this->exigirSinTrabajoDeBatch($cfg->codisb);
        }

        DB::transaction(function () use ($cfg, $activos, $opciones, $batchActivo, $etiquetasActivo, $admin) {
            // Sin Batch Picking no puede haber pedidos llegando en espera: nadie podría agruparlos ni liberarlos.
            $cfg->forceFill([
                'activarPacking' => $opciones['activarPacking'] ? 1 : 0,
                'procAlcabalaPicking' => $batchActivo && $opciones['procAlcabalaPicking'] ? 1 : 0,
                // activarEtiPacking era el interruptor legacy de Etiquetas: sigue al módulo.
                'activarEtiPacking' => $etiquetasActivo ? 1 : 0,
                'formatoPersEtiq' => FormatosEtiqueta::clave($opciones['formatoPersEtiq'] ?? $cfg->formatoPersEtiq),
                'activar_etiqueta_packing' => $etiquetasActivo && ($opciones['activar_etiqueta_packing'] ?? false) ? 1 : 0,
                'activarImpTicket' => ($opciones['activarImpTicket'] ?? false) ? 1 : 0,
                'mostrarEntrega' => ($opciones['mostrarEntrega'] ?? false) ? 1 : 0,
            ])->save();

            foreach (MenuSides::OPCIONALES as $modulo) {
                SidesModuloSucursal::query()->updateOrCreate(
                    ['codisb' => $cfg->codisb, 'modulo' => $modulo],
                    ['activo' => in_array($modulo, $activos, true) ? 1 : 0, 'actualizado_por' => $admin->name, 'updated_at' => Carbon::now()]
                );
            }
        });
    }

    private function exigirSinTrabajoDeBatch(string $codisb): void
    {
        $enEspera = DB::table('pedido')->where('codisb', $codisb)->where('estado', 'ALCABALA')->count();
        $lotes = SidesAlcabalaLote::query()->where('codisb', $codisb)->whereIn('estado', ['ABIERTO', 'CONFIRMADO'])->count();

        if ($enEspera > 0 || $lotes > 0) {
            throw ValidationException::withMessages([
                'modulos' => "No se puede desactivar Batch Picking: la droguería tiene {$enEspera} pedidos en espera y {$lotes} lotes en curso. Libéralos o termina los lotes primero.",
            ]);
        }
    }
}
