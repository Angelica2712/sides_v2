<?php

namespace Tests\Unit;

use App\Support\Monitor\TiemposPedido;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class TiemposPedidoTest extends TestCase
{
    private function pedido(array $datos): object
    {
        return (object) array_merge(['fecprocesado' => null, 'fecpicking' => null, 'fecpacking' => null], $datos);
    }

    public function test_recibido_solo_cuenta_espera_hasta_ahora(): void
    {
        $ahora = Carbon::parse('2026-09-15 10:00:59');

        $this->assertSame(
            ['espera' => 60, 'picking' => 0, 'packing' => 0],
            TiemposPedido::calcular($this->pedido(['estado' => 'RECIBIDO', 'fecprocesado' => '2026-09-15 09:00:00']), $ahora)
        );
    }

    public function test_packing_reparte_los_tres_tramos(): void
    {
        $ahora = Carbon::parse('2026-09-15 10:00:00');
        $pedido = $this->pedido([
            'estado' => 'PACKING',
            'fecprocesado' => '2026-09-15 08:00:00',
            'fecpicking' => '2026-09-15 08:30:00',
            'fecpacking' => '2026-09-15 09:45:00',
        ]);

        $this->assertSame(['espera' => 30, 'picking' => 75, 'packing' => 15], TiemposPedido::calcular($pedido, $ahora));
    }

    public function test_formato_legible(): void
    {
        $this->assertSame('0 min', TiemposPedido::formatear(0));
        $this->assertSame('45 min', TiemposPedido::formatear(45));
        $this->assertSame('1 h', TiemposPedido::formatear(60));
        $this->assertSame('1 h 30 min', TiemposPedido::formatear(90));
        $this->assertSame('1 d', TiemposPedido::formatear(1440));
        $this->assertSame('1 d 1 h', TiemposPedido::formatear(1500));
    }

    public function test_semaforo_y_tiempo_de_la_etapa_actual(): void
    {
        $this->assertSame('normal', TiemposPedido::nivel(29));
        $this->assertSame('atencion', TiemposPedido::nivel(30));
        $this->assertSame('demorado', TiemposPedido::nivel(60));

        $tiempos = ['espera' => 10, 'picking' => 20, 'packing' => 30];
        $this->assertSame(10, TiemposPedido::enEstadoActual('RECIBIDO', $tiempos));
        $this->assertSame(20, TiemposPedido::enEstadoActual('PICKING', $tiempos));
        $this->assertSame(30, TiemposPedido::enEstadoActual('PACKING', $tiempos));
    }

    public function test_fechas_faltantes_y_otros_estados_dan_cero(): void
    {
        $ahora = Carbon::parse('2026-09-15 10:00:00');

        $this->assertSame(['espera' => 0, 'picking' => 0, 'packing' => 0], TiemposPedido::calcular($this->pedido(['estado' => 'PICKING']), $ahora));
        $this->assertSame(['espera' => 0, 'picking' => 0, 'packing' => 0], TiemposPedido::calcular($this->pedido(['estado' => 'FACTURADO', 'fecprocesado' => '2026-09-15 08:00:00']), $ahora));
    }
}
