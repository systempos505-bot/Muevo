<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.guest')]
class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate();
        $this->ensureNotRateLimited();

        // El correo es unico por empresa, no en todo el sistema, asi que
        // hay que buscar sin el filtro de empresa: todavia no se sabe a
        // cual pertenece quien esta entrando.
        $user = Tenancy::withoutScope(
            fn () => User::where('email', $this->email)->where('status', 'active')->first(),
        );

        if (! $user || ! Auth::attempt(['id' => $user->id, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            // Mismo mensaje exista o no el correo: distinguirlos revelaria
            // que cuentas estan registradas.
            throw ValidationException::withMessages([
                'email' => 'Las credenciales no coinciden con nuestros registros.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        Tenancy::set($user->tenant_id);
        $user->forceFill(['last_login_at' => now()])->save();

        $this->redirectRoute('dashboard', navigate: true);
    }

    /**
     * Cinco intentos por correo y direccion IP. Sin esto, probar
     * contrasenas a fuerza bruta seria cuestion de tiempo.
     */
    protected function ensureNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), maxAttempts: 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Demasiados intentos. Vuelve a intentar en {$seconds} segundos.",
        ]);
    }

    protected function throttleKey(): string
    {
        return str()->transliterate(mb_strtolower($this->email).'|'.request()->ip());
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
