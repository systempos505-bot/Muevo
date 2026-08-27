<div>
    <x-page-header title="Negocio" subtitle="Datos que salen en el ticket y en el motor de precios" />

    @include('partials.settings-tabs')

    <form wire:submit="save" class="max-w-2xl space-y-6">
        <x-card title="Identidad" description="El nombre comercial es el que se imprime en el ticket">
            <div class="space-y-4">
                <x-input label="Nombre del negocio" wire:model="name" placeholder="Boutique Las Rosas"
                         :error="$errors->first('name')" />

                <x-input label="Nombre comercial (opcional)" wire:model="tradeName"
                         hint="Si no se llena, se usa el nombre del negocio"
                         :error="$errors->first('tradeName')" />

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-input label="Razon social (opcional)" wire:model="legalName"
                             :error="$errors->first('legalName')" />

                    <x-input label="RTN / ID fiscal (opcional)" wire:model="taxIdNumber"
                             :error="$errors->first('taxIdNumber')" />
                </div>
            </div>
        </x-card>

        <x-card title="Contacto" description="Tambien sale en el ticket">
            <div class="space-y-4">
                <x-input label="Direccion (opcional)" wire:model="address"
                         :error="$errors->first('address')" />

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-input label="Telefono (opcional)" wire:model="phone"
                             :error="$errors->first('phone')" />

                    <x-input label="WhatsApp (opcional)" wire:model="whatsapp"
                             :error="$errors->first('whatsapp')" />
                </div>

                <x-input label="Correo de la empresa" type="email" wire:model="email"
                         :error="$errors->first('email')" />
            </div>
        </x-card>

        <x-card title="Moneda e impuesto" description="Lo que usa el motor de precios en cada venta">
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <x-input label="Simbolo de la moneda" wire:model="currencySymbol" placeholder="L"
                             :error="$errors->first('currencySymbol')" />

                    <x-input label="Decimales de la moneda" type="number" min="0" max="4"
                             wire:model="currencyDecimals"
                             :error="$errors->first('currencyDecimals')" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <x-input label="Impuesto por defecto (%)" type="number" step="0.01" min="0" max="100"
                             wire:model="taxRate"
                             hint="Cambiarlo afecta a los productos que aun no tienen su propio impuesto"
                             :error="$errors->first('taxRate')" />

                    <x-input label="Decimales de precio" type="number" min="0" max="4"
                             wire:model="priceDecimals"
                             :error="$errors->first('priceDecimals')" />
                </div>

                <div class="rounded-lg bg-slate-50 border border-slate-200 px-4 py-3">
                    <p class="text-sm text-slate-700">
                        Los precios
                        <strong>{{ $pricesIncludeTax ? 'ya llevan' : 'no llevan' }}</strong>
                        el impuesto incluido.
                    </p>
                    <p class="text-xs text-slate-500 mt-1">
                        Esto se elige al crear la empresa y no se cambia despues: hacerlo
                        reinterpretaria de golpe cada precio ya guardado, como si el negocio
                        hubiera subido sus precios de la noche a la manana sin querer.
                    </p>
                </div>
            </div>
        </x-card>

        @can('settings.edit')
            <x-button type="submit">Guardar cambios</x-button>
        @endcan
    </form>
</div>
