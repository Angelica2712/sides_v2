<?php

namespace App\Services\Siad;

use App\Services\Auditoria\Auditoria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pedidos para el SIAD (ERP de la droguería). Puerto del ApiController del SIDES legacy con el
 * mismo contrato; en v2 SIDES comparte la base con SEPED, así que un solo UPDATE deja al día a
 * los dos (ya no hay upd_pedidoSeped).
 *
 * El SIAD arma el PEDIDO.TXT con la fila completa de pedido y pedren. Los datos del despacho
 * (lote, despachador, embalador, bultos, chequeado...) viven en sides_*_operacion, así que al
 * responder se superponen sobre pedido/pedren sin copiarlos de vuelta: un valor de operación no
 * nulo gana; si no hay, queda el original.
 *
 * Todo con DB::table, como el legacy: las fechas salen 'Y-m-d H:i:s', que es lo que lee el SIAD.
 */
class SiadPedidosService
{
    /** Campos que el SIAD lee como int (clases Pedido y Pedren de FrmMenu.cs): un null le rompe la respuesta. */
    public const ENTEROS_PEDIDO = ['id', 'dcredito', 'numren', 'numund', 'mantenerNegociacion', 'sincronizado', 'pedfiscal',
        'despasignado', 'sincAlterno', 'comprometeunidades', 'pedido_recibido'];

    public const ENTEROS_PEDREN = ['id', 'item', 'cantidad', 'cantdesp', 'bulto', 'packing', 'manejalote', 'psicotropico',
        'alertalote', 'refrigerado', 'chequeado', 'dcredito', 'marcarDelete', 'ExiRealPick'];

    /** sides_pedido_operacion → pedido */
    private const OPERACION_PEDIDO = ['despachador', 'embalador', 'recipiente', 'cantBultos', 'num_cesta_ped',
        'despasignado', 'comprometeunidades', 'fecpicking2', 'fecpacking2'];

    /** sides_pedren_operacion → pedren (chequeado aparte: -1 es "sin dato") */
    private const OPERACION_PEDREN = ['lote', 'feclote', 'ubicacion', 'deposito', 'bulto', 'packing', 'recipiente',
        'despachador', 'alertalote', 'ExiRealPick', 'marcarDelete'];

    /** Si el pedido ya está FACTURADO, un aviso atrasado de estos estados no lo devuelve atrás. */
    private const NO_PISAN_FACTURADO = ['PROCESADO', 'RECIBIDO', 'PICKING', 'PACKING', 'PEND-FACTURA', 'FACTURANDO'];

    public function __construct(private readonly Auditoria $auditoria)
    {
    }

    /**
     * El pedido más reciente de la sede en ese estado, con sus renglones. En PEND-FACTURA de una
     * sede con SIDES v2 salta los que tengan la operación incompleta y entrega el siguiente.
     *
     * @return list<array{id: int, pedido: object, pedren: list<object>}>
     */
    public function pedido(string $codisb, string $estado): array
    {
        $validar = $estado === 'PEND-FACTURA' && $this->usaSidesV2($codisb);

        $candidatos = DB::table('pedido')->where('codisb', $codisb)->where('estado', $estado)
            ->orderByDesc('id')->limit($validar ? (int) config('siad.max_revisados', 20) : 1)->get();

        foreach ($candidatos as $pedido) {
            $renglones = $this->renglones($pedido);
            if ($validar && ($motivo = $this->incompleto($pedido, $renglones))) {
                $this->avisarIncompleto($pedido, $motivo);

                continue;
            }

            return [$this->armar($pedido, $renglones)];
        }

        return [];
    }

    /** Como pedido(), pero cada pedido se entrega una sola vez (pedido.pedido_recibido). Sin validación. */
    public function pedidoRecibido(string $codisb, string $estado): array
    {
        $pedido = DB::table('pedido')->where('codisb', $codisb)->where('estado', $estado)
            ->where('pedido_recibido', 0)->orderByDesc('id')->first();

        if (! $pedido) {
            return [];
        }

        $salida = [$this->armar($pedido, $this->renglones($pedido))];
        DB::table('pedido')->where('id', $pedido->id)->where('codisb', $codisb)->update(['pedido_recibido' => 1]);

        return $salida;
    }

