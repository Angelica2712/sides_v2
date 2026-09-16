<?php

namespace Tests\Feature;

use App\Models\Sides\SidesUsers;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

class UsuariosTest extends TestCase
{
    use TablasSides;

    private SidesUsers $jefe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearTablasPedidos();

        $this->crearCfg();
        $this->jefe = $this->crearUsuario(['name' => 'Jefa Ana', 'email' => 'ana@example.com', 'activarUsuario' => 1]);
    }

    private function permisos(string ...$permisos): array
    {
        return array_fill_keys($permisos, '1');
    }

    public function test_lista_solo_los_usuarios_de_la_sucursal_y_busca(): void
    {
        $this->crearUsuario(['name' => 'Pedro Picking', 'email' => 'pedro@example.com', 'activarPicking' => 1]);
        $this->crearUsuario(['name' => 'Ajeno', 'email' => 'ajeno@example.com', 'codisb' => 'OTRA']);

        $this->actingAs($this->jefe)->get('/usuarios')->assertOk()
            ->assertSee('Jefa Ana')->assertSee('(tú)')
            ->assertSee('Pedro Picking')->assertSee('Picking')
            ->assertDontSee('Ajeno');

        $this->get('/usuarios?buscar=pedro@')->assertSee('1 usuario con la búsqueda aplicada')->assertSee('Pedro Picking');
        $this->get('/usuarios/'.SidesUsers::query()->where('email', 'ajeno@example.com')->value('id'))->assertNotFound();
    }

    public function test_crear_usuario_en_la_sucursal_con_contrasena_cifrada(): void
    {
        $this->actingAs($this->jefe)->post('/usuarios', [
            'name' => ' Carla Packing ',
            'email' => 'Carla@Example.com',
            'estado' => 'ACTIVO',
            'password' => 'secreta1',
            'password_confirmation' => 'secreta1',
            'codisb' => 'OTRA',
            'esAdmin' => '1',
            'permisos' => $this->permisos('activarPacking', 'activarMonitor') + ['inventado' => '1'],
        ])->assertRedirect(route('usuarios.index'))->assertSessionHas('mensaje', 'Usuario Carla Packing creado.');

        $carla = SidesUsers::query()->where('email', 'carla@example.com')->firstOrFail();
        $this->assertSame(['Carla Packing', '505094939', 0, 1, 1, 0], [
            $carla->name, $carla->codisb, (int) $carla->esAdmin, (int) $carla->activarPacking, (int) $carla->activarMonitor, (int) $carla->activarPicking,
        ]);
        $this->assertTrue(Hash::check('secreta1', $carla->password));
        $this->assertSame('', $carla->clave);

        $this->post('/logout');
        $this->post('/login', ['email' => 'carla@example.com', 'password' => 'secreta1'])->assertRedirect('/home');
    }

    public function test_crear_valida_correo_unico_y_contrasena(): void
    {
        $this->actingAs($this->jefe)->from('/usuarios/nuevo')->post('/usuarios', [
            'name' => '',
            'email' => 'ANA@example.com',
            'estado' => 'BORRADO',
            'password' => '123',
            'password_confirmation' => '321',
        ])->assertRedirect('/usuarios/nuevo')->assertSessionHasErrors(['name', 'email', 'estado', 'password']);

        $this->assertSame(1, SidesUsers::query()->count());
    }

    public function test_modificar_datos_estado_y_permisos(): void
    {
        $pedro = $this->crearUsuario(['name' => 'Pedro', 'email' => 'pedro@example.com', 'activarPicking' => 1, 'activarPacking' => 1]);

        $this->actingAs($this->jefe)->get("/usuarios/{$pedro->id}")->assertOk()->assertSee('Cambiar contraseña')->assertSee('Eliminar usuario');

        $this->put("/usuarios/{$pedro->id}", [
            'name' => 'Pedro Pérez',
            'email' => 'pedro@example.com',
            'estado' => 'INACTIVO',
            'permisos' => $this->permisos('activarPicking', 'activarResetear', 'activarLiberarAlcabala'),
        ])->assertRedirect(route('usuarios.index'));

        $pedro->refresh();
        $this->assertSame(['Pedro Pérez', 'INACTIVO', 1, 0, 1, 1], [
            $pedro->name, $pedro->estado, (int) $pedro->activarPicking, (int) $pedro->activarPacking, (int) $pedro->activarResetear, (int) $pedro->activarLiberarAlcabala,
        ]);
    }

    public function test_nadie_se_deja_sin_acceso_a_si_mismo(): void
    {
        $this->actingAs($this->jefe)->get("/usuarios/{$this->jefe->id}")->assertOk()->assertDontSee('Eliminar usuario');

        $this->from("/usuarios/{$this->jefe->id}")->put("/usuarios/{$this->jefe->id}", [
            'name' => 'Jefa Ana', 'email' => 'ana@example.com', 'estado' => 'ACTIVO', 'permisos' => $this->permisos('activarMonitor'),
        ])->assertSessionHasErrors('estado');
        $this->put("/usuarios/{$this->jefe->id}", [
            'name' => 'Jefa Ana', 'email' => 'ana@example.com', 'estado' => 'INACTIVO', 'permisos' => $this->permisos('activarUsuario'),
        ])->assertSessionHasErrors('estado');
        $this->delete("/usuarios/{$this->jefe->id}")->assertSessionHas('error', 'No puedes eliminar tu propio usuario.');

        $this->jefe->refresh();
        $this->assertSame(['ACTIVO', 1], [$this->jefe->estado, (int) $this->jefe->activarUsuario]);
    }

    public function test_cambiar_contrasena(): void
    {
        $pedro = $this->crearUsuario(['email' => 'pedro@example.com']);
        $pedro->forceFill(['remember_token' => 'token-viejo'])->save();

        $this->actingAs($this->jefe)->from("/usuarios/{$pedro->id}")
            ->put("/usuarios/{$pedro->id}/clave", ['password' => 'nueva12', 'password_confirmation' => 'otra12'])
            ->assertSessionHasErrorsIn('clave', 'password');

        $this->put("/usuarios/{$pedro->id}/clave", ['password' => 'nueva12', 'password_confirmation' => 'nueva12'])
            ->assertRedirect(route('usuarios.edit', $pedro->id));

        $pedro->refresh();
        $this->assertTrue(Hash::check('nueva12', $pedro->password));
        $this->assertNull($pedro->remember_token);
    }

    public function test_eliminar_usuario(): void
    {
        $pedro = $this->crearUsuario(['email' => 'pedro@example.com']);

        $this->actingAs($this->jefe)->delete("/usuarios/{$pedro->id}")->assertRedirect(route('usuarios.index'));

        $this->assertNull(SidesUsers::query()->find($pedro->id));
    }

    public function test_solo_el_administrador_modifica_a_un_administrador(): void
    {
        $admin = $this->crearUsuario(['name' => 'Admin', 'email' => 'admin@example.com', 'esAdmin' => 1, 'activarUsuario' => 1]);

        $this->actingAs($this->jefe)->get('/usuarios')->assertSee('Administrador');
        $this->get("/usuarios/{$admin->id}")->assertForbidden();
        $this->put("/usuarios/{$admin->id}/clave", ['password' => 'robada1', 'password_confirmation' => 'robada1'])->assertForbidden();
        $this->delete("/usuarios/{$admin->id}")->assertForbidden();
        $this->assertFalse(Hash::check('robada1', $admin->fresh()->password));

        $this->actingAs($admin)->get("/usuarios/{$admin->id}")->assertOk();
    }

    public function test_usuario_desactivado_sale_en_su_siguiente_peticion(): void
    {
        $pedro = $this->crearUsuario(['email' => 'pedro@example.com', 'activarPicking' => 1]);
        $this->actingAs($pedro)->get('/home')->assertOk();

        $pedro->forceFill(['estado' => 'INACTIVO'])->save();

        $this->get('/home')->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_requiere_permiso_de_usuarios(): void
    {
        $operario = $this->crearUsuario(['email' => 'op@example.com', 'activarConfig' => 1]);

        $this->actingAs($operario)->get('/usuarios')->assertForbidden();
        $this->post('/usuarios', [])->assertForbidden();
        $this->delete("/usuarios/{$this->jefe->id}")->assertForbidden();
    }
}
