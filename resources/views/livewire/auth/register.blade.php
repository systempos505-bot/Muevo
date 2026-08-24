<div>
    <h2 class="text-xl font-semibold text-slate-900">Registra tu negocio</h2>
    <p class="mt-1 text-sm text-slate-500">Toma menos de un minuto</p>

    <form wire:submit="register" class="mt-6 space-y-4">

        <x-input
            label="Nombre del negocio"
            wire:model="businessName"
            placeholder="Farmacia La Salud"
            :error="$errors->first('businessName')"
            autofocus
        />

        <div class="space-y-1.5">
            <label class="block text-sm font-medium text-slate-700">Giro del negocio</label>
            <div class="grid grid-cols-2 gap-2">
                @foreach ($businessTypes as $value => $label)
                    <button type="button" wire:click="$set('businessType', '{{ $value }}')"
                        @class([
                            'px-3 py-2.5 rounded-lg border text-sm font-medium text-left transition',
                            'border-indigo-500 bg-indigo-50 text-indigo-700' => $businessType === $value,
                            'border-slate-300 text-slate-700 hover:bg-slate-50' => $businessType !== $value,
                        ])>
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            {{-- El giro decide que se activa por defecto al crear productos:
                 lotes y vencimiento en farmacia, tallas en ropa. --}}
            <p class="text-xs text-slate-500">
                Ajusta las opciones de tus productos. Lo puedes cambiar despues.
            </p>
        </div>

        <x-input
            label="Tu nombre"
            wire:model="ownerName"
            placeholder="Maria Lopez"
            :error="$errors->first('ownerName')"
        />

        <x-input
            label="Correo"
            type="email"
            wire:model="email"
            autocomplete="username"
            inputmode="email"
            placeholder="tu@correo.com"
            :error="$errors->first('email')"
        />

        <div class="grid grid-cols-2 gap-3">
            <x-input
                label="Contrasena"
                type="password"
                wire:model="password"
                autocomplete="new-password"
                :error="$errors->first('password')"
            />
            <x-input
                label="Repetir"
                type="password"
                wire:model="password_confirmation"
                autocomplete="new-password"
            />
        </div>

        <div class="grid grid-cols-3 gap-3">
            <x-input
                label="Moneda"
                wire:model="currencyCode"
                maxlength="3"
                placeholder="USD"
                :error="$errors->first('currencyCode')"
            />
            <x-input
                label="Simbolo"
                wire:model="currencySymbol"
                maxlength="4"
                placeholder="$"
            />
            <x-input
                label="Impuesto %"
                type="number"
                step="0.01"
                min="0"
                max="100"
                wire:model="taxRate"
                inputmode="decimal"
                :error="$errors->first('taxRate')"
            />
        </div>

        <x-button type="submit" size="lg" class="w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="register">Crear mi negocio</span>
            <span wire:loading wire:target="register">Creando...</span>
        </x-button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-500">
        Ya tienes cuenta?
        <a href="{{ route('login') }}" wire:navigate
           class="font-medium text-indigo-600 hover:text-indigo-500">Inicia sesion</a>
    </p>
</div>
