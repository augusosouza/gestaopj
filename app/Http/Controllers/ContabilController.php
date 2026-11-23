<?php

namespace App\Http\Controllers;

use App\Enums\CategoriaDocumento;
use App\Enums\EtapaContabil;
use App\Models\CompetenciaContabil;
use App\Models\DocumentoContabil;
use App\Models\Evento;
use App\Models\Solicitacao;
use App\Models\VersaoFechamento;
use App\Services\AreaContabil;
use App\Services\Fechamento;
use App\Support\Competencia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Área do contador, vista pela empresa e pelo escritório no contexto de uma empresa. */
class ContabilController extends Controller
{
    /** Tipos aceitos: documentos, planilhas, imagens, extratos OFX e XML. */
    private const MIMES = 'pdf,xml,csv,ofx,txt,xls,xlsx,ods,doc,docx,png,jpg,jpeg,zip';

    public function __construct(private AreaContabil $area) {}

    public function index(): View
    {
        $competencias = CompetenciaContabil::withCount(['documentos', 'solicitacoes as pendentes_count' => fn ($q) => $q->whereIn('status', ['aberta', 'respondida'])])
            ->orderByDesc('competencia')->limit(24)->get();

        return view('contabil.index', ['competencias' => $competencias, 'sugerida' => Competencia::de()->subMonth()]);
    }

    public function show(string $mes, Fechamento $fechamento): View
    {
        $c = Competencia::de($mes);
        $cc = $this->area->competencia($c, criar: false);
        $cc->load(['documentos.enviadoPor', 'solicitacoes.criadaPor', 'solicitacoes.respondidaPor', 'solicitacoes.documentos', 'versoes.fechadoPor']);
        $historico = $cc->exists
            ? Evento::with('user')->where(fn ($q) => $q->where('alvo_type', $cc->getMorphClass())->where('alvo_id', $cc->id))
                ->orWhere(fn ($q) => $q->where('alvo_type', (new Solicitacao)->getMorphClass())->whereIn('alvo_id', $cc->solicitacoes->pluck('id')))
                ->latest('id')->limit(30)->get()
            : collect();

        return view('contabil.show', [
            'cc' => $cc, 'competencia' => $c, 'r' => $fechamento->resumo($c), 'historico' => $historico,
            'categorias' => CategoriaDocumento::cases(),
        ]);
    }

    // ---- empresa ----

    public function enviarDocumento(Request $request, string $mes): RedirectResponse
    {
        $d = $request->validate([
            'arquivo' => ['required', 'file', 'max:10240', 'mimes:'.self::MIMES],
            'categoria' => ['required', Rule::enum(CategoriaDocumento::class)],
            'descricao' => ['nullable', 'string', 'max:255'],
        ], ['arquivo.mimes' => 'Tipo de arquivo não aceito. Use PDF, XML, planilha, OFX, imagem ou ZIP.']);
        $cc = $this->area->competencia(Competencia::de($mes));
        $this->area->enviarDocumento($cc, $request->file('arquivo'), $d['categoria'], $d['descricao'] ?? null);

        return back()->with('ok', 'Documento enviado ao contador.');
    }

    public function enviar(string $mes): RedirectResponse
    {
        $this->area->marcarEnviada($this->area->competencia(Competencia::de($mes)));

        return back()->with('ok', 'Contador avisado de que os documentos do mês foram enviados.');
    }

    public function responder(Request $request, Solicitacao $solicitacao): RedirectResponse
    {
        $d = $request->validate([
            'resposta' => ['nullable', 'string', 'max:2000'],
            'arquivo' => ['nullable', 'file', 'max:10240', 'mimes:'.self::MIMES],
        ]);
        $this->area->responder($solicitacao, $d['resposta'] ?? null, $request->file('arquivo'));

        return back()->with('ok', 'Resposta enviada ao contador.');
    }

    // ---- contador ----

    public function solicitar(Request $request, string $mes): RedirectResponse
    {
        $d = $request->validate([
            'titulo' => ['required', 'string', 'max:160'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'prazo' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        $this->area->solicitar($this->area->competencia(Competencia::de($mes)), $d['titulo'], $d['descricao'] ?? null, $d['prazo'] ?? null);

        return back()->with('ok', 'Solicitação enviada à empresa.');
    }

    public function aceitar(Solicitacao $solicitacao): RedirectResponse
    {
        $this->area->aceitar($solicitacao);

        return back()->with('ok', 'Solicitação resolvida.');
    }

    public function devolver(Request $request, Solicitacao $solicitacao): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:1000']]);
        $this->area->devolver($solicitacao, $d['motivo']);

        return back()->with('ok', 'Solicitação devolvida à empresa.');
    }

    public function cancelar(Solicitacao $solicitacao): RedirectResponse
    {
        $this->area->cancelar($solicitacao);

        return back()->with('ok', 'Solicitação cancelada.');
    }

    public function etapa(Request $request, string $mes): RedirectResponse
    {
        $d = $request->validate(['etapa' => ['required', Rule::enum(EtapaContabil::class)]]);
        $this->area->mudarEtapa($this->area->competencia(Competencia::de($mes)), EtapaContabil::from($d['etapa']));

        return back()->with('ok', 'Etapa atualizada.');
    }

    public function fechar(Request $request, string $mes): RedirectResponse
    {
        $d = $request->validate(['observacao' => ['nullable', 'string', 'max:1000']]);
        $v = $this->area->fechar($this->area->competencia(Competencia::de($mes)), $d['observacao'] ?? null);

        return back()->with('ok', "Competência fechada. Versão {$v->versao} gravada.");
    }

    public function reabrir(Request $request, string $mes): RedirectResponse
    {
        $d = $request->validate(['justificativa' => ['required', 'string', 'max:1000']]);
        $this->area->reabrir($this->area->competencia(Competencia::de($mes)), $d['justificativa']);

        return back()->with('ok', 'Competência reaberta para revisão. A versão anterior continua guardada.');
    }

    // ---- downloads (escopo da empresa pelo route model binding) ----

    public function baixarDocumento(DocumentoContabil $documento): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($documento->arquivo_path), 404);
        Evento::registrar('contabil.documento_baixado', $documento);

        return Storage::disk('local')->download($documento->arquivo_path, $documento->nome_original);
    }

    public function baixarVersao(VersaoFechamento $versao): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($versao->arquivo_path), 404);
        Evento::registrar('contabil.versao_baixada', $versao, ['versao' => $versao->versao]);
        $cc = CompetenciaContabil::find($versao->competencia_contabil_id);

        return Storage::disk('local')->download($versao->arquivo_path, sprintf('fechamento-%s-v%d.zip', $cc->mes(), $versao->versao));
    }
}
