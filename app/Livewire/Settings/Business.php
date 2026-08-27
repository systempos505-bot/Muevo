<?php

namespace App\Livewire\Settings;

use App\Livewire\Page;
use App\Models\Currency;
use App\Models\Tax;
use App\Models\Tenant;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;

/**
 * Datos del negocio: identidad, contacto, moneda e impuesto.
 *
 * Es lo que sale impreso en el ticket y lo que usa el motor de precios
 * en cada venta, asi que vive en un solo lugar en vez de repartirse entre
 * pantallas sueltas.
 */
#[Layout('layouts.app')]
class Business extends Page
{
    public string $name = '';

    public string $tradeName = '';

    public string $legalName = '';

    public string $taxIdNumber = '';

    public string $address = '';

    public string $phone = '';

    public string $whatsapp = '';

    public string $email = '';

    /**
     * Si los precios ya llevan el impuesto adentro. Solo se lee, no se
     * cambia aqui: voltearlo reinterpretaria de golpe cada precio ya
     * guardado, como si el negocio hubiera subido sus precios de la
     * noche a la manana sin querer.
     */
    public bool $pricesIncludeTax = true;

    public int $priceDecimals = 2;

    // --- Moneda principal ---
    public string $currencySymbol = '';

    public int $currencyDecimals = 2;

    // --- Impuesto por defecto ---
    public float $taxRate = 0;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('settings.view'), 403);

        $tenant = auth()->user()->tenant;

        $this->name = $tenant->name;
        $this->tradeName = (string) $tenant->trade_name;
        $this->legalName = (string) $tenant->legal_name;
        $this->taxIdNumber = (string) $tenant->tax_id;
        $this->address = (string) $tenant->address;
        $this->phone = (string) $tenant->phone;
        $this->whatsapp = (string) $tenant->whatsapp;
        $this->email = $tenant->email;
        $this->pricesIncludeTax = $tenant->prices_include_tax;
        $this->priceDecimals = $tenant->price_decimals;

        $currency = $tenant->primaryCurrency;
        $this->currencySymbol = (string) $currency?->symbol;
        $this->currencyDecimals = $currency?->decimals ?? 2;

        $this->taxRate = (float) (Tax::where('is_default', true)->value('rate') ?? 0);
    }

    protected function rules(): array
    {
        $tenantId = auth()->user()->tenant_id;

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'tradeName' => ['nullable', 'string', 'max:120'],
            'legalName' => ['nullable', 'string', 'max:160'],
            'taxIdNumber' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'email' => [
                'required', 'email', 'max:120',
                Rule::unique('tenants', 'email')->ignore($tenantId),
            ],
            'priceDecimals' => ['required', 'integer', 'min:0', 'max:4'],
            'currencySymbol' => ['required', 'string', 'max:8'],
            'currencyDecimals' => ['required', 'integer', 'min:0', 'max:4'],
            'taxRate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'El negocio necesita un nombre.',
            'email.unique' => 'Ya hay otra empresa registrada con ese correo.',
            'currencySymbol.required' => 'Indica el simbolo de tu moneda.',
        ];
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('settings.edit'), 403);

        $data = $this->validate();

        $tenant = Tenant::findOrFail(auth()->user()->tenant_id);

        $attributes = [
            'name' => $data['name'],
            'trade_name' => $data['tradeName'] ?: null,
            'legal_name' => $data['legalName'] ?: null,
            'tax_id' => $data['taxIdNumber'] ?: null,
            'address' => $data['address'] ?: null,
            'phone' => $data['phone'] ?: null,
            'whatsapp' => $data['whatsapp'] ?: null,
            'email' => $data['email'],
            'price_decimals' => $data['priceDecimals'],
        ];

        $tenant->update($attributes);

        Currency::where('is_primary', true)->update([
            'symbol' => $data['currencySymbol'],
            'decimals' => $data['currencyDecimals'],
        ]);

        Tax::where('is_default', true)->update(['rate' => $data['taxRate']]);

        $this->notify('Configuracion guardada');
    }

    public function render()
    {
        return view('livewire.settings.business');
    }
}