    /** @return list<array{id: int}> */
    public function idsEnEstado(string $codisb, string $estado): array
    {
        return DB::table('pedido')->where('codisb', $codisb)->where('estado', $estado)->orderBy('id')->pluck('id')
            ->map(fn ($id) => ['id' => (int) $id])->all();
    }

    /**
     * Cambio de estado que manda el SIAD. Nunca lanza: devuelve {status, msg}, y el SIAD reintenta
     * mientras status no sea 200.
     *
     * @return array{status: int, msg: string, codisb?: string, id?: int} codisb e id solo si cambió el pedido
     */
    public function actualizar(mixed $datos): array
    {
        if (is_array($datos) && array_is_list($datos)) {
            $datos = $datos[0] ?? null;
        }
        $id = is_array($datos) ? trim((string) ($datos['id'] ?? '')) : '';
        $estado = is_array($datos) ? strtoupper(trim((string) ($datos['estado'] ?? ''))) : '';

        if (! ctype_digit($id) || $estado === '') {
            return ['status' => 500, 'msg' => 'SOLICITUD INVALIDA'];
        }

        // FACTURADO trae el número de factura; FACTURANDO, el del pedido del ERP; ANULADO, el motivo.
        $documento = trim((string) ($datos['numerod'] ?? ''));
        if (str_ends_with($documento, 'FACT:')) {
            $documento = '';
        }
        $documento = trim(mb_substr(str_replace('FACT: ', '', $documento), 0, 50));

        return DB::transaction(function () use ($id, $estado, $documento) {
            $pedido = DB::table('pedido')->where('id', $id)->lockForUpdate()->first();
            if (! $pedido) {
                return ['status' => 500, 'msg' => 'PEDIDO NO ENCONTRADO'];
            }

            if ($pedido->estado === 'FACTURADO' && in_array($estado, self::NO_PISAN_FACTURADO, true)) {
                Log::info("SIAD API -> upd_pedido ID {$id}: {$estado} ignorado, el pedido ya está FACTURADO (codisb {$pedido->codisb})");

                return ['status' => 200, 'msg' => 'PEDIDO YA FACTURADO'];
            }

            $ahora = Carbon::now()->format('Y-m-d H:i:s');
            $cambios = match ($estado) {
                'PROCESADO' => ['fecprocesado' => $ahora],
                'RECIBIDO' => ['fecrecibido' => $ahora],
                'PICKING' => ['fecpicking' => $ahora],
                'PACKING' => ['fecpacking' => $ahora],
                'FACTURANDO' => ['documento' => $documento !== '' ? "PED ERP: {$documento}" : '', 'fecfacturado' => $ahora],
                'FACTURADO' => ['documento' => trim("{$pedido->documento} FACT: {$documento}"), 'fecfacturado' => $ahora],
                'ANULADO' => ['observacion' => $documento, 'fecfacturado' => $ahora],
                default => ['feccompletado' => $ahora],
            };

            DB::table('pedido')->where('id', $id)->where('codisb', $pedido->codisb)->update(['estado' => $estado] + $cambios);

            Log::info("SIAD API -> upd_pedido ID {$id}: {$pedido->estado} -> {$estado} (codisb {$pedido->codisb})");
            $this->auditoria->registrar([
                'codisb' => $pedido->codisb,
                'correo' => 'SIAD',
                'accion' => 'siad.upd_pedido',
                'descripcion' => "El SIAD cambió el pedido #{$id} de {$pedido->estado} a {$estado}",
                'referencia' => $id,
                'datos' => ['estado_anterior' => $pedido->estado, 'estado' => $estado, 'numerod' => $documento],
                'metodo' => 'POST',
                'ruta' => 'upd_pedido',
            ]);

            return ['status' => 200, 'msg' => 'UPD SATISFACTORIO', 'codisb' => $pedido->codisb, 'id' => (int) $id];
        });
    }

