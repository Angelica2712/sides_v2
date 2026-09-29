<?php

namespace Tests\Feature;

use App\Models\Sides\SidesAuditoria;
use App\Models\Sides\SidesUsers;
use Illuminate\Support\Carbon;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class AuditoriaTest extends TestCase
{
    use TablasSides;

    private SidesUsers $admin;

    private SidesUsers $operario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        Carbon::setTestNow('2026-09-29 10:00:00');

        $this->crearCfg();
        $this->crearCfg(['codisb' => 'SIDES', 'nombre' => 'EMPRESA'], []);
        $this->admin = $this->crearUsuario(['name' => 'Empresa', 'email' => 'admin@example.com', 'codisb' => 'SIDES', 'esAdmin' => 1]);
        $this->operario = $this->crearUsuario(['name' => 'Ana Operaria', 'email' => 'ana@example.com', 'activarPicking' => 1, 'activarPacking' => 1, 'activarUsuario' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pedidoRecibido(int $id = 500): void
    {
        $this->crearPedido(['id' => $id, 'estado' => 'RECIBIDO', 'nomcli' => "CLIENTE {$id}"]);
        $this->crearRenglon($id, 1, ['cantidad' => 3]);
    }

    public function test_tomar_un_pedido_queda_registrado_con_quien_cuando_y_donde(): void
    {
        $this->pedidoRecibido();

        $this->actingAs($this->operario)->post('/picking/500/tomar', ['recipiente' => 'CESTA 5'])->assertRedirect();

        $registro = SidesAuditoria::query()->where('accion', 'picking.tomar')->sole();
        $this->assertSame(
            ['505094939', $this->operario->id, 'ana@example.com', 'Ana Operaria', 'Picking', 'Tomó el pedido #500 para picking', '500', 'OK', 'POST', '/picking/500/tomar', '127.0.0.1'],
            [$registro->codisb, (int) $registro->usuario_id, $registro->usuario, $registro->nombre, $registro->modulo, $registro->descripcion, $registro->referencia, $registro->resultado, $registro->metodo, $registro->ruta, $registro->ip]
        );
        $this->assertSame(['recipiente' => 'CESTA 5'], $registro->datos);
        $this->assertSame('2026-09-29 10:00:00', $registro->fecha->toDateTimeString());
    }

    public function test_una_accion_que_el_sistema_no_deja_hacer_queda_como_fallida_con_su_motivo(): void
    {
        $this->pedidoRecibido();
        $otro = $this->crearUsuario(['name' => 'Luis', 'email' => 'luis@example.com', 'activarPicking' => 1]);
        $this->actingAs($otro)->post('/picking/500/tomar', ['recipiente' => 'X']);

        $this->actingAs($this->operario)->post('/picking/500/tomar', ['recipiente' => 'CESTA 5']);

        $fallido = SidesAuditoria::query()->where('usuario', 'ana@example.com')->sole();
        $this->assertSame(['FALLIDO', 'El pedido #500 ya lo tomó Luis.'], [$fallido->resultado, $fallido->detalle_resultado]);
    }

    public function test_formulario_con_errores_de_validacion_no_se_registra(): void
    {
        $this->actingAs($this->operario)->post('/usuarios', ['name' => '', 'email' => 'no-es-correo'])->assertSessionHasErrors(['name', 'email']);

        $this->assertSame(0, SidesAuditoria::query()->count());
    }

    public function test_acceso_sin_permiso_queda_como_denegado(): void
    {
        $this->actingAs($this->operario)->post('/admin/droguerias', ['codisb' => 'X', 'nombre' => 'X'])->assertForbidden();

        $registro = SidesAuditoria::query()->sole();
        $this->assertSame(['admin.store', 'Administración', 'DENEGADO'], [$registro->accion, $registro->modulo, $registro->resultado]);
    }

    public function test_nunca_guarda_contrasenas_ni_claves(): void
    {
        $empleado = $this->crearUsuario(['name' => 'Empleado', 'email' => 'empleado@example.com']);

        $this->actingAs($this->operario)->put("/usuarios/{$empleado->id}/clave", [
            'password' => 'MuySecreta123',
            'password_confirmation' => 'MuySecreta123',
        ]);

        $registro = SidesAuditoria::query()->where('accion', 'usuarios.clave')->sole();
        $this->assertSame(['password' => '***', 'password_confirmation' => '***'], $registro->datos);
        $this->assertStringNotContainsString('MuySecreta123', json_encode(SidesAuditoria::query()->get()->toArray()));
    }

    public function test_las_lecturas_del_escaner_una_por_una_no_se_registran(): void
    {
        $this->crearPedido(['id' => 700, 'estado' => 'PACKING'], ['embalador' => 'Ana Operaria', 'recipiente' => 'C1']);
        $this->crearRenglon(700, 1, ['cantidad' => 2, 'barra' => '759111']);

        $this->actingAs($this->operario)->postJson('/packing/700/escanear', ['item' => 1]);

        $this->assertSame(0, SidesAuditoria::query()->where('accion', 'packing.escanear')->count());
    }

    public function test_registra_entradas_salidas_e_intentos_fallidos_sin_la_contrasena(): void
    {
        $this->post('/login', ['email' => 'ana@example.com', 'password' => 'clave-equivocada']);
        $this->post('/login', ['email' => 'nadie@example.com', 'password' => 'otra-clave']);
        $this->post('/login', ['email' => 'ana@example.com', 'password' => 'secreto123'])->assertRedirect(route('home'));
        $this->post('/logout');

        $registros = SidesAuditoria::query()->orderBy('id')->get(['accion', 'usuario', 'codisb', 'resultado', 'detalle_resultado']);
        $this->assertSame([
            ['sesion.fallida', 'ana@example.com', '505094939', 'FALLIDO', 'Contraseña incorrecta o usuario inactivo'],
            ['sesion.fallida', 'nadie@example.com', null, 'FALLIDO', 'El correo no existe'],
            ['sesion.entrar', 'ana@example.com', '505094939', 'OK', null],
            ['sesion.salir', 'ana@example.com', '505094939', 'OK', null],
        ], $registros->map(fn ($r) => [$r->accion, $r->usuario, $r->codisb, $r->resultado, $r->detalle_resultado])->all());
        $this->assertStringNotContainsString('clave-equivocada', json_encode(SidesAuditoria::query()->get()->toArray()));
    }

    public function test_solo_el_administrador_ve_la_auditoria_de_todas_las_droguerias(): void
    {
        SidesAuditoria::query()->create(['fecha' => '2026-09-28 09:00:00', 'codisb' => '505094939', 'usuario' => 'ana@example.com', 'nombre' => 'Ana Operaria', 'modulo' => 'Pedidos', 'accion' => 'pedidos.anular', 'descripcion' => 'Anuló el pedido #111', 'referencia' => '111', 'resultado' => 'OK']);
        SidesAuditoria::query()->create(['fecha' => '2026-09-28 10:00:00', 'codisb' => 'OTRA', 'usuario' => 'luis@otra.com', 'nombre' => 'Luis', 'modulo' => 'Picking', 'accion' => 'picking.tomar', 'descripcion' => 'Tomó el pedido #222 para picking', 'referencia' => '222', 'resultado' => 'FALLIDO']);
        SidesAuditoria::query()->create(['fecha' => '2026-08-01 10:00:00', 'codisb' => '505094939', 'usuario' => 'ana@example.com', 'modulo' => 'Pedidos', 'accion' => 'pedidos.anular', 'descripcion' => 'Anuló el pedido #333 (fuera de rango)', 'resultado' => 'OK']);

        $this->actingAs($this->operario)->get('/admin/auditoria')->assertForbidden();
        $this->get('/home')->assertDontSee('Auditoría');

        // En el menú lateral del administrador, sección Ajustes.
        $this->actingAs($this->admin)->get('/home')->assertSeeInOrder(['Ajustes', 'Administración', 'Auditoría']);

        $this->actingAs($this->admin)->get('/admin/auditoria')
            ->assertOk()
            ->assertSeeInOrder(['Tomó el pedido #222 para picking', 'Anuló el pedido #111'])
            ->assertDontSee('fuera de rango');

        $this->get('/admin/auditoria?codisb=505094939')->assertSee('Anuló el pedido #111')->assertDontSee('#222');
        $this->get('/admin/auditoria?resultado=FALLIDO')->assertSee('#222')->assertDontSee('#111');
        $this->get('/admin/auditoria?usuario=luis')->assertSee('#222')->assertDontSee('#111');
        $this->get('/admin/auditoria?buscar=111')->assertSee('#111')->assertDontSee('#222');
        $this->get('/admin/auditoria?desde=2026-08-01&hasta=2026-08-01')->assertSee('fuera de rango');
    }
}
