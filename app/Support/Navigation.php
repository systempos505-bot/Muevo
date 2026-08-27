<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * Menu de navegacion del sistema.
 *
 * Vive fuera de los componentes Livewire porque el layout tambien lo
 * necesita, y ahi no hay una instancia del componente disponible.
 * Agregar un modulo nuevo es tocar un solo arreglo.
 */
class Navigation
{
    /**
     * @return array<int, array{
     *     label: string, icon: string, route: string,
     *     match: string, permission: ?string, primary: bool
     * }>
     */
    protected static function definition(): array
    {
        return [
            ['label' => 'Inicio',     'icon' => '⌂', 'route' => 'dashboard',          'match' => 'dashboard', 'permission' => null,             'primary' => true],
            ['label' => 'Vender',     'icon' => '⊞', 'route' => 'pos',                'match' => 'pos',       'permission' => 'sales.create',   'primary' => true],
            ['label' => 'Productos',  'icon' => '▤', 'route' => 'products',           'match' => 'products',  'permission' => 'products.view',  'primary' => true],
            ['label' => 'Inventario', 'icon' => '▣', 'route' => 'inventory',          'match' => 'inventory', 'permission' => 'inventory.view', 'primary' => false],
            ['label' => 'Traspasos',  'icon' => '⇄', 'route' => 'transfers',          'match' => 'transfers', 'permission' => 'inventory.view', 'primary' => false],
            ['label' => 'Conteos',    'icon' => '☑', 'route' => 'stock-counts',       'match' => 'stock-counts', 'permission' => 'inventory.view', 'primary' => false],
            ['label' => 'Ventas',     'icon' => '◫', 'route' => 'sales',              'match' => 'sales',     'permission' => 'sales.view',     'primary' => true],
            ['label' => 'Caja',       'icon' => '▦', 'route' => 'cash',               'match' => 'cash',      'permission' => 'cash.open',      'primary' => true],
            ['label' => 'Devoluciones', 'icon' => '↩', 'route' => 'returns',          'match' => 'returns',   'permission' => 'sales.view',     'primary' => false],
            ['label' => 'Compras',    'icon' => '▽', 'route' => 'purchases',          'match' => 'purchases', 'permission' => 'purchases.view', 'primary' => false],
            ['label' => 'Cuentas',    'icon' => '◈', 'route' => 'accounts',           'match' => 'accounts',  'permission' => 'finance.view',   'primary' => false],
            ['label' => 'Gastos',     'icon' => '◇', 'route' => 'expenses',           'match' => 'expenses',  'permission' => 'expenses.view',  'primary' => false],
            ['label' => 'Proveedores', 'icon' => '☖', 'route' => 'suppliers',          'match' => 'suppliers', 'permission' => 'purchases.view', 'primary' => false],
            ['label' => 'Catalogo',   'icon' => '☰', 'route' => 'catalog.categories', 'match' => 'catalog',   'permission' => 'products.view',  'primary' => false],
            ['label' => 'Clientes',   'icon' => '☺', 'route' => 'customers',          'match' => 'customers', 'permission' => 'customers.view', 'primary' => false],
            ['label' => 'Promociones', 'icon' => '✽', 'route' => 'promotions',        'match' => 'promotions', 'permission' => 'promotions.view', 'primary' => false],
            ['label' => 'Reportes',   'icon' => '◔', 'route' => 'reports',            'match' => 'reports',   'permission' => 'reports.view',   'primary' => true],
            ['label' => 'Negocio',    'icon' => '⚙', 'route' => 'business',           'match' => 'business',  'permission' => 'settings.view',  'primary' => false],
            ['label' => 'Sucursales', 'icon' => '⊟', 'route' => 'branches',           'match' => 'branches',  'permission' => 'settings.view',  'primary' => false],
            ['label' => 'Usuarios',   'icon' => '☻', 'route' => 'users',              'match' => 'users',     'permission' => 'users.view',     'primary' => false],
        ];
    }

    /**
     * Opciones visibles para el usuario conectado.
     *
     * Se filtran por permiso, para no ofrecer una pantalla que el sistema
     * va a negar despues. En celular caben cinco botones y el ultimo lo
     * ocupa "Mas", asi que `primary` recorta a las cuatro mas usadas y el
     * resto se alcanza desde ese menu.
     *
     * @return array<int, array{label: string, icon: string, url: string, active: bool}>
     */
    public static function items(bool $primary = false): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        return collect(static::definition())
            ->filter(fn (array $item) => $item['permission'] === null || $user->can($item['permission']))
            // Una ruta de un modulo aun no construido no debe romper el menu.
            ->filter(fn (array $item) => Route::has($item['route']))
            ->when($primary, fn ($items) => $items->where('primary', true)->take(4))
            ->map(fn (array $item) => [
                'label' => $item['label'],
                'icon' => $item['icon'],
                'url' => route($item['route']),
                // Se marca activo por familia de rutas, para que las
                // pantallas hijas resalten la seccion a la que pertenecen.
                'active' => request()->routeIs($item['match'].'*'),
            ])
            ->values()
            ->all();
    }
}
