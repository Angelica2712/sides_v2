<?php

namespace App\Services\Guias;

use App\Models\Sides\SidesGuia;
use App\Models\Sides\SidesUsers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Carga y descarga de guías (legacy AdminguiachoferController), para usuarios con permiso de
 * guía de carga o de descarga.
 *
 * Como en el legacy, el usuario es chofer si en SEPED existe un usuario con su mismo correo cuyo
 * código de cliente es la cédula de un chofer de la sucursal. Un chofer ve solo sus guías (como
 * chofer o auxiliar); el resto (personal de almacén) ve todas las guías en curso de la sucursal.
 */
class DespachoService
{
    public const ESTADOS_EN_CURSO = ['NUEVO', 'CARGANDO', 'CARGADO', 'TRANSITO'];

    public function __construct(private readonly GuiasService $guias)
    {
    }

    /** Código del chofer vinculado al usuario, o null. */
    public function choferDe(SidesUsers $usuario): ?string
    {
        return DB::table('choferes')
            ->join('users', 'users.codcli', '=', 'choferes.chof_ced')
            ->where('users.email', $usuario->email)
            ->where('choferes.codisb', $usuario->codisb)
            ->value('choferes.chof_co');
    }

    public function guias(SidesUsers $usuario): Collection
    {
        return $this->consulta($usuario)
            ->select('sides_guia.*')
            ->selectSub(fn ($q) => $q->from('sides_etiqueta_pedido')->whereColumn('guia', 'sides_guia.id')->selectRaw('COUNT(*)'), 'bultos')
            ->selectSub(fn ($q) => $q->from('sides_etiqueta_pedido')->whereColumn('guia', 'sides_guia.id')->whereNotNull('feccargado')->selectRaw('COUNT(*)'), 'cargados')
            ->selectSub(fn ($q) => $q->from('sides_etiqueta_pedido')->whereColumn('guia', 'sides_guia.id')->whereNotNull('fecentregado')->selectRaw('COUNT(*)'), 'entregados')
            ->orderBy('fecha')
            ->get();
    }

    public function guia(SidesUsers $usuario, int $id): SidesGuia
    {
        return $this->consulta($usuario)->find($id)
            ?? throw new GuiasException("La guía #{$id} no está asignada a ti o ya fue entregada.");
    }

    /**
     * Clientes de la guía para cargar (en orden inverso a la entrega: lo último que se entrega
     * entra primero al camión) o para descargar (en orden de visita).
     */
    public function clientes(SidesGuia $guia, string $fase): Collection
    {
        $clientes = $this->guias->clientes($guia);

        return $fase === 'carga' ? $clientes->reverse()->values() : $clientes;
    }

    /** Legacy: la descarga empieza cuando todos los bultos están en el camión. */
    public function pendientesDeCarga(SidesGuia $guia): int
    {
        return DB::table('sides_etiqueta_pedido')->where('guia', $guia->id)->whereNull('feccargado')->count();
    }

    private function consulta(SidesUsers $usuario)
    {
        $chofer = $this->choferDe($usuario);

        return SidesGuia::query()
            ->where('codisb', $usuario->codisb)
            ->whereIn('estado', self::ESTADOS_EN_CURSO)
            ->when($chofer !== null, fn ($q) => $q->where(fn ($q) => $q->where('chofer', $chofer)->orWhere('chofer_aux_id', $chofer)));
    }
}
