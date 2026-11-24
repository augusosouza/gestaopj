<?php

namespace App\Models;

use App\Enums\Papel;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Identidade apenas. Papéis e acesso vêm dos vínculos com organizações,
 * e uma mesma pessoa pode atuar em várias empresas e escritórios.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'admin_plataforma' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function exigeDoisFatores(): bool
    {
        return config('gestao.exigir_2fa') && ($this->admin_plataforma
            || $this->vinculos()->vigentes()->whereIn('papel', [Papel::Admin->value, Papel::Revisor->value])->exists());
    }

    public function temDoisFatores(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function vinculos(): HasMany
    {
        return $this->hasMany(Vinculo::class);
    }
}
