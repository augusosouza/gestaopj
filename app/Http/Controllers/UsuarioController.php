<?php

namespace App\Http\Controllers;

use App\Enums\Papel;
use App\Models\Prestador;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UsuarioController extends Controller
{
    public function index(): View
    {
        return view('cadastros.usuarios', [
            'usuarios' => User::with('prestador')->orderBy('name')->get(),
            'prestadores' => Prestador::orderBy('nome')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'papel' => ['required', Rule::enum(Papel::class)],
            'prestador_id' => ['nullable', 'required_if:papel,prestador', 'exists:prestadores,id'],
            'password' => ['required', Password::min(10)],
        ], ['prestador_id.required_if' => 'Selecione o prestador vinculado a este acesso.']);

        if ($d['papel'] !== Papel::Prestador->value) {
            $d['prestador_id'] = null;
        }
        User::create($d);

        return back()->with('ok', 'Acesso criado.');
    }
}
