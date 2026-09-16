<?php

namespace App\Console\Commands;

use App\Models\Sides\SidesCfg;
use App\Services\Rutas\RutasService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Legacy vAccionSincRutas:rutas (cada hora): rutas de SEPED → rutas de SIDES. */
class SincronizarRutas extends Command
{
    protected $signature = 'sides:sincronizar-rutas {codisb? : Solo esta sucursal}';

    protected $description = 'Crea y actualiza las rutas de SIDES con las rutas que SEPED asigna a los clientes (sucursales con la sincronización activa)';

    public function handle(RutasService $rutas): int
    {
        $sucursales = SidesCfg::query()
            ->where('activarSincronizacionRutas', 1)
            ->when($this->argument('codisb'), fn ($q, $codisb) => $q->where('codisb', $codisb))
            ->whereHas('modulos', fn ($q) => $q->where('modulo', 'rutas')->where('activo', 1))
            ->pluck('codisb');

        $fallas = 0;
        foreach ($sucursales as $codisb) {
            try {
                $r = $rutas->sincronizar($codisb);
                $this->info("{$codisb}: {$r['rutas']} rutas nuevas, {$r['agregados']} clientes agregados, {$r['actualizados']} actualizados.");
            } catch (Throwable $e) {
                $fallas++;
                Log::error("Sincronización de rutas de {$codisb}: {$e->getMessage()}");
                $this->error("{$codisb}: {$e->getMessage()}");
            }
        }

        return $fallas === 0 ? self::SUCCESS : self::FAILURE;
    }
}
