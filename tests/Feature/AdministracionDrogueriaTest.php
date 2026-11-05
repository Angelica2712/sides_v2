<?php

namespace Tests\Feature;

use App\Models\Sides\SidesUsers;
use App\Models\Sides\SidesWebhook;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

/** El permiso «Administración» (activarAdmin): Administración solo de la propia droguería. */
class AdministracionDrogueriaTest extends TestCase
{
    use TablasSides;

    private SidesUsers $encargado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();

        $this->crearCfg(['codisb' => 'UNO', 'nombre' => 'Droguería Uno']);
        $this->crearCfg(['codisb' => 'DOS', 'nombre' => 'Droguería Dos']);
        $this->encargado = $this->crearUsuario(['name' => 'Encargado', 'email' => 'jefe@uno.test', 'codisb' => 'UNO', 'activarAdmin' => 1, 'activarUsuario' => 1]);
    }

    public function test_con_el_permiso_ve_administracion_solo_de_su_drogueria(): void
    {
        $this->actingAs($this->encargado)->get('/home')->assertSee('Administración');

        $this->get('/admin')->assertOk()
            ->assertSee('Tu droguería')->assertSee('Droguería Uno')
            ->assertDontSee('Droguería Dos')->assertDontSee('Nueva droguería')->assertDontSee('Auditoría');
        $this->get('/admin/droguerias/UNO')->assertOk()
            ->assertSee('los cambia FULLTECH360')->assertDontSee('Guardar cambios')->assertDontSee('Crear encargado');
        $this->get('/admin/droguerias/UNO/almacen')->assertOk();
        $this->get('/admin/droguerias/UNO/webhooks')->assertOk();

        // Otra droguería no existe para él.
        $this->get('/admin/droguerias/DOS')->assertNotFound();
        $this->get('/admin/droguerias/DOS/almacen')->assertNotFound();
        $this->get('/admin/droguerias/DOS/webhooks')->assertNotFound();
        $this->get('/admin/auditoria')->assertForbidden();
    }

    public function test_sin_el_permiso_no_entra(): void
    {
        $operario = $this->crearUsuario(['email' => 'op@uno.test', 'codisb' => 'UNO', 'activarConfig' => 1, 'activarUsuario' => 1]);

        $this->actingAs($operario)->get('/admin')->assertForbidden();
        $this->get('/home')->assertDontSee('Administración');
    }

    public function test_no_cambia_modulos_datos_ni_logo_ni_crea_droguerias(): void
    {
        $this->actingAs($this->encargado);

        $this->put('/admin/droguerias/UNO', ['nombre' => 'Otro nombre', 'modulos' => ['batch']])->assertForbidden();
        $this->post('/admin/droguerias', ['codisb' => 'TRES', 'nombre' => 'Tres'])->assertForbidden();
        $this->get('/admin/droguerias/nueva')->assertForbidden();
        $this->post('/admin/droguerias/UNO/encargado', ['name' => 'X', 'email' => 'x@uno.test'])->assertForbidden();

        $this->assertDatabaseHas('sides_cfg', ['codisb' => 'UNO', 'nombre' => 'Droguería Uno']);
        $this->assertDatabaseMissing('sides_cfg', ['codisb' => 'TRES']);
    }

    public function test_cambia_contrasenas_solo_de_su_drogueria(): void
    {
        $suyo = $this->crearUsuario(['email' => 'op@uno.test', 'codisb' => 'UNO']);
        $ajeno = $this->crearUsuario(['email' => 'op@dos.test', 'codisb' => 'DOS']);

        $this->actingAs($this->encargado)
            ->post("/admin/droguerias/UNO/usuarios/{$suyo->id}/clave", ['password' => 'nueva123'])
            ->assertRedirect(route('admin.edit', 'UNO'));
        $this->assertTrue(Hash::check('nueva123', $suyo->fresh()->password));

        $this->post("/admin/droguerias/DOS/usuarios/{$ajeno->id}/clave", ['password' => 'nueva123'])->assertNotFound();
        $this->post("/admin/droguerias/UNO/usuarios/{$ajeno->id}/clave", ['password' => 'nueva123'])->assertNotFound();
        $this->assertFalse(Hash::check('nueva123', $ajeno->fresh()->password));
    }

    public function test_no_toca_webhooks_de_otra_drogueria(): void
    {
        $ajeno = SidesWebhook::query()->create(['codisb' => 'DOS', 'nombre' => 'App de Dos', 'url' => 'https://dos.test/hook', 'secreto' => 'whsec_dos', 'activo' => true]);

        $this->actingAs($this->encargado)->delete("/admin/webhooks/{$ajeno->id}")->assertNotFound();
        $this->assertDatabaseHas('sides_webhooks', ['id' => $ajeno->id]);
    }

    public function test_el_permiso_lo_da_y_lo_quita_solo_quien_lo_tiene(): void
    {
        $operario = $this->crearUsuario(['name' => 'Operario', 'email' => 'op@uno.test', 'codisb' => 'UNO']);
        $datos = fn (array $permisos) => ['name' => 'Operario', 'email' => 'op@uno.test', 'estado' => 'ACTIVO', 'permisos' => $permisos];

        // Quien lo tiene lo da y lo quita, como cualquier otro permiso.
        $this->actingAs($this->encargado)->put("/usuarios/{$operario->id}", $datos(['activarAdmin' => 1]))->assertRedirect(route('usuarios.index'));
        $this->assertSame(1, (int) $operario->fresh()->activarAdmin);
        $this->put("/usuarios/{$operario->id}", $datos(['activarPicking' => 1]))->assertRedirect(route('usuarios.index'));
        $this->assertSame(0, (int) $operario->fresh()->activarAdmin);

        // Con solo Usuarios no se lo puede dar a nadie (ni a sí mismo).
        $usuarios = $this->crearUsuario(['name' => 'RRHH', 'email' => 'rrhh@uno.test', 'codisb' => 'UNO', 'activarUsuario' => 1]);
        $this->actingAs($usuarios)->put("/usuarios/{$operario->id}", $datos(['activarAdmin' => 1, 'activarPicking' => 1]))->assertRedirect(route('usuarios.index'));
        $this->assertSame([0, 1], [(int) $operario->fresh()->activarAdmin, (int) $operario->fresh()->activarPicking]);

        // Ni puede tocar a quien lo tiene (le cambiaría la contraseña y entraría como él).
        $this->get("/usuarios/{$this->encargado->id}")->assertForbidden();
        $this->put("/usuarios/{$this->encargado->id}", $datos([]))->assertForbidden();
        $this->assertSame(1, (int) $this->encargado->fresh()->activarAdmin);
    }
}
