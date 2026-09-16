<?php

namespace Tests\Concerns;

use App\Models\Seped\Pedido;
use App\Models\Sides\SidesCfg;
use App\Models\Sides\SidesModuloSucursal;
use App\Models\Sides\SidesPedidoOperacion;
use App\Support\MenuSides;
use App\Models\Sides\SidesUsers;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sides_v2 no tiene migraciones (el esquema es de seped_v2), así que las pruebas crean
 * en SQLite en memoria solo las columnas de sides_* que usan.
 */
trait TablasSides
{
    private const FLAGS_USUARIO = [
        'activarMonitor', 'activarPicking', 'activarPacking', 'activarUsuario', 'activarConfig',
        'eliminarPedido', 'activarResetear', 'activarPedido', 'activarResumen', 'activarInformes',
        'activarGuiaCarga', 'activarGuiaDescarga', 'activarLiberarAlcabala',
    ];

    protected function crearTablasSides(): void
    {
        Schema::create('sides_cfg', function (Blueprint $table) {
            $table->string('codisb', 20)->primary();
            $table->string('nombre', 150)->nullable();
            $table->string('nomcorto', 20)->nullable();
            $table->string('rif', 20)->nullable();
            $table->string('direccion', 150)->nullable();
            $table->string('contacto', 50)->nullable();
            $table->string('telefono', 50)->nullable();
            $table->string('localidad', 100)->nullable();
            $table->integer('activarPacking')->default(1);
            $table->integer('activarEtiPacking')->default(0);
            $table->integer('ModoCesta')->default(0);
            $table->tinyInteger('procAlcabalaPicking')->default(0);
            $table->integer('TamLetraMonitor')->default(12);
            $table->integer('MostrarTituloMonitor')->default(1);
            $table->integer('activarVerOperadorMonitor')->default(0);
            $table->integer('mostrarObsMonitor')->default(0);
            $table->integer('mostrarTranMonitor')->default(0);
            $table->string('ordenPedSides', 20)->default('DESCRIPCION');
            $table->integer('activarValPicking')->default(0);
            $table->string('claveValPicking', 20)->default('123456');
            $table->integer('mostrarExiRealPick')->default(0);
            $table->integer('mostrarDepPiking')->default(0);
            $table->integer('activarValPacking')->default(0);
            $table->integer('activar_separador_automatico')->default(0);
            $table->string('titulopagina', 100)->nullable();
            $table->string('formatoPersEtiq', 50)->nullable();
            $table->integer('activarImpTicket')->default(0);
            $table->integer('activar_etiqueta_packing')->default(0);
            $table->integer('mostrarEntrega')->default(0);
            $table->integer('activarSincronizacionRutas')->default(0);
        });

        Schema::create('sides_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();
            $table->string('estado', 10)->default('ACTIVO');
            $table->string('clave')->default('');
            $table->string('codisb', 20);
            foreach (self::FLAGS_USUARIO as $flag) {
                $table->integer($flag)->default(0);
            }
            $table->tinyInteger('esAdmin')->default(0);
        });

