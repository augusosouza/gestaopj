<?php

namespace App\Support;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** TOTP (RFC 6238), compatível com Google Authenticator, Microsoft Authenticator, 1Password etc. */
final class DoisFatores
{
    public function __construct(private Google2FA $g2fa = new Google2FA) {}

    public function novoSegredo(): string
    {
        return $this->g2fa->generateSecretKey(32);
    }

    public function qrSvg(User $user, string $segredo): string
    {
        $uri = $this->g2fa->getQRCodeUrl(config('app.name'), $user->email, $segredo);
        $writer = new Writer(new ImageRenderer(new RendererStyle(184, 1), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }

    /** Valida o código contra um segredo ainda não salvo (cadastro). */
    public function codigoValido(string $segredo, string $codigo): bool
    {
        return (bool) $this->g2fa->verifyKey($segredo, $this->normalizar($codigo), 1);
    }

    /**
     * Valida o código do usuário e registra o intervalo usado, recusando reutilização.
     */
    public function verificar(User $user, string $codigo): bool
    {
        $ts = $this->g2fa->verifyKeyNewer($user->two_factor_secret, $this->normalizar($codigo), $user->two_factor_ultimo_uso, 1);
        if ($ts === false) {
            return false;
        }
        $user->forceFill(['two_factor_ultimo_uso' => $ts === true ? $this->g2fa->getTimestamp() : $ts])->save();

        return true;
    }

    /** Consome um código de recuperação (uso único). */
    public function usarRecuperacao(User $user, string $codigo): bool
    {
        $codigos = $user->two_factor_recovery_codes ?? [];
        $codigo = Str::upper(trim($codigo));
        foreach ($codigos as $i => $c) {
            if (hash_equals($c, $codigo)) {
                unset($codigos[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codigos)])->save();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function novosCodigosRecuperacao(): array
    {
        return collect(range(1, 8))->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))->all();
    }

    private function normalizar(string $codigo): string
    {
        return preg_replace('/\D/', '', $codigo);
    }
}
