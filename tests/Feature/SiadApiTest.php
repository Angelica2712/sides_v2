<?php

namespace Tests\Feature;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesAuditoria;
use App\Models\Sides\SidesPedrenOperacion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

/** API que consulta el SIAD (contrato del ApiController del SIDES legacy). */
class SiadApiTest extends TestCase
{
    use TablasSides;

    private const API = '/api/siad/token-de-prueba';

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        $this->crearCfg();
        config(['siad.tokens' => ['token-de-prueba'], 'siad.abierta' => false]);
        Event::fake();
    }

    public function test_sin_token_valido_la_api_no_existe(): void
    {
        $this->getJson('/api/siad/otro/get_pedido?codisb=505094939&estado=PEND-FACTURA')->assertNotFound();
        $this->getJson('/api/get_pedido?codisb=505094939&estado=PEND-FACTURA')->assertNotFound();

        config(['siad.tokens' => []]);
        $this->getJson(self::API.'/get_pedido?codisb=505094939&estado=PEND-FACTURA')->assertNotFound();

        config(['siad.abierta' => true]);
        $this->getJson('/api/get_pedido?codisb=505094939&estado=PEND-FACTURA')->assertOk()->assertExactJson([]);
    }

    public function test_get_pedido_superpone_el_despacho_de_sides_sobre_pedido_y_pedren(): void
    {
        $this->pedidoCerrado(500, [
            'despachador' => 'PEDRO', 'embalador' => 'MARIA EMPACADORA', 'recipiente' => 'C9', 'cantBultos' => '3',
            'num_cesta_ped' => '7, 9', 'fecpicking2' => '2026-09-15 09:00:00', 'fecpacking2' => null,
        ]);
        SidesPedrenOperacion::query()->where('id_pedido', 500)->where('item', 1)->update(['lote' => 'L-NUEVO', 'feclote' => '2027-06-01', 'chequeado' => 2]);
        SidesPedrenOperacion::query()->where('id_pedido', 500)->where('item', 2)->update(['lote' => null, 'chequeado' => -1]);
        // Mismo id en otra sede: no se mezcla.
        $this->crearRenglon(500, 9, ['codisb' => 'OTRA']);

        $respuesta = $this->getJson(self::API.'/get_pedido?codisb=505094939&estado=PEND-FACTURA')->assertOk()->json();

        $this->assertCount(1, $respuesta);
        $this->assertSame(500, $respuesta[0]['id']);
        $pedido = $respuesta[0]['pedido'];
        $this->assertSame(
            ['PEDRO', 'MARIA EMPACADORA', 'C9', '3', '7, 9', '2026-09-15 09:00:00', '2020-01-01 00:00:00', 0, 0],
            [$pedido['despachador'], $pedido['embalador'], $pedido['recipiente'], $pedido['cantBultos'], $pedido['num_cesta_ped'],
                $pedido['fecpicking2'], $pedido['fecpacking2'], $pedido['comprometeunidades'], $pedido['pedido_recibido']]
        );

        $renglones = collect($respuesta[0]['pedren'])->keyBy('item');
        $this->assertSame([1, 2], $renglones->keys()->sort()->values()->all());
        $this->assertSame(['L-NUEVO', '2027-06-01', 2, 2, 1], [$renglones[1]['lote'], $renglones[1]['feclote'], $renglones[1]['chequeado'], $renglones[1]['cantdesp'], $renglones[1]['packing']]);
        // Sin dato en la operación queda el de SEPED: el lote original y chequeado (el -1 no se usa).
        $this->assertSame(['L-SEPED', 0], [$renglones[2]['lote'], $renglones[2]['chequeado']]);
        // Texto sin dato sale null, nunca "0".
        $this->assertNull($renglones[2]['feclote']);
    }

    public function test_un_pedido_con_la_operacion_incompleta_no_se_entrega_y_no_bloquea_a_los_demas(): void
    {
        $this->pedidoCerrado(400);
        $this->pedidoCerrado(600);
        DB::table('pedren')->where('id', 600)->where('item', 1)->update(['cantdesp' => 5]);
        $this->crearPedido(['id' => 700, 'estado' => 'PEND-FACTURA']);
        $this->crearRenglon(700, 1);

        $this->getJson(self::API.'/get_pedido?codisb=505094939&estado=PEND-FACTURA')->assertOk()->assertJsonPath('0.id', 400);

        $this->assertTrue(Cache::has('siad:incompleto:505094939:700'));
        $this->assertTrue(Cache::has('siad:incompleto:505094939:600'));
    }

    public function test_sin_sides_v2_no_se_valida_la_operacion(): void
    {
        $this->crearPedido(['id' => 800, 'estado' => 'PEND-FACTURA', 'codisb' => 'SINSIDES']);
        $this->crearRenglon(800, 1, ['codisb' => 'SINSIDES', 'cantdesp' => 2]);

        $this->getJson(self::API.'/get_pedido?codisb=SINSIDES&estado=PEND-FACTURA')
            ->assertOk()->assertJsonPath('0.id', 800)->assertJsonPath('0.pedren.0.cantdesp', 2);
    }

    public function test_get_pedido_recibido_entrega_cada_pedido_una_vez_y_facturando_solo_ids(): void
    {
        $this->crearPedido(['id' => 300, 'estado' => 'RECIBIDO']);
        $this->crearRenglon(300, 1);

        $this->getJson(self::API.'/get_pedido_recibido?codisb=505094939&estado=RECIBIDO')->assertOk()->assertJsonPath('0.id', 300);
        $this->getJson(self::API.'/get_pedido_recibido?codisb=505094939&estado=RECIBIDO')->assertOk()->assertExactJson([]);
        $this->assertSame('RECIBIDO', Pedido::query()->find(300)->estado);

        $this->crearPedido(['id' => 102, 'estado' => 'FACTURANDO']);
        $this->crearPedido(['id' => 101, 'estado' => 'FACTURANDO']);
        $this->getJson(self::API.'/get_pedido_facturando?codisb=505094939&estado=FACTURANDO')
            ->assertOk()->assertExactJson([['id' => 101], ['id' => 102]]);
    }

    public function test_upd_pedido_sigue_el_ciclo_del_siad_y_queda_auditado(): void
    {
        $this->pedidoCerrado(500);

        $this->postJson(self::API.'/upd_pedido', ['id' => '500', 'estado' => 'FACTURANDO', 'numerod' => '101'])
            ->assertOk()->assertExactJson(['status' => 200, 'msg' => 'UPD SATISFACTORIO']);
        $this->assertSame(['FACTURANDO', 'PED ERP: 101'], $this->estadoYDocumento(500));

        // Como lo mandaba el SIDES legacy: lista de un elemento.
        $this->postJson(self::API.'/upd_pedido', [['id' => 500, 'estado' => 'FACTURADO', 'numerod' => 'FACT: 7001']])
            ->assertOk()->assertJson(['status' => 200]);
        $this->assertSame(['FACTURADO', 'PED ERP: 101 FACT: 7001'], $this->estadoYDocumento(500));

        // Un aviso atrasado no devuelve atrás un pedido facturado.
        $this->postJson(self::API.'/upd_pedido', ['id' => 500, 'estado' => 'FACTURANDO', 'numerod' => '101'])
            ->assertOk()->assertExactJson(['status' => 200, 'msg' => 'PEDIDO YA FACTURADO']);
        $this->assertSame(['FACTURADO', 'PED ERP: 101 FACT: 7001'], $this->estadoYDocumento(500));

        $auditoria = SidesAuditoria::query()->where('accion', 'siad.upd_pedido')->orderBy('id')->get();
        $this->assertSame(['SIAD', 'SIAD'], $auditoria->pluck('usuario')->all());
        $this->assertSame('El SIAD cambió el pedido #500 de FACTURANDO a FACTURADO', $auditoria[1]->descripcion);
    }

    public function test_upd_pedido_con_datos_malos_responde_500_en_el_cuerpo(): void
    {
        $this->postJson(self::API.'/upd_pedido', ['id' => 'abc', 'estado' => 'FACTURADO'])
            ->assertOk()->assertExactJson(['status' => 500, 'msg' => 'SOLICITUD INVALIDA']);
        $this->postJson(self::API.'/upd_pedido', ['id' => 999, 'estado' => 'FACTURADO'])
            ->assertOk()->assertExactJson(['status' => 500, 'msg' => 'PEDIDO NO ENCONTRADO']);
    }

    /** Pedido en PEND-FACTURA como lo deja SIDES al cerrar: operación completa y cantdesp igual en pedren. */
    private function pedidoCerrado(int $id, array $operacion = []): void
    {
        $this->crearPedido(['id' => $id, 'estado' => 'PEND-FACTURA'], array_merge(['despachador' => 'ANA'], $operacion));
        foreach ([1 => 'L-SEPED', 2 => 'L-SEPED'] as $item => $lote) {
            $this->crearRenglon($id, $item, ['cantdesp' => 2, 'estado_desp' => 'FACTURADO', 'lote' => $lote]);
            SidesPedrenOperacion::query()->insert([
                'id_pedido' => $id, 'item' => $item, 'codisb' => '505094939', 'cantdesp' => 2, 'chequeado' => 2, 'packing' => 1,
            ]);
        }
    }

    /** @return array{0: string, 1: ?string} */
    private function estadoYDocumento(int $id): array
    {
        $pedido = Pedido::query()->find($id);

        return [$pedido->estado, $pedido->documento];
    }
}
