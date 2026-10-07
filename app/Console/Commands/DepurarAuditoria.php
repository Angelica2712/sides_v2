<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * La auditoría crece con cada acción de cada usuario: cuando pasa del máximo de registros
 * (config sides.auditoria_maximo) se borran los más antiguos y quedan los más recientes.
 * Corre cada noche desde el programador (routes/console.php).
 */
class DepurarAuditoria extends Command
{
    /** Se borra por tandas para no dejar la tabla bloqueada mientras los operarios trabajan. */
    private const TANDA = 5000;

    /** Desde cuántos registros borrados vale la pena compactar la tabla para devolver el espacio. */
    private const COMPACTAR_DESDE = 1000;

    protected $signature = 'sides:depurar-auditoria {--maximo= : Registros que se conservan (por defecto, el de la configuración)}';

    protected $description = 'Borra los registros más antiguos de la auditoría cuando pasa del máximo y deja los más recientes';

    public function handle(): int
    {
        $maximo = (int) ($this->option('maximo') ?? config('sides.auditoria_maximo'));
        if ($maximo <= 0) {
            $this->info('La auditoría no tiene máximo de registros: no se borra nada.');

            return self::SUCCESS;
        }

        $total = DB::table('sides_auditoria')->count();
        if ($total <= $maximo) {
            $this->info("La auditoría tiene {$total} registros (máximo {$maximo}): no hay nada que borrar.");

            return self::SUCCESS;
        }

        // El registro más nuevo de los que sobran: de ese hacia atrás se borra todo.
        $corte = DB::table('sides_auditoria')->orderByDesc('id')->skip($maximo)->limit(1)->value('id');
        $borrados = 0;
        do {
            $tanda = DB::table('sides_auditoria')->where('id', '<=', $corte)->limit(self::TANDA)->delete();
            $borrados += $tanda;
        } while ($tanda > 0);

        $this->compactar($borrados);
        Log::info("AUDITORIA -> DEPURADA: {$borrados} registros antiguos borrados, quedan ".($total - $borrados)." (máximo {$maximo}).");
        $this->info("Se borraron {$borrados} registros antiguos de la auditoría; quedan ".($total - $borrados).'.');

        return self::SUCCESS;
    }

    /** Borrar filas no achica el archivo de la tabla en MySQL/MariaDB: hay que reconstruirla. */
    private function compactar(int $borrados): void
    {
        if ($borrados < self::COMPACTAR_DESDE || ! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        try {
            DB::statement('OPTIMIZE TABLE sides_auditoria');
        } catch (Throwable $e) {
            Log::warning('AUDITORIA -> NO SE PUDO COMPACTAR LA TABLA: '.$e->getMessage());
        }
    }
}
