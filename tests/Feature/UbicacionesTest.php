<?php

namespace Tests\Feature;

use App\Models\Sides\SidesAlmacenCfg;
use App\Models\Sides\SidesPedrenOperacion;
use App\Models\Sides\SidesProductoUbicacion;
use App\Models\Sides\SidesUsers;
use App\Support\ConfigAlmacen;
use App\Support\MenuSides;
use App\Support\UbicacionAlmacen;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class UbicacionesTest extends TestCase
{
    use TablasSides;

    private SidesUsers $registrador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();
        $this->crearCfg();
        $this->registrador = $this->crearUsuario(['activarAlmacen' => 1, 'name' => 'Luis', 'email' => 'luis@example.com', 'activarUbicaciones' => 1]);

        DB::table('producto')->insert([
            ['codprod' => 'A1', 'codisb' => '505094939', 'desprod' => 'ACETAMINOFEN 500', 'barra' => '7701', 'ubicacion' => 'A01PICK24'],
            ['codprod' => 'L5', 'codisb' => '505094939', 'desprod' => 'LOSARTAN 50', 'barra' => '7702', 'ubicacion' => 'PK-02-01-A-01'],
            ['codprod' => 'X9', 'codisb' => '999', 'desprod' => 'ACETAMINOFEN OTRA', 'barra' => '7709', 'ubicacion' => ''],
        ]);
    }

    private function guardar(string $codprod, string $ubicacion, ?SidesUsers $usuario = null)
    {
        return $this->actingAs($usuario ?? $this->registrador)
            ->putJson('/almacen/ubicaciones/producto', ['codprod' => $codprod, 'ubicacion' => $ubicacion]);
    }

    public function test_el_permiso_da_la_pantalla_y_la_da_tambien_quien_administra_la_drogueria(): void
    {
        $this->actingAs($this->registrador)->get('/almacen/ubicaciones')
            ->assertOk()
            ->assertSee('Ubicaciones')
            ->assertSee(route('almacen.acomodo'));

        $encargado = $this->crearUsuario(['activarAlmacen' => 1, 'email' => 'enc@example.com', 'activarConfig' => 1]);
        $this->actingAs($encargado)->get('/almacen/ubicaciones')->assertOk();

        $operario = $this->crearUsuario(['activarAlmacen' => 1, 'email' => 'op@example.com', 'activarPicking' => 1]);
        $this->actingAs($operario)->get('/almacen/ubicaciones')->assertForbidden();
        $this->actingAs($operario)->putJson('/almacen/ubicaciones/producto', ['codprod' => 'A1', 'ubicacion' => 'PK-01-01'])->assertForbidden();
    }

    public function test_va_en_pestanas_dentro_del_almacen_3d(): void
    {
        // Sin menú propio: con solo este permiso se entra por el Almacén 3D.
        $menu = array_column(MenuSides::visibles($this->registrador, $this->registrador->cfg), 'clave');
        $this->assertContains('almacen', $menu);
        $this->assertNotContains('ubicaciones', $menu);

        $this->actingAs($this->registrador)->get('/almacen')
            ->assertOk()
            ->assertSee(route('almacen.ubicaciones'))
            ->assertSee(route('almacen.acomodo'))
            // Con el permiso, la caja del producto se arrastra y se guarda.
            ->assertSee('agarra la caja naranja');

        // Quien solo recoge ve el almacén, sin pestañas ni arrastre.
        $operario = $this->crearUsuario(['activarAlmacen' => 1, 'email' => 'op@example.com', 'activarPicking' => 1]);
        $this->actingAs($operario)->get('/almacen')
            ->assertOk()
            ->assertDontSee(route('almacen.ubicaciones'))
            ->assertDontSee('agarra la caja naranja');
    }

    public function test_el_permiso_sale_en_el_formulario_de_usuarios(): void
    {
        $admin = $this->crearUsuario(['email' => 'adm@example.com', 'activarUsuario' => 1]);

        $this->actingAs($admin)->get('/usuarios/nuevo')->assertOk()->assertSee('Registrar ubicaciones')->assertSee('activarUbicaciones');
    }

    public function test_sigue_al_modulo_almacen_de_la_drogueria(): void
    {
        $cfg = $this->registrador->cfg;
        $this->assertTrue(MenuSides::puede($this->registrador, $cfg, 'ubicaciones'));

        DB::table('sides_modulo_sucursal')->insert(['codisb' => '505094939', 'modulo' => 'almacen', 'activo' => 0]);

        $this->assertFalse(MenuSides::puede($this->registrador, $cfg->fresh(), 'ubicaciones'));
    }

    public function test_registra_la_ubicacion_de_un_producto(): void
    {
        $respuesta = $this->guardar('A1', ' pk-03-12-b-04 ; my-02-03 ')->assertOk()->json();

        $this->assertSame('PK-03-12-B-04;MY-02-03', $respuesta['producto']['sides']);
        $this->assertSame('A01PICK24', $respuesta['producto']['erp']);
        $this->assertSame('Luis', $respuesta['recientes'][0]['usuario']);

        $fila = SidesProductoUbicacion::query()->where('codisb', '505094939')->where('codprod', 'A1')->first();
        $this->assertSame('PK-03-12-B-04;MY-02-03', $fila->ubicacion);
        $this->assertSame($this->registrador->id, (int) $fila->usuario_id);
        // La del ERP no se toca.
        $this->assertSame('A01PICK24', DB::table('producto')->where('codprod', 'A1')->value('ubicacion'));

        // Volver a guardar la cambia, no duplica.
        $this->guardar('A1', 'PK-03-12-B-05')->assertOk();
        $this->assertSame(1, SidesProductoUbicacion::query()->count());
        $this->assertSame('PK-03-12-B-05', SidesProductoUbicacion::query()->value('ubicacion'));
    }

    public function test_rechaza_ubicaciones_fuera_del_formato(): void
    {
        $this->guardar('A1', 'PK-3-12')->assertUnprocessable()->assertJsonValidationErrors('ubicacion');
        $this->guardar('A1', 'PK-03-12;pasillo raro')->assertUnprocessable()->assertJsonValidationErrors('ubicacion');
        $this->guardar('A1', ';')->assertUnprocessable()->assertJsonValidationErrors('ubicacion');

        $this->assertSame(0, SidesProductoUbicacion::query()->count());
    }

    public function test_quien_administra_la_drogueria_arma_su_formato_y_se_registra_con_el(): void
    {
        $formato = ['separador' => '.', 'partes' => [
            ['rol' => 'pasillo', 'nombre' => 'Pasillo', 'tipo' => 'numeros', 'largo' => 2],
            ['rol' => 'anaquel', 'nombre' => 'Rack', 'tipo' => 'numeros', 'largo' => 2],
            ['rol' => 'estante', 'nombre' => 'Nivel', 'tipo' => 'numeros', 'largo' => 1],
        ]];

        // Quien solo registra ubicaciones no lo cambia: vale para toda la droguería.
        $this->actingAs($this->registrador)->putJson('/almacen/ubicaciones/formato', $formato)->assertForbidden();
        $this->actingAs($this->registrador)->get('/almacen/ubicaciones')->assertOk()->assertDontSee('Cambiar el formato');

        $encargado = $this->crearUsuario(['activarAlmacen' => 1, 'email' => 'enc@example.com', 'activarConfig' => 1]);
        $this->actingAs($encargado)->get('/almacen/ubicaciones')->assertOk()->assertSee('Cambiar el formato');
        $this->actingAs($encargado)->putJson('/almacen/ubicaciones/formato', $formato)
            ->assertOk()
            ->assertJsonPath('mensaje', 'Formato guardado: PASILLO.RACK.NIVEL, por ejemplo 03.12.2.');
        $this->assertSame($formato, ConfigAlmacen::formatoDe('505094939'));

        // Desde ahora se registra con ese formato y el de SIDES se rechaza.
        $this->guardar('A1', '03.12.2')->assertOk()->assertJsonPath('producto.sides', '03.12.2');
        $this->guardar('L5', 'PK-03-12-B-04')->assertUnprocessable()
            ->assertJsonPath('errors.ubicacion.0', 'La ubicación «PK-03-12-B-04» no sigue el formato PASILLO.RACK.NIVEL (por ejemplo 03.12.2).');
        $this->assertSame(['03.12.2'], $this->actingAs($this->registrador)->getJson('/almacen/buscar?q=7701')->json('productos.0.ubicaciones'));

        // Guardar o restablecer el acomodo no lo pierde.
        $this->actingAs($encargado)->put('/almacen/acomodo', ['numeracion' => 'u', 'estanteA' => 'abajo', 'posicion1' => 'izquierda', 'estantes' => 4, 'posiciones' => 4]);
        $this->assertSame('u', ConfigAlmacen::de('505094939')['numeracion']);
        $this->assertSame($formato, ConfigAlmacen::formatoDe('505094939'));
        $this->actingAs($encargado)->delete('/almacen/acomodo');
        $this->assertSame('alternada', ConfigAlmacen::de('505094939')['numeracion']);
        $this->assertSame($formato, ConfigAlmacen::formatoDe('505094939'));
    }

    public function test_rechaza_un_formato_que_no_sirve(): void
    {
        $encargado = $this->crearUsuario(['activarAlmacen' => 1, 'email' => 'enc@example.com', 'activarConfig' => 1]);

        $this->actingAs($encargado)->putJson('/almacen/ubicaciones/formato', ['separador' => '-', 'partes' => [
            ['rol' => 'pasillo', 'nombre' => 'Pasillo', 'tipo' => 'numeros', 'largo' => 2],
            ['rol' => 'estante', 'nombre' => 'Nivel', 'tipo' => 'letras', 'largo' => 1],
        ]])->assertUnprocessable()->assertJsonValidationErrors('formato');

        $this->assertSame(UbicacionAlmacen::FORMATO_SIDES, ConfigAlmacen::formatoDe('505094939'));
    }

    public function test_solo_productos_de_su_drogueria(): void
    {
        $this->guardar('X9', 'PK-01-01')->assertUnprocessable()->assertJsonValidationErrors('codprod');
        $this->guardar('NOEXISTE', 'PK-01-01')->assertUnprocessable()->assertJsonValidationErrors('codprod');

        $this->assertSame(0, SidesProductoUbicacion::query()->count());
    }

    public function test_quitar_vuelve_a_la_del_erp(): void
    {
        $this->guardar('L5', 'MY-01-01-A-01');

        $respuesta = $this->actingAs($this->registrador)->deleteJson('/almacen/ubicaciones/producto', ['codprod' => 'L5'])->assertOk()->json();

        $this->assertSame('', $respuesta['producto']['sides']);
        $this->assertSame('PK-02-01-A-01', $respuesta['producto']['erp']);
        $this->assertSame(0, SidesProductoUbicacion::query()->count());
    }

    public function test_busca_con_las_dos_ubicaciones_y_el_codigo_exacto_primero(): void
    {
        $this->guardar('A1', 'PK-03-12-B-04');

        $datos = $this->actingAs($this->registrador)->getJson('/almacen/ubicaciones/buscar?q=7701')->assertOk()->json();
        $this->assertTrue($datos['exacto']);
        $this->assertSame(['A1'], array_column($datos['productos'], 'codprod'));
        $this->assertSame('PK-03-12-B-04', $datos['productos'][0]['sides']);
        $this->assertSame('A01PICK24', $datos['productos'][0]['erp']);
        $this->assertSame('Luis', $datos['productos'][0]['usuario']);

        // Solo el catálogo de su droguería.
        $porTexto = $this->actingAs($this->registrador)->getJson('/almacen/ubicaciones/buscar?q=acetaminofen')->json();
        $this->assertFalse($porTexto['exacto']);
        $this->assertSame(['A1'], array_column($porTexto['productos'], 'codprod'));
    }

    public function test_la_de_sides_gana_en_el_almacen_3d(): void
    {
        $this->guardar('A1', 'FR-01-02-A-01');
        $this->guardar('L5', 'FR-01-02-A-02');
        $operario = $this->crearUsuario(['activarAlmacen' => 1, 'email' => 'op@example.com', 'activarPicking' => 1]);

        $plano = $this->actingAs($operario)->getJson('/almacen/plano')->json();
        $this->assertSame(['FR'], array_column($plano['zonas'], 'zona'));
        $this->assertSame(2, $plano['reconocidas']);

        $productos = $this->actingAs($operario)->getJson('/almacen/buscar?q=7702')->json('productos');
        $this->assertSame(['FR-01-02-A-02'], $productos[0]['ubicaciones']);
    }

    public function test_la_de_sides_gana_en_el_picking(): void
    {
        $this->crearPedido(['id' => 700, 'estado' => 'RECIBIDO']);
        $this->crearRenglon(700, 1, ['codprod' => 'A1', 'ubicacion' => 'A01PICK24']);
        $this->crearRenglon(700, 2, ['codprod' => 'L5', 'ubicacion' => 'PK-02-01-A-01']);
        $this->guardar('A1', 'PK-01-01-A-01');

        $operario = $this->crearUsuario(['activarAlmacen' => 1, 'email' => 'op@example.com', 'activarPicking' => 1]);
        $this->actingAs($operario)->post('/picking/700/tomar', ['recipiente' => 'C1']);

        // Al tomar el pedido se copia la de SIDES en la operación.
        $this->assertSame('PK-01-01-A-01', SidesPedrenOperacion::query()->where('id_pedido', 700)->where('item', 1)->value('ubicacion'));
        $this->assertSame('PK-02-01-A-01', SidesPedrenOperacion::query()->where('id_pedido', 700)->where('item', 2)->value('ubicacion'));

        // Si se registra mientras el pedido está en picking, el operario ya ve la nueva.
        $this->guardar('L5', 'MY-09-09');
        $renglones = collect($this->actingAs($operario)->get('/picking/700')->assertOk()->viewData('renglones'));
        $this->assertSame(['PK-01-01-A-01', 'MY-09-09'], $renglones->sortBy('item')->pluck('ubicacion')->values()->all());
    }

    public function test_la_drogueria_registra_sus_pasillos_y_anaqueles(): void
    {
        $this->actingAs($this->registrador)->get('/almacen/acomodo')
            ->assertOk()
            ->assertSee('Ajustes avanzados del almacén')
            ->assertSee(route('almacen.acomodo.update'));

        $this->actingAs($this->registrador)->put('/almacen/acomodo', [
            'numeracion' => 'u',
            'estanteA' => 'abajo',
            'posicion1' => 'izquierda',
            'estantes' => 5,
            'posiciones' => 4,
            // El editor manda la lista de pasillos entera; lo que no sea válido se descarta.
            'pasillos' => json_encode([
                'PK-01' => ['izquierda' => 2, 'derecha' => 1, 'estantes' => null, 'posiciones' => null],
                'MY-02' => ['izquierda' => 1, 'derecha' => 1, 'estantes' => 3, 'posiciones' => 6],
                'pk-03' => ['izquierda' => 1, 'derecha' => 1],
                'FR-00' => ['izquierda' => 1, 'derecha' => 1],
            ]),
        ])->assertRedirect('/almacen/acomodo')->assertSessionHasNoErrors();

        $config = ConfigAlmacen::de('505094939');
        $this->assertSame('u', $config['numeracion']);
        $this->assertSame([
            'MY-02' => ['izquierda' => 1, 'derecha' => 1, 'estantes' => 3, 'posiciones' => 6],
            'PK-01' => ['izquierda' => 2, 'derecha' => 1, 'estantes' => null, 'posiciones' => null],
        ], $config['pasillos']);
        $this->assertSame('luis@example.com', SidesAlmacenCfg::query()->find('505094939')->actualizado_por);

        // El plano dibuja los anaqueles registrados aunque no tengan productos.
        $operario = $this->crearUsuario(['activarAlmacen' => 1, 'email' => 'op@example.com', 'activarPicking' => 1]);
        $plano = $this->actingAs($operario)->getJson('/almacen/plano')->json();
        $pk = collect($plano['zonas'])->firstWhere('zona', 'PK');
        $this->assertSame([1, 2], array_column($pk['pasillos'], 'pasillo'));
        $this->assertSame([1, 2, 3], array_column($pk['pasillos'][0]['anaqueles'], 'anaquel'));
        $this->assertSame(5, $pk['pasillos'][0]['anaqueles'][0]['estantes']);
        $my = collect($plano['zonas'])->firstWhere('zona', 'MY')['pasillos'][0]['anaqueles'];
        $this->assertSame([1, 2], array_column($my, 'anaquel'));
        // El pasillo con medidas propias no usa las generales.
        $this->assertSame([3, 6], [$my[0]['estantes'], $my[0]['posiciones']]);
    }

    public function test_borra_anaqueles_del_plano_salvo_los_que_tienen_productos(): void
    {
        // PK-01 registrado con 3 a la izquierda (01, 03, 05) y 2 a la derecha (02, 04); un producto en el 03.
        $this->guardar('A1', 'PK-01-03-A-01');
        $acomodo = fn (array $quitados) => $this->actingAs($this->registrador)->put('/almacen/acomodo', [
            'numeracion' => 'alternada', 'estanteA' => 'abajo', 'posicion1' => 'izquierda', 'estantes' => 4, 'posiciones' => 4,
            'pasillos' => json_encode(['PK-01' => ['izquierda' => 3, 'derecha' => 2]]),
            'disposicion' => json_encode(['PK-01-04' => ['x' => 1, 'z' => 1, 'giro' => 0]]),
            'quitados' => json_encode($quitados),
        ]);

        // MY-09 no es un pasillo registrado: su borrado no se guarda.
        $acomodo(['PK-01-04', 'PK-01-03', 'basura', 'MY-09-01'])->assertSessionHasNoErrors();
        $this->assertSame(['PK-01-03', 'PK-01-04'], ConfigAlmacen::de('505094939')['quitados']);

        $plano = $this->actingAs($this->registrador)->getJson('/almacen/plano')->json();
        // El 04 ya no está; el 03 sigue porque tiene un producto.
        $this->assertSame([1, 2, 3, 5], array_column($plano['zonas'][0]['pasillos'][0]['anaqueles'], 'anaquel'));
        // LOSARTAN ya venía del ERP en PK-02-01.
        $this->assertSame(['PK-01-03', 'PK-02-01'], $plano['ocupados']);

        // El editor recibe también los borrados, para poder volver a ponerlos.
        $this->actingAs($this->registrador)->get('/almacen/acomodo')
            ->assertOk()
            ->assertSee('Borrar anaquel')
            ->assertViewHas('plano', fn (array $plano) => count($plano['zonas'][0]['pasillos'][0]['anaqueles']) === 5);

        // Volver a contar el pasillo en el asistente lo deja completo.
        $this->actingAs($this->registrador)->putJson('/almacen/armar/pasillo', [
            'zona' => 'PK', 'pasillo' => 1, 'izquierda' => 3, 'derecha' => 2, 'estantes' => 4, 'posiciones' => 4,
        ])->assertOk();
        $this->assertSame([], ConfigAlmacen::de('505094939')['quitados']);
    }

    public function test_anaqueles_numerados_a_mano_en_el_3d(): void
    {
        // PK-01 registrado (01, 03, 02); en el 3D el 03 pasó a ser el 15 y se agregó PK-04-02 suelto.
        $this->actingAs($this->registrador)->put('/almacen/acomodo', [
            'numeracion' => 'alternada', 'estanteA' => 'abajo', 'posicion1' => 'izquierda', 'estantes' => 4, 'posiciones' => 4,
            'pasillos' => json_encode(['PK-01' => ['izquierda' => 2, 'derecha' => 1, 'estantes' => 6, 'posiciones' => 5]]),
            'quitados' => json_encode(['PK-01-03']),
            'extras' => json_encode(['PK-01-15', 'PK-04-02', 'PK-00-01', 'malo']),
            'disposicion' => json_encode(['PK-01-15' => ['x' => 3, 'z' => 1, 'giro' => 0]]),
        ])->assertSessionHasNoErrors();

        $config = ConfigAlmacen::de('505094939');
        $this->assertSame(['PK-01-15', 'PK-04-02'], $config['extras']);

        $plano = $this->actingAs($this->registrador)->getJson('/almacen/plano')->json();
        $pk = collect($plano['zonas'])->firstWhere('zona', 'PK')['pasillos'];
        $this->assertSame([1, 2, 4], array_column($pk, 'pasillo'));
        // El 15 suelto toma las medidas de su pasillo registrado; el de PK-04, las generales.
        $this->assertSame([1, 2, 15], array_column($pk[0]['anaqueles'], 'anaquel'));
        $this->assertSame([6, 5], [$pk[0]['anaqueles'][2]['estantes'], $pk[0]['anaqueles'][2]['posiciones']]);
        $this->assertSame([2], array_column($pk[2]['anaqueles'], 'anaquel'));

        // El editor recibe aparte lo que sale de los productos, para armar el plano al momento.
        $this->actingAs($this->registrador)->get('/almacen/acomodo')
            ->assertOk()
            ->assertViewHas('plano', fn (array $plano) => array_column($plano['productos'], 'zona') === ['PK']
                && array_column($plano['productos'][0]['pasillos'], 'pasillo') === [2]);

        // Volver a contar el pasillo en el asistente lo deja como se contó, sin sueltos de antes.
        $this->actingAs($this->registrador)->putJson('/almacen/armar/pasillo', [
            'zona' => 'PK', 'pasillo' => 1, 'izquierda' => 2, 'derecha' => 1, 'estantes' => 4, 'posiciones' => 4,
        ])->assertOk();
        $this->assertSame(['PK-04-02'], ConfigAlmacen::de('505094939')['extras']);
    }

    public function test_guarda_las_entradas_del_almacen(): void
    {
        $this->actingAs($this->registrador)->put('/almacen/acomodo', [
            'numeracion' => 'alternada', 'estanteA' => 'abajo', 'posicion1' => 'izquierda', 'estantes' => 4, 'posiciones' => 4,
            'entradas' => json_encode([
                ['x' => -2.456, 'z' => 3, 'giro' => 450, 'nombre' => '  Carga  '],
                ['x' => 10, 'z' => 'lejos'],
                ['x' => 1, 'z' => 0, 'giro' => 180],
            ]),
        ])->assertSessionHasNoErrors();

        $this->assertSame([
            ['x' => -2.46, 'z' => 3.0, 'giro' => 90, 'nombre' => 'Carga'],
            ['x' => 1.0, 'z' => 0.0, 'giro' => 180, 'nombre' => ''],
        ], ConfigAlmacen::de('505094939')['entradas']);

        // El 3D las recibe en el plano.
        $plano = $this->actingAs($this->registrador)->getJson('/almacen/plano')->json();
        $this->assertSame('Carga', $plano['config']['entradas'][0]['nombre']);

        $this->actingAs($this->registrador)->get('/almacen/acomodo')->assertOk()->assertSee('+ Entrada');
    }

    public function test_el_asistente_arma_el_almacen_pasillo_por_pasillo(): void
    {
        // Tenía otra numeración: el asistente deja la de SIDES y lo avisa.
        SidesAlmacenCfg::query()->create(['codisb' => '505094939', 'config' => ['numeracion' => 'u', 'estantes' => 6]]);

        $this->actingAs($this->registrador)->get('/almacen/armar')
            ->assertOk()
            ->assertSee('Armar el almacén')
            ->assertSee('¿Cuántos anaqueles hay a tu izquierda?');

        $pasillo = fn (array $datos) => $this->actingAs($this->registrador)->putJson('/almacen/armar/pasillo', [
            'zona' => 'PK', 'pasillo' => 1, 'izquierda' => 3, 'derecha' => 2, 'estantes' => 5, 'posiciones' => 4, ...$datos,
        ]);

        $pasillo([])->assertOk()->assertJsonPath('pasillos.0.clave', 'PK-01');
        $respuesta = $pasillo(['zona' => 'qx', 'nombreZona' => 'Quirúrgicos', 'izquierda' => 0, 'derecha' => 4, 'estantes' => 3, 'posiciones' => 8])->assertOk()->json();
        $this->assertSame('Quirúrgicos', $respuesta['zonas']['QX']);

        $config = ConfigAlmacen::de('505094939');
        $this->assertSame(['alternada', 'abajo', 'izquierda'], [$config['numeracion'], $config['estanteA'], $config['posicion1']]);
        $this->assertSame(6, $config['estantes']);
        $this->assertSame(['izquierda' => 3, 'derecha' => 2, 'estantes' => 5, 'posiciones' => 4], $config['pasillos']['PK-01']);

        // El plano: impares a la izquierda, pares a la derecha, con las medidas de cada pasillo.
        $plano = $this->actingAs($this->registrador)->getJson('/almacen/plano')->json();
        $pk = collect($plano['zonas'])->firstWhere('zona', 'PK')['pasillos'][0]['anaqueles'];
        $this->assertSame([1, 2, 3, 4, 5], array_column($pk, 'anaquel'));
        $this->assertSame([5, 4], [$pk[0]['estantes'], $pk[0]['posiciones']]);
        $qx = collect($plano['zonas'])->firstWhere('zona', 'QX')['pasillos'][0]['anaqueles'];
        $this->assertSame([2, 4, 6, 8], array_column($qx, 'anaquel'));

        // Editar cambiando el número del pasillo reemplaza el anterior.
        $pasillo(['pasillo' => 3, 'antes' => 'PK-01'])->assertOk();
        $this->assertSame(['PK-03', 'QX-01'], array_keys(ConfigAlmacen::de('505094939')['pasillos']));

        $this->actingAs($this->registrador)->deleteJson('/almacen/armar/pasillo', ['clave' => 'QX-01'])
            ->assertOk()->assertJsonCount(1, 'pasillos');
    }

    public function test_el_asistente_valida_lo_contado(): void
    {
        $this->actingAs($this->registrador)->putJson('/almacen/armar/pasillo', [
            'zona' => 'PK', 'pasillo' => 1, 'izquierda' => 0, 'derecha' => 0, 'estantes' => 4, 'posiciones' => 4,
        ])->assertUnprocessable()->assertJsonValidationErrors('izquierda');

        $this->actingAs($this->registrador)->putJson('/almacen/armar/pasillo', [
            'zona' => 'P1', 'pasillo' => 100, 'izquierda' => 60, 'derecha' => 1, 'estantes' => 0, 'posiciones' => 4,
        ])->assertUnprocessable()->assertJsonValidationErrors(['zona', 'pasillo', 'izquierda', 'estantes']);

        $this->assertNull(SidesAlmacenCfg::query()->find('505094939'));

        $operario = $this->crearUsuario(['activarAlmacen' => 1, 'email' => 'op@example.com', 'activarPicking' => 1]);
        $this->actingAs($operario)->get('/almacen/armar')->assertForbidden();
    }

    public function test_el_acomodo_valida_los_pasillos_y_solo_toca_su_drogueria(): void
    {
        $this->actingAs($this->registrador)->put('/almacen/acomodo', [
            'numeracion' => 'alternada', 'estanteA' => 'abajo', 'posicion1' => 'izquierda', 'estantes' => 4, 'posiciones' => 4,
            'pasillos' => 'esto no es json',
            'extras' => '[1,',
        ])->assertSessionHasErrors(['pasillos', 'extras']);
        $this->assertNull(SidesAlmacenCfg::query()->find('505094939'));

        $this->crearCfg(['codisb' => '999']);
        $this->actingAs($this->registrador)->put('/almacen/acomodo', [
            'numeracion' => 'u', 'estanteA' => 'abajo', 'posicion1' => 'izquierda', 'estantes' => 4, 'posiciones' => 4,
        ]);
        $this->assertNull(SidesAlmacenCfg::query()->find('999'));

        $this->actingAs($this->registrador)->delete('/almacen/acomodo')->assertRedirect('/almacen/acomodo');
        $this->assertNull(SidesAlmacenCfg::query()->find('505094939'));
    }
}
