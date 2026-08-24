<?php

namespace App\Livewire\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Alta de una empresa nueva.
 *
 * Crea el negocio, su primer usuario administrador y toda la
 * configuracion inicial en una sola transaccion: si algo falla, no queda
 * una empresa a medio configurar con la que nadie pueda trabajar.
 */
#[Layout('layouts.guest')]
class Register extends Component
{
    #[Validate('required|string|min:2|max:120')]
    public string $businessName = '';

    #[Validate('required|string|min:2|max:120')]
    public string $ownerName = '';

    public string $email = '';

    #[Validate('required|string|min:8|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    #[Validate('required')]
    public string $businessType = 'general';

    #[Validate('nullable|string|size:3')]
    public string $currencyCode = 'USD';

    #[Validate('nullable|string|max:4')]
    public string $currencySymbol = '$';

    #[Validate('required|numeric|min:0|max:100')]
    public float $taxRate = 0;

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:160', Rule::unique('tenants', 'email')],
            'businessType' => ['required', Rule::in(array_keys(TenantProvisioner::BUSINESS_TYPES))],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Ya hay una cuenta registrada con ese correo.',
            'password.confirmed' => 'Las contrasenas no coinciden.',
            'password.min' => 'La contrasena debe tener al menos 8 caracteres.',
        ];
    }

    public function register(): void
    {
        $data = $this->validate();

        $user = DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name' => $data['businessName'],
                'email' => $data['email'],
                'business_type' => $data['businessType'],
            ]);

            $setup = app(TenantProvisioner::class)->provision(
                $tenant,
                $data['currencyCode'] ?: 'USD',
                $data['currencySymbol'] ?: '$',
                (float) $data['taxRate'],
            );

            return Tenancy::forTenant($tenant->id, fn () => User::create([
                'branch_id' => $setup['branch']->id,
                'role_id' => $setup['admin_role']->id,
                'name' => $data['ownerName'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]));
        });

        auth()->login($user, remember: true);
        session()->regenerate();

        $this->redirectRoute('dashboard', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.register', [
            'businessTypes' => TenantProvisioner::BUSINESS_TYPES,
        ]);
    }
}
