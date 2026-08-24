<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#0f172a">
    <title>{{ $title ?? 'Muevo POS' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-900">

<div class="min-h-full flex flex-col justify-center px-4 py-10 sm:px-6">
    <div class="w-full max-w-md mx-auto">

        <div class="text-center mb-8">
            <span class="inline-grid place-items-center w-12 h-12 rounded-xl bg-indigo-500 text-white text-xl font-bold">M</span>
            <h1 class="mt-4 text-2xl font-semibold text-white">Muevo POS</h1>
            <p class="mt-1 text-sm text-slate-400">Sistema de facturacion y punto de venta</p>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 sm:p-8">
            {{ $slot }}
        </div>

    </div>
</div>

</body>
</html>
