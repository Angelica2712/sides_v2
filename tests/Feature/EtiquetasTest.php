<?php

namespace Tests\Feature;

use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesEtiquetaPedido;
use App\Models\Sides\SidesModuloSucursal;
use App\Models\Sides\SidesPedidoOperacion;
use App\Models\Sides\SidesUsers;
use Illuminate\Support\Carbon;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class EtiquetasTest extends TestCase
{
    use TablasSides;

    private SidesUsers $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->crearCfg();
        $this->usuario = $this->crearUsuario(['name' => 'Eli Etiqueta', 'email' => 'eli@example.com', 'activarMonitor' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pedidoEmpacado(int $id = 500, array $atributos = [], array $operacion = []): void
    {
        $this->crearPedido(array_merge(['id' => $id, 'estado' => 'PEND-FACTURA', 'nomcli' => "CLIENTE {$id}"], $atributos), array_merge([
            'recipiente' => 'C7', 'cantBultos' => '3', 'despachador' => 'Pedro Picker', 'embalador' => 'Eva Empaca',
        ], $operacion));
    }

    private function etiquetasDe(int $id): array
    {
        return SidesEtiquetaPedido::query()->where('numepedi', (string) $id)->orderBy('etiqueta')
            ->get(['etiqueta', 'estado', 'guia'])->map(fn ($e) => [$e->etiqueta, $e->estado, $e->guia])->all();
    }

    public function test_busca_por_pedido_por_etiqueta_impresa_o_por_recipiente(): void
    {
        $this->pedidoEmpacado();
        $this->actingAs($this->usuario);

        foreach (['500', '500-02', 'C7'] as $filtro) {
            $this->get('/etiquetas?buscar='.$filtro)->assertOk()->assertSee('Pedido #500')->assertSee('value="3"', false);
        }

        $this->crearPedido(['id' => 501, 'estado' => 'PICKING']);
        $this->get('/etiquetas?buscar=501')
            ->assertSee('El pedido #501 está en PICKING: solo se imprimen etiquetas de pedidos con packing terminado.');

        $this->crearPedido(['id' => 502, 'estado' => 'PEND-FACTURA', 'codisb' => 'OTRA']);
        $this->get('/etiquetas?buscar=502')->assertSee('No se encontró ningún pedido con el número o recipiente 502.');
    }

    public function test_el_recipiente_reutilizado_toma_el_pedido_imprimible_mas_reciente(): void
    {
        $this->pedidoEmpacado(500, ['fecprocesado' => '2026-09-14 08:00:00']);
        $this->pedidoEmpacado(510, ['fecprocesado' => '2026-09-15 08:00:00']);
        $this->pedidoEmpacado(520, ['fecprocesado' => '2026-09-15 09:00:00', 'estado' => 'PICKING']);

        $this->actingAs($this->usuario)->get('/etiquetas?buscar=C7')->assertSee('Pedido #510');
    }

    public function test_imprimir_guarda_los_bultos_y_registra_una_etiqueta_por_bulto(): void
    {
        $this->pedidoEmpacado();
        $this->actingAs($this->usuario);

        $this->post('/etiquetas/500/generar', ['bultos' => 3])
            ->assertRedirect(route('etiquetas.imprimir', ['pedido' => 500, 'imprimir' => 1]));
        $this->assertSame([['500-01', 'NUEVO', null], ['500-02', 'NUEVO', null], ['500-03', 'NUEVO', null]], $this->etiquetasDe(500));

        // Reimprimir con menos bultos reemplaza las etiquetas.
        $this->post('/etiquetas/500/generar', ['bultos' => 2]);
        $this->assertSame([['500-01', 'NUEVO', null], ['500-02', 'NUEVO', null]], $this->etiquetasDe(500));
        $this->assertSame('2', SidesPedidoOperacion::query()->find(500)->cantBultos);

        $this->get('/etiquetas/500/imprimir?imprimir=1')
            ->assertOk()
            ->assertSee('size: 130mm 80mm', false)
            ->assertSee('2 de 2')
            ->assertSee('500-02')
            ->assertSee('<svg', false)
            ->assertSee('window.print()', false);
    }

    public function test_reimprimir_conserva_la_guia_y_no_toca_pedidos_entregados(): void
    {
        $this->pedidoEmpacado(500);
        $this->pedidoEmpacado(600, [], ['recipiente' => 'C8']);
        $fila = ['codcli' => 'C001', 'nomcli' => 'CLIENTE', 'ruta' => 'RUTA 1'];
        SidesEtiquetaPedido::query()->insert([
            $fila + ['numepedi' => '500', 'etiqueta' => '500-01', 'estado' => 'EN GUIA', 'guia' => 7, 'feccargado' => '2026-09-15 09:00:00'],
            $fila + ['numepedi' => '600', 'etiqueta' => '600-01', 'estado' => 'ENTREGADO', 'guia' => 8, 'feccargado' => null],
            $fila + ['numepedi' => '600', 'etiqueta' => '600-02', 'estado' => 'ENTREGADO', 'guia' => 8, 'feccargado' => null],
        ]);
        $this->actingAs($this->usuario);

        $this->post('/etiquetas/500/generar', ['bultos' => 2]);
        $this->assertSame([['500-01', 'EN GUIA', 7], ['500-02', 'EN GUIA', 7]], $this->etiquetasDe(500));

        $this->post('/etiquetas/600/generar', ['bultos' => 5])->assertRedirect();
        $this->assertSame([['600-01', 'ENTREGADO', 8], ['600-02', 'ENTREGADO', 8]], $this->etiquetasDe(600));

        // Ya en el camión: se reimprime sin duplicar ni cambiar los bultos.
        $this->pedidoEmpacado(700, [], ['recipiente' => 'C9']);
        SidesEtiquetaPedido::query()->insert($fila + ['numepedi' => '700', 'etiqueta' => '700-01', 'estado' => 'CARGADO', 'guia' => 9, 'feccargado' => '2026-09-15 09:00:00']);
        $this->post('/etiquetas/700/generar', ['bultos' => 3])->assertRedirect();
        $this->assertSame([['700-01', 'CARGADO', 9]], $this->etiquetasDe(700));
    }

    public function test_no_imprime_pedidos_sin_packing_terminado_ni_bultos_invalidos(): void
    {
        $this->pedidoEmpacado();
        $this->crearPedido(['id' => 501, 'estado' => 'PACKING']);
        $this->actingAs($this->usuario);

        $this->from('/etiquetas')->post('/etiquetas/501/generar', ['bultos' => 2])
            ->assertSessionHas('error', 'El pedido #501 está en PACKING: solo se imprimen etiquetas de pedidos con packing terminado.');
        $this->post('/etiquetas/500/generar', ['bultos' => 0])->assertSessionHasErrors(['bultos' => 'Debe haber al menos 1 bulto.']);
        $this->get('/etiquetas/501/imprimir')->assertRedirect(route('etiquetas.index'));

        $this->assertSame(0, SidesEtiquetaPedido::query()->count());
    }

    public function test_formato_direccion_y_ticket_segun_la_configuracion(): void
    {
        $this->pedidoEmpacado(500, ['entrega' => 'AV. PRINCIPAL, LOCAL 4']);
        $this->crearRenglon(500, 1, ['desprod' => 'ACETAMINOFEN 500MG', 'cantdesp' => 2]);
        $this->crearRenglon(500, 2, ['desprod' => 'IBUPROFENO 400MG', 'cantdesp' => 3]);
        $this->actingAs($this->usuario);

        $this->get('/etiquetas/500/imprimir')->assertDontSee('AV. PRINCIPAL');
        $this->get('/etiquetas?buscar=500')->assertDontSee('Imprimir ticket');
        $this->get('/etiquetas/500/ticket')->assertForbidden();

        SidesCfg::query()->whereKey('505094939')->update(['formatoPersEtiq' => 'RPTETIQUETA15X10', 'mostrarEntrega' => 1, 'activarImpTicket' => 1]);

        $this->get('/etiquetas/500/imprimir')->assertSee('size: 150mm 100mm', false)->assertSee('AV. PRINCIPAL, LOCAL 4');
        $this->get('/etiquetas?buscar=500')->assertSee('Imprimir ticket');
        $this->get('/etiquetas/500/ticket')
            ->assertOk()
            ->assertSeeInOrder(['Pedro Picker', 'Eva Empaca', 'ACETAMINOFEN 500MG', 'IBUPROFENO 400MG', 'Unidades totales:', '5']);
    }

    public function test_sin_el_modulo_activo_no_hay_etiquetas(): void
    {
        SidesModuloSucursal::query()->where('modulo', 'etiquetas')->update(['activo' => 0]);

        $this->actingAs($this->usuario)->get('/etiquetas')->assertForbidden();
    }
}
