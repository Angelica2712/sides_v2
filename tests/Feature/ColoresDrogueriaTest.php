<?php

namespace Tests\Feature;

use App\Support\TemaSides;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\TablasSides;
use Tests\TestCase;

/** SIDES se ve con los colores que la droguería configuró en SEPED (tabla tema_paleta). */
class ColoresDrogueriaTest extends TestCase
{
    use TablasSides;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->crearTablasSides();
        $this->crearCfg();
        Cache::flush();

        // La crea y la llena SEPED (TemaColores::publicar).
        Schema::create('tema_paleta', function (Blueprint $table) {
            $table->string('codisb', 20)->primary();
            $table->text('paleta');
            $table->timestamp('updated_at')->nullable();
        });
    }

    private function publicar(string $codisb, array $paleta): void
    {
        DB::table('tema_paleta')->insert(['codisb' => $codisb, 'paleta' => json_encode($paleta)]);
    }

    public function test_el_layout_usa_la_paleta_que_publico_seped(): void
    {
        $this->publicar('505094939', ['primary' => '#a7f3d0', 'primary_dark' => '#6ee7b7', 'text_primary' => '#0f172a', 'sidebar_bg' => '#ffffff', 'sidebar_text' => '#1e293b']);
        $usuario = $this->crearUsuario(['activarMonitor' => 1]);

        $this->actingAs($usuario)->get('/home')->assertOk()
            ->assertSee(':root{--brand-primary:#a7f3d0;--brand-primary-dark:#6ee7b7;--brand-text-primary:#0f172a;--brand-sidebar-bg:#ffffff;--brand-sidebar-text:#1e293b;}', false);
    }

    public function test_sin_paleta_quedan_los_colores_por_defecto(): void
    {
        $usuario = $this->crearUsuario(['activarMonitor' => 1]);

        $this->actingAs($usuario)->get('/home')->assertOk()->assertDontSee('--brand-primary:', false);

        // Ni siquiera falla si SEPED todavía no creó la tabla.
        Schema::drop('tema_paleta');
        Cache::flush();
        $this->get('/home')->assertOk();
    }

    public function test_el_login_de_la_drogueria_tambien_toma_sus_colores(): void
    {
        $this->publicar('505094939', ['primary' => '#dc2626']);

        $this->get('/login/505094939')->assertOk()->assertSee(':root{--brand-primary:#dc2626;}', false);
    }

    public function test_descarta_lo_que_no_es_un_color(): void
    {
        $this->publicar('505094939', [
            'primary' => '#dc2626',
            'accent' => 'red;}body{display:none',
            'sidebar_bg' => 'url(x)',
            'Mal-Clave' => '#000000',
        ]);

        $this->assertSame(['primary' => '#dc2626'], TemaSides::paleta('505094939'));
    }
}
