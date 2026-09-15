<?php

namespace Tests\Feature;

use Tests\Concerns\TablasSides;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use TablasSides;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearCfg();
    }

    public function test_invitado_es_enviado_al_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/home')->assertRedirect(route('login'));
    }

    public function test_pantalla_de_login_muestra_la_drogueria(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Iniciar sesión')
            ->assertSee('DROGUERIA ACTIVA,C.A');
    }

    public function test_usuario_activo_inicia_sesion(): void
    {
        $usuario = $this->crearUsuario();

        $this->post('/login', ['email' => $usuario->email, 'password' => 'secreto123'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($usuario);
    }

    public function test_contrasena_incorrecta_no_entra(): void
    {
        $usuario = $this->crearUsuario();

        $this->from('/login')
            ->post('/login', ['email' => $usuario->email, 'password' => 'otra'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_usuario_inactivo_no_entra(): void
    {
        $usuario = $this->crearUsuario(['estado' => 'INACTIVO']);

        $this->post('/login', ['email' => $usuario->email, 'password' => 'secreto123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_cerrar_sesion(): void
    {
        $usuario = $this->crearUsuario();

        $this->actingAs($usuario)->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
