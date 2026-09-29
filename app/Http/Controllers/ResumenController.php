<?php

namespace App\Http\Controllers;

use App\Services\Resumen\ResumenService;
use App\Support\MenuSides;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ResumenController extends Controller
{
    public function __construct(private readonly ResumenService $resumen)
    {
    }

    public function index(Request $request): View
    {
        $usuario = $request->user();
        $cfg = $usuario->cfg;

        return view('resumen.index', [
            'cfg' => $cfg,
            'contadores' => $this->resumen->contadores($usuario->codisb),
            'veMonitor' => MenuSides::puede($usuario, $cfg, 'monitor'),
            'vePicking' => MenuSides::puede($usuario, $cfg, 'picking'),
            'vePacking' => MenuSides::puede($usuario, $cfg, 'packing'),
            'vePedidos' => MenuSides::puede($usuario, $cfg, 'pedidos'),
        ]);
    }
}
