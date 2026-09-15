<?php

namespace App\Http\Controllers;

use App\Support\MenuSides;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(Request $request): View
    {
        $usuario = $request->user();
        $cfg = $usuario->cfg;

        return view('home', [
            'cfg' => $cfg,
            'modulos' => MenuSides::visibles($usuario, $cfg),
        ]);
    }
}