        Schema::create('sides_modulo_sucursal', function (Blueprint $table) {
            $table->increments('id');
            $table->string('codisb', 20);
            $table->string('modulo', 40);
            $table->tinyInteger('activo')->default(1);
            $table->string('actualizado_por', 100)->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['codisb', 'modulo']);
        });
    }

    /** `pedido` de SEPED (solo columnas que usa SIDES) y sides_pedido_operacion. */
    protected function crearTablasPedidos(): void
    {
        Schema::create('pedido', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('codcli', 20);
            $table->string('nomcli', 200)->default('');
            $table->string('ruta', 50)->nullable();
            $table->string('estado', 20);
            $table->string('codisb', 20);
            $table->dateTime('fecha')->nullable();
            $table->dateTime('fecenviado')->nullable();
            $table->dateTime('fecprocesado')->nullable();
            $table->dateTime('fecpicking')->nullable();
            $table->dateTime('fecpacking')->nullable();
            $table->dateTime('fecfacturado')->nullable();
            $table->dateTime('feccompletado')->nullable();
            $table->integer('numren')->default(0);
            $table->integer('numund')->default(0);
            $table->string('observacion', 500)->nullable();
            $table->string('codtransp', 100)->nullable();
            $table->string('entrega', 250)->nullable();
            $table->text('documento')->nullable();
            $table->dateTime('fecrecibido')->nullable();
            $table->string('tipedido', 10)->default('NORMAL');
        });

        Schema::create('sides_etiqueta_pedido', function (Blueprint $table) {
            $table->increments('id');
            $table->string('numepedi', 100);
            $table->string('etiqueta', 100);
            $table->string('nomcli', 255);
            $table->string('ruta', 100);
            $table->string('estado', 100)->default('NUEVO');
            $table->string('codcli', 20);
            $table->dateTime('fecentregado')->nullable();
            $table->integer('guia')->nullable();
            $table->dateTime('feccargado')->nullable();
        });

        Schema::create('sides_pedido_operacion', function (Blueprint $table) {
            $table->integer('id_pedido')->primary();
            $table->string('codisb', 20);
            $table->string('despachador', 100)->nullable();
            $table->string('embalador', 100)->nullable();
            $table->string('recipiente', 50)->nullable();
            $table->integer('despasignado')->default(0);
            $table->dateTime('fecpicking2')->nullable();
            $table->dateTime('fecpacking2')->nullable();
            $table->string('cantBultos', 100)->default('1');
            $table->text('num_cesta_ped')->nullable();
        });

        Schema::create('pedren', function (Blueprint $table) {
            $table->integer('id');
            $table->integer('item');
            $table->string('codprod', 20);
            $table->string('desprod', 250)->default('');
            $table->string('barra', 20)->default('');
            $table->integer('cantidad')->default(1);
            $table->string('codisb', 20)->default('505094939');
            $table->integer('cantdesp')->default(0);
            $table->string('estado_desp', 50)->nullable();
            $table->string('ubicacion', 50)->nullable();
            $table->string('deposito', 20)->nullable();
            $table->string('lote', 50)->nullable();
            $table->string('feclote', 50)->nullable();
            $table->string('marcamodelo', 50)->nullable();
            $table->integer('refrigerado')->default(0);
            $table->integer('psicotropico')->default(0);
            $table->string('listalote', 3000)->nullable();
            $table->primary(['id', 'item']);
        });

        Schema::create('sides_pedren_operacion', function (Blueprint $table) {
            $table->integer('id_pedido');
            $table->integer('item');
            $table->string('codisb', 20);
            $table->integer('cantdesp')->default(-1);
            $table->integer('chequeado')->default(-1);
            $table->integer('bulto')->default(1);
            $table->integer('packing')->default(0);
            $table->string('recipiente', 50)->default('1');
            $table->string('despachador', 100)->nullable();
            $table->string('ubicacion', 100)->nullable();
            $table->string('deposito', 100)->nullable();
            $table->string('lote', 50)->nullable();
            $table->string('feclote', 50)->nullable();
            $table->integer('alertalote')->default(0);
            $table->integer('ExiRealPick')->default(0);
            $table->integer('marcarDelete')->default(0);
            $table->primary(['id_pedido', 'item']);
        });

        Schema::create('sides_logpicking', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('id_pedido');
            $table->string('usuario', 250);
            $table->integer('numren');
            $table->integer('numund');
            $table->integer('tiempo_picking');
            $table->dateTime('fecha_del_picking');
            $table->string('descripcion', 250);
        });

        Schema::create('sides_alcabala_lote', function (Blueprint $table) {
            $table->increments('id');
            $table->string('codisb', 20);
            $table->string('nombre', 100)->nullable();
            $table->string('estado', 20)->default('ABIERTO');
            $table->string('estado_packing', 20)->nullable();
            $table->string('origen_creacion', 20);
            $table->integer('id_perfil')->nullable();
            $table->string('creado_por', 100)->nullable();
            $table->dateTime('fecha_creacion');
            $table->dateTime('fecha_confirmado')->nullable();
            $table->dateTime('fecha_terminacion')->nullable();
            $table->dateTime('fecha_inicio_packing')->nullable();
            $table->dateTime('fecha_terminacion_packing')->nullable();
            $table->string('embalador_batch', 100)->nullable();
            $table->string('observacion', 255)->nullable();
        });

        Schema::create('sides_alcabala_lote_pedido', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('id_lote');
            $table->integer('numped')->unique();
            $table->integer('num_caja')->nullable();
            $table->string('codcli', 20)->nullable();
            $table->dateTime('fecha_agregado');
        });

        Schema::create('sides_alcabala_pedido_liberado', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('numped')->unique();
            $table->string('codisb', 20);
            $table->dateTime('fecha_liberado');
            $table->string('liberado_por', 100)->nullable();
        });

        Schema::create('sides_logpacking', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('id_pedido');
            $table->string('usuario', 250);
            $table->integer('numren');
            $table->integer('numund');
            $table->integer('tiempo_packing');
            $table->dateTime('fecha_del_packing');
        });

        Schema::create('sides_log_inac_packing', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('id_pedido');
            $table->integer('id_pedido_anterior')->nullable();
            $table->string('usuario', 250);
            $table->integer('numren');
            $table->integer('numund');
            $table->integer('tiempo_packing_inac');
            $table->dateTime('fecha_del_packing');
        });

        Schema::create('sides_log_inac_picking', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('id_pedido');
            $table->integer('id_pedido_anterior')->nullable();
            $table->string('usuario', 250);
            $table->integer('numren');
            $table->integer('numund');
            $table->integer('tiempo_picking_inac');
            $table->dateTime('fecha_del_picking');
        });
    }

    /** Rutas de SIDES, `cliente` de SEPED (solo columnas que usa SIDES) y sides_guia. */
    protected function crearTablasRutas(): void
    {
        Schema::create('sides_rutas', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nombre', 100);
            $table->dateTime('fecha')->nullable();
            $table->string('codisb', 20);
            $table->unique(['codisb', 'nombre']);
        });

        Schema::create('sides_rutasren', function (Blueprint $table) {
            $table->increments('item');
            $table->integer('id');
            $table->string('codisb', 20);
            $table->string('codcli', 100);
            $table->text('nomcli');
            $table->string('rif', 100);
            $table->string('sec', 100)->nullable();
            $table->string('zona', 100);
            $table->integer('retiraLocal')->default(0);
            $table->unique(['codisb', 'codcli']);
        });

        Schema::create('cliente', function (Blueprint $table) {
            $table->string('codcli', 20);
            $table->string('codisb', 20);
            $table->string('codac3', 20)->default('');
            $table->string('nombre', 200)->nullable();
            $table->string('rif', 50)->nullable();
            $table->text('direccion')->nullable();
            $table->string('entrega', 250)->nullable();
            $table->string('ruta', 50)->default('');
            $table->integer('orden')->default(0);
            $table->primary(['codac3', 'codcli', 'codisb']);
        });

        Schema::create('sides_guia', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamp('fecha')->nullable();
            $table->string('chofer', 255)->default('');
            $table->string('estado', 30)->nullable();
            $table->string('nomchofer', 255)->default('');
            $table->string('codisb', 20);
            $table->string('ruta', 100);
            $table->string('chofer_aux_id', 20)->nullable();
            $table->string('chof_aux_nom', 255)->nullable();
            $table->dateTime('fecha_salida')->nullable();
            $table->string('unidad', 100)->nullable();
            $table->string('ordenarPor', 100)->default('clientes');
            $table->string('latitud', 100)->nullable();
            $table->string('longitud', 100)->nullable();
        });
    }

    /** Renglones de guía y tablas de SEPED que leen las guías (choferes, users, fact, cxc, reclamo, recren). */
    protected function crearTablasGuias(): void
    {
        Schema::create('sides_guia_ren', function (Blueprint $table) {
            $table->integer('id');
            $table->string('codcli', 100);
            $table->string('nomcli', 100);
            $table->integer('orden');
            $table->boolean('terminado')->default(0);
            $table->boolean('cargado')->default(0);
        });

        Schema::create('choferes', function (Blueprint $table) {
            $table->string('chof_co', 20);
            $table->string('chof_nom', 100)->nullable();
            $table->string('chof_ced', 20)->nullable();
            $table->string('chof_tipo', 1)->default('C');
            $table->string('codisb', 20);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
            $table->string('codcli', 20)->nullable();
        });

        Schema::create('fact', function (Blueprint $table) {
            $table->string('factnum', 20);
            $table->string('codisb', 20);
            $table->string('codcli', 20);
            $table->string('descrip', 100);
            $table->string('nroctrol', 20)->default('');
        });

        Schema::create('cxc', function (Blueprint $table) {
            $table->string('id', 20);
            $table->string('codisb', 20);
            $table->string('codcli', 20);
        });

        Schema::create('reclamo', function (Blueprint $table) {
            $table->increments('id');
            $table->string('codisb', 20)->nullable();
            $table->string('codcli', 20);
            $table->string('factnum', 20);
        });

        Schema::create('recren', function (Blueprint $table) {
            $table->integer('id');
            $table->increments('item');
            $table->string('motivo', 50);
        });
    }

    /** Cliente en `cliente` de SEPED. */
    protected function crearCliente(string $codcli, string $nombre, string $ruta = '', array $atributos = []): void
    {
        DB::table('cliente')->insert(array_merge([
            'codcli' => $codcli,
            'codisb' => '505094939',
            'nombre' => $nombre,
            'rif' => "J-{$codcli}",
            'ruta' => $ruta,
        ], $atributos));
    }

    /** Renglón en `pedren` de SEPED. */
    protected function crearRenglon(int $pedidoId, int $item, array $atributos = []): void
    {
        DB::table('pedren')->insert(array_merge([
            'id' => $pedidoId,
            'item' => $item,
            'codprod' => "P{$item}",
            'desprod' => "PRODUCTO {$item}",
            'barra' => "77{$item}",
            'cantidad' => 2,
        ], $atributos));
    }

    protected function crearPedido(array $atributos, array $operacion = []): Pedido
    {
        $pedido = Pedido::query()->create(array_merge([
            'codcli' => 'C001',
            'nomcli' => 'CLIENTE PRUEBA',
            'ruta' => 'RUTA 1',
            'codisb' => '505094939',
            'fecha' => '2026-09-15 07:30:00',
            'fecenviado' => '2026-09-15 08:00:00',
            'fecprocesado' => '2026-09-15 08:30:00',
            'numren' => 3,
            'numund' => 10,
        ], $atributos));

        if ($operacion !== []) {
            SidesPedidoOperacion::query()->create(array_merge([
                'id_pedido' => $pedido->id,
                'codisb' => $pedido->codisb,
            ], $operacion));
        }

        return $pedido;
    }

    /** @param list<string>|null $modulos opcionales activos; por defecto todos */
    protected function crearCfg(array $atributos = [], ?array $modulos = null): SidesCfg
    {
        $cfg = SidesCfg::query()->create(array_merge([
            'codisb' => '505094939',
            'nombre' => 'DROGUERIA ACTIVA,C.A',
            'nomcorto' => 'DROACTIVA',
            'activarPacking' => 1,
            'activarEtiPacking' => 1,
        ], $atributos));

        foreach ($modulos ?? MenuSides::OPCIONALES as $modulo) {
            SidesModuloSucursal::query()->create(['codisb' => $cfg->codisb, 'modulo' => $modulo, 'activo' => 1]);
        }

        return $cfg;
    }

    protected function crearUsuario(array $atributos = []): SidesUsers
    {
        return SidesUsers::query()->create(array_merge([
            'name' => 'Operador Prueba',
            'email' => 'operador@example.com',
            'password' => 'secreto123',
            'estado' => 'ACTIVO',
            'clave' => '',
            'codisb' => '505094939',
        ], $atributos));
    }

    protected function todosLosPermisos(): array
    {
        return array_fill_keys(self::FLAGS_USUARIO, 1);
    }
}
