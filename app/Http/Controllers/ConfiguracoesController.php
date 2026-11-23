<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/** Central de configurações da empresa (CFG-01). */
class ConfiguracoesController extends Controller
{
    public function index(): View
    {
        return view('configuracoes.index');
    }
}
