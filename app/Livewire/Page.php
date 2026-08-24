<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * Base de las pantallas del sistema.
 *
 * Concentra el menu y los avisos para que cada pantalla no repita lo
 * mismo, y para que agregar un modulo nuevo sea tocar un solo lugar.
 */
abstract class Page extends Component
{
    /**
     * Menu de navegacion.
     *
     * Se filtra por permisos: a un cajero no se le muestra una opcion que
     * el sistema le va a negar despues. En celular solo caben cinco, asi
     * que `primary` recorta a las mas usadas.
     *
     * @return array<int, array{label: string, icon: string, url: string, active: bool}>
     */
    public function navigation(bool $primary = false): array
    {
        $user = auth()->user();

        $items = [
            ['label' => 'Inicio',     'icon' => '⌂', 'route' => 'dashboard',  'permission' => null,             'primary' => true],
            ['label' => 'Vender',     'icon' => '⊞', 'route' => 'pos',        'permission' => 'sales.create',   'primary' => true],
            ['label' => 'Productos',  'icon' => '▤', 'route' => 'products',   'permission' => 'products.view',  'primary' => true],
            ['label' => 'Inventario', 'icon' => '▣', 'route' => 'inventory',  'permission' => 'inventory.view', 'primary' => true],
            ['label' => 'Categorias', 'icon' => '☰', 'route' => 'categories', 'permission' => 'products.view',  'primary' => false],
            ['label' => 'Precios',    'icon' => '$', 'route' => 'price-lists', 'permission' => 'products.view',  'primary' => false],
            ['label' => 'Clientes',   'icon' => '☺', 'route' => 'customers',  'permission' => 'customers.view', 'primary' => false],
            ['label' => 'Reportes',   'icon' => '◔', 'route' => 'reports',    'permission' => 'reports.view',   'primary' => true],
        ];

        return collect($items)
            ->filter(fn (array $item) => $item['permission'] === null || $user->can($item['permission']))
            // Una ruta aun no implementada no debe romper el menu.
            ->filter(fn (array $item) => Route::has($item['route']))
            ->when($primary, fn ($c) => $c->where('primary', true)->take(5))
            ->map(fn (array $item) => [
                'label' => $item['label'],
                'icon' => $item['icon'],
                'url' => route($item['route']),
                'active' => request()->routeIs($item['route'].'*'),
            ])
            ->values()
            ->all();
    }

    /** Aviso breve en pantalla. */
    protected function notify(string $message, string $kind = 'success'): void
    {
        $this->dispatch('notify', message: $message, kind: $kind);
    }
}
