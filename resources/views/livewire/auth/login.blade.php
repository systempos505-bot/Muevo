<div>
    <h2 class="text-xl font-semibold text-slate-900">Iniciar sesion</h2>
    <p class="mt-1 text-sm text-slate-500">Entra a tu negocio</p>

    <form wire:submit="login" class="mt-6 space-y-4">
        <x-input
            label="Correo"
            type="email"
            wire:model="email"
            autocomplete="username"
            inputmode="email"
            placeholder="tu@correo.com"
            :error="$errors->first('email')"
            autofocus
        />

        <x-input
            label="Contrasena"
            type="password"
            wire:model="password"
            autocomplete="current-password"
            placeholder="••••••••"
            :error="$errors->first('password')"
        />

        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" wire:model="remember"
                   class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
            Mantener sesion iniciada
        </label>

        <x-button type="submit" size="lg" class="w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">Entrar</span>
            <span wire:loading wire:target="login">Entrando...</span>
        </x-button>
    </form>

    <p class="mt-6 text-center text-sm text-slate-500">
        Aun no tienes cuenta?
        <a href="{{ route('register') }}" wire:navigate
           class="font-medium text-indigo-600 hover:text-indigo-500">Registra tu negocio</a>
    </p>
</div>
