<?php

namespace App\Services\Picking;

use App\Models\Sides\SidesCesta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cestas de la droguería (sides_cesta) y cuáles están ocupadas.
 *
 * Una cesta está ocupada mientras su pedido está en PICKING: se libera al terminar el picking. No
 * hay fila de reserva: se deduce de sides_pedido_operacion.recipiente, así liberar el pedido (la
 * mercancía sigue en la cesta y la hereda quien lo retome) o resetearlo no pueden dejar una cesta trabada.
 */
class CestasService
{
    public static function normalizar(?string $codigo): string
    {
        return mb_strtoupper(trim((string) $codigo));
    }

    /** @return Collection<string, object> pedido que ocupa cada cesta, por código */
    public function ocupadas(string $codisb): Collection
    {
        return $this->consultaOcupadas($codisb)
            ->orderBy('pedido.id')
            ->get(['op.recipiente', 'pedido.id', 'op.despachador', 'op.despasignado'])
            ->keyBy(fn ($fila) => self::normalizar($fila->recipiente));
    }

    /** @return list<string> códigos que se pueden tomar ahora */
    public function libres(string $codisb): array
    {
        $ocupadas = $this->ocupadas($codisb);

        return SidesCesta::query()->where('codisb', $codisb)->where('activo', 1)->orderBy('codigo')->pluck('codigo')
            ->reject(fn (string $codigo) => $ocupadas->has(self::normalizar($codigo)))
            ->values()->all();
    }

    /**
     * Revisa que la cesta se pueda usar y bloquea su fila hasta el fin de la transacción: de dos
     * operarios que piden la misma a la vez, el segundo ya ve al primero. Llamar dentro de una transacción.
     *
     * @param  list<int>  $pedidos  pedidos que sí pueden tenerla (el mismo pedido, los del lote)
     * @return array{codigo: ?string, error: ?string}
     */
    public function apartar(string $codisb, ?string $codigo, array $pedidos = []): array
    {
        $codigo = self::normalizar($codigo);

        $cesta = SidesCesta::query()->where('codisb', $codisb)->where('codigo', $codigo)->lockForUpdate()->first();
        if (! $cesta) {
            return ['codigo' => null, 'error' => "La cesta {$codigo} no está registrada en la lista de cestas."];
        }
        if (! $cesta->activo) {
            return ['codigo' => null, 'error' => "La cesta {$codigo} está desactivada."];
        }

        $ocupante = $this->consultaOcupadas($codisb)
            ->where('op.recipiente', $cesta->codigo)
            ->whereNotIn('pedido.id', $pedidos)
            ->first(['pedido.id', 'op.despachador', 'op.despasignado']);

        if ($ocupante) {
            $quien = $ocupante->despasignado && $ocupante->despachador ? " ({$ocupante->despachador})" : '';

            return ['codigo' => null, 'error' => "La cesta {$cesta->codigo} está ocupada con el pedido #{$ocupante->id}{$quien}. Elige otra."];
        }

        return ['codigo' => $cesta->codigo, 'error' => null];
    }

    private function consultaOcupadas(string $codisb)
    {
        return DB::table('sides_pedido_operacion as op')
            ->join('pedido', 'pedido.id', '=', 'op.id_pedido')
            ->where('pedido.codisb', $codisb)
            ->where('pedido.estado', 'PICKING')
            ->whereNotNull('op.recipiente')
            ->where('op.recipiente', '<>', '');
    }
}
