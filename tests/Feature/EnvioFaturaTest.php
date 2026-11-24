<?php

namespace Tests\Feature;

use App\Enums\StatusFatura;
use App\Models\Contrato;
use App\Models\Fatura;
use App\Services\EnvioFatura;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\ComContexto;
use Tests\TestCase;

class EnvioFaturaTest extends TestCase
{
    use ComContexto, RefreshDatabase;

    private function fatura(string $cnpj): Fatura
    {
        $empresa = $this->empresa($cnpj);
        $contrato = Contrato::where('organizacao_id', $empresa->id)->firstOrFail();
        $contrato->cliente()->update(['email' => 'cobranca@example.test']);

        return Fatura::create([
            'organizacao_id' => $empresa->id, 'contrato_id' => $contrato->id, 'competencia' => '2026-11-01',
            'valor_centavos' => 12345, 'vencimento' => '2026-12-10', 'status' => StatusFatura::Aberta,
        ]);
    }

    public function test_aceitacao_smtp_e_registrada_nos_dois_historicos_sem_reenvio_duplicado(): void
    {
        $this->seed(DatabaseSeeder::class);
        $fatura = $this->fatura(self::A);
        $externa = $this->fatura(self::B);
        $mailer = Mockery::mock(Mailer::class);
        Mail::swap(Mockery::mock(MailManager::class));
        Mail::shouldReceive('getDefaultDriver')->once()->andReturn('smtp');
        Mail::shouldReceive('mailer')->with('smtp')->once()->andReturn($mailer);
        $mailer->shouldReceive('raw')->once()->andReturn(Mockery::mock(SentMessage::class));

        $cliente = $fatura->contrato->cliente;
        $dados = ['chave' => (string) Str::uuid(), 'destinatario' => $cliente->email];
        $this->como('admin@augi.local')->get(route('faturas.show', $fatura))->assertOk()->assertSee('Enviar resumo da fatura');
        $this->post(route('faturas.enviar', $externa), $dados)->assertNotFound();
        $this->post(route('faturas.enviar', $fatura), $dados)->assertSessionHas('ok');
        $this->post(route('faturas.enviar', $fatura), $dados)->assertSessionHas('ok');

        $this->assertDatabaseHas('eventos', ['acao' => EnvioFatura::ACEITO, 'alvo_id' => $fatura->id]);
        $this->get(route('clientes.historico', [$cliente, 'tipo' => 'comunicacao']))
            ->assertOk()->assertSee(EnvioFatura::ROTULOS[EnvioFatura::ACEITO])->assertSee('sem confirmação')
            ->assertDontSee($cliente->email);
        $this->get(route('governanca.index', ['tipo' => 'comunicacao']))
            ->assertOk()->assertSee(EnvioFatura::ROTULOS[EnvioFatura::ACEITO])->assertSee('Histórico do cliente');
    }

    public function test_falha_smtp_e_exibida_sem_vazar_a_mensagem_do_provedor(): void
    {
        $this->seed(DatabaseSeeder::class);
        $fatura = $this->fatura(self::A);
        $mailer = Mockery::mock(Mailer::class);
        Mail::swap(Mockery::mock(MailManager::class));
        Mail::shouldReceive('getDefaultDriver')->once()->andReturn('smtp');
        Mail::shouldReceive('mailer')->with('smtp')->once()->andReturn($mailer);
        $mailer->shouldReceive('raw')->once()->andThrow(new TransportException('informação privada do SMTP'));
        $cliente = $fatura->contrato->cliente;

        $this->como('admin@augi.local')->post(route('faturas.enviar', $fatura), [
            'chave' => (string) Str::uuid(), 'destinatario' => $cliente->email,
        ])->assertSessionHas('erro', EnvioFatura::MOTIVOS['transporte']);
        $this->assertDatabaseHas('eventos', ['acao' => EnvioFatura::FALHOU, 'alvo_id' => $fatura->id]);
        $this->get(route('clientes.historico', [$cliente, 'tipo' => 'comunicacao']))
            ->assertOk()->assertSee(EnvioFatura::MOTIVOS['transporte'])->assertDontSee('informação privada do SMTP');
    }
}
