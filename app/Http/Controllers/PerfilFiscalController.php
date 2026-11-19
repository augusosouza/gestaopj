<?php

namespace App\Http\Controllers;

use App\Enums\RegimeTributario;
use App\Models\Empresa;
use App\Models\Evento;
use App\Models\PerfilFiscal;
use App\Models\Prestador;
use App\Rules\DocumentoValido;
use App\Support\Documento;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PerfilFiscalController extends Controller
{
    public function index(): View
    {
        $nomes = Empresa::pluck('razao_social', 'cnpj')->union(Prestador::pluck('nome', 'documento'));

        return view('perfis.index', [
            'perfis' => PerfilFiscal::with('confirmadoPor')->orderBy('cnpj')->orderByDesc('vigencia_inicio')->get(),
            'nomes' => $nomes,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['cnpj' => Documento::limpar($request->input('cnpj'))]);
        $d = $request->validate([
            'cnpj' => ['required', new DocumentoValido('cnpj')],
            'regime' => ['required', Rule::enum(RegimeTributario::class)],
            'vigencia_inicio' => ['required', 'date'],
            'vigencia_fim' => ['nullable', 'date', 'after_or_equal:vigencia_inicio'],
            'observacao' => ['nullable', 'string', 'max:1000'],
        ]);

        // Encerra a vigência anterior em aberto, no dia anterior ao início da nova.
        PerfilFiscal::where('cnpj', $d['cnpj'])->whereNull('vigencia_fim')
            ->whereDate('vigencia_inicio', '<', $d['vigencia_inicio'])
            ->update(['vigencia_fim' => Carbon::parse($d['vigencia_inicio'])->subDay()->toDateString()]);

        $perfil = PerfilFiscal::create($d);
        Evento::registrar('perfil_fiscal.criado', $perfil, ['regime' => $d['regime']]);

        return back()->with('ok', 'Perfil fiscal registrado. Confirme-o para que conte na conferência.');
    }

    public function confirmar(PerfilFiscal $perfil): RedirectResponse
    {
        $perfil->forceFill(['confirmado_por' => auth()->id(), 'confirmado_em' => now()])->save();
        Evento::registrar('perfil_fiscal.confirmado', $perfil);

        return back()->with('ok', 'Perfil fiscal confirmado.');
    }
}