    /** Sede que trabaja con SIDES v2: tiene sides_cfg y en SEPED no la marcaron INACTIVA. */
    private function usaSidesV2(string $codisb): bool
    {
        if (! DB::table('sides_cfg')->where('codisb', $codisb)->exists()) {
            return false;
        }

        $usarSides = DB::getSchemaBuilder()->hasTable('cfg_sides')
            ? DB::table('cfg_sides')->where('codisb', $codisb)->value('usarSides')
            : null;

        return strtoupper((string) $usarSides) !== 'INACTIVA';
    }

    /** @return Collection<int, object> */
    private function renglones(object $pedido): Collection
    {
        return DB::table('pedren')->where('id', $pedido->id)->where('codisb', $pedido->codisb)->orderBy('desprod')->get();
    }

    /** Motivo por el que el pedido no se entrega todavía, o null si está completo. */
    private function incompleto(object $pedido, Collection $renglones): ?string
    {
        if (! DB::table('sides_pedido_operacion')->where('id_pedido', $pedido->id)->where('codisb', $pedido->codisb)->exists()) {
            return 'no tiene fila en sides_pedido_operacion';
        }

        $operacion = $this->operacionRenglones($pedido);
        foreach ($renglones as $renglon) {
            $op = $operacion->get((int) $renglon->item);
            if (! $op) {
                return "el renglón {$renglon->item} no tiene fila en sides_pedren_operacion";
            }
            if ((int) $op->cantdesp !== (int) $renglon->cantdesp) {
                return "el renglón {$renglon->item} tiene cantdesp {$renglon->cantdesp} en pedren y {$op->cantdesp} en sides_pedren_operacion";
            }
        }

        return null;
    }

    /** El SIAD pregunta cada 1 o 2 minutos: el aviso sale una vez por hora por pedido. */
    private function avisarIncompleto(object $pedido, string $motivo): void
    {
        if (Cache::add("siad:incompleto:{$pedido->codisb}:{$pedido->id}", true, now()->addHour())) {
            Log::warning("SIAD API -> pedido {$pedido->id} (codisb {$pedido->codisb}) en PEND-FACTURA no se entrega: {$motivo}");
        }
    }

    /** @return array{id: int, pedido: object, pedren: list<object>} */
    private function armar(object $pedido, Collection $renglones): array
    {
        $operacion = DB::table('sides_pedido_operacion')->where('id_pedido', $pedido->id)->where('codisb', $pedido->codisb)->first();
        if ($operacion) {
            $this->superponer($pedido, $operacion, self::OPERACION_PEDIDO);
        }

        $operacionRenglones = $this->operacionRenglones($pedido);
        $renglones = $renglones->map(function ($renglon) use ($operacionRenglones) {
            if ($op = $operacionRenglones->get((int) $renglon->item)) {
                $this->superponer($renglon, $op, self::OPERACION_PEDREN);
                if ($op->chequeado !== null && (int) $op->chequeado >= 0) {
                    $renglon->chequeado = $op->chequeado;
                }
            }

            return $this->enteros($renglon, self::ENTEROS_PEDREN);
        });

        return [
            'id' => (int) $pedido->id,
            'pedido' => $this->enteros($pedido, self::ENTEROS_PEDIDO),
            'pedren' => $renglones->values()->all(),
        ];
    }

    /** @return Collection<int, object> renglones de operación por item */
    private function operacionRenglones(object $pedido): Collection
    {
        return DB::table('sides_pedren_operacion')->where('id_pedido', $pedido->id)->where('codisb', $pedido->codisb)
            ->get()->keyBy(fn ($op) => (int) $op->item);
    }

    private function superponer(object $fila, object $operacion, array $campos): void
    {
        foreach ($campos as $campo) {
            if (property_exists($operacion, $campo) && $operacion->{$campo} !== null) {
                $fila->{$campo} = $operacion->{$campo};
            }
        }
    }

    /** Los enteros siempre como int (null → 0). Textos y fechas quedan como vienen: un "0" se grabaría en el ERP. */
    private function enteros(object $fila, array $campos): object
    {
        foreach ($campos as $campo) {
            if (property_exists($fila, $campo)) {
                $fila->{$campo} = (int) $fila->{$campo};
            }
        }

        return $fila;
    }
}
