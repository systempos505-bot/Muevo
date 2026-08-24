<?php

namespace App\Support;

/**
 * Catalogo de permisos del sistema.
 *
 * Vive en un solo lugar porque lo necesitan tres cosas a la vez: la
 * pantalla de roles para dibujar la lista, la de usuarios para las
 * excepciones, y quien lee el codigo para saber que permisos existen sin
 * ir a buscarlos por todo el proyecto.
 *
 * Cada permiso lleva su explicacion en palabras del negocio. Un rol se
 * arma pensando "que puede hacer esta persona", no descifrando el nombre
 * tecnico de una clave.
 */
class Permissions
{
    /**
     * Permiso que abre todo. Lo tiene el administrador.
     *
     * No se ofrece en la pantalla: se da con el rol de administrador, no
     * marcando una casilla.
     */
    public const WILDCARD = '*';

    /**
     * @return array<string, array{label: string, icon: string, abilities: array<string, string>}>
     */
    public static function catalog(): array
    {
        return [
            'sales' => [
                'label' => 'Ventas y caja',
                'icon' => '⊞',
                'abilities' => [
                    'sales.create' => 'Vender en la caja',
                    'sales.view' => 'Ver el historial de ventas',
                    'sales.discount' => 'Dar descuentos a mano',
                    'sales.return' => 'Registrar devoluciones',
                    'sales.void' => 'Anular ventas',
                    'cash.open' => 'Abrir la caja',
                    'cash.close' => 'Hacer el corte de caja',
                ],
            ],
            'products' => [
                'label' => 'Productos',
                'icon' => '▤',
                'abilities' => [
                    'products.view' => 'Ver el catalogo',
                    'products.create' => 'Crear productos',
                    'products.edit' => 'Editar productos y precios',
                ],
            ],
            'inventory' => [
                'label' => 'Inventario',
                'icon' => '▣',
                'abilities' => [
                    'inventory.view' => 'Ver existencias y kardex',
                    'inventory.adjust' => 'Ajustar existencias y traspasar',
                    'inventory.count' => 'Hacer inventario fisico',
                ],
            ],
            'purchases' => [
                'label' => 'Compras',
                'icon' => '▽',
                'abilities' => [
                    'purchases.view' => 'Ver compras y proveedores',
                    'purchases.create' => 'Registrar compras',
                    'purchases.void' => 'Anular compras',
                ],
            ],
            'customers' => [
                'label' => 'Clientes',
                'icon' => '☺',
                'abilities' => [
                    'customers.view' => 'Ver clientes y sus saldos',
                    'customers.create' => 'Dar de alta clientes',
                    'customers.edit' => 'Editar clientes y su credito',
                ],
            ],
            'finance' => [
                'label' => 'Dinero',
                'icon' => '◈',
                'abilities' => [
                    'finance.view' => 'Ver cuentas y movimientos',
                    'finance.manage' => 'Mover dinero entre cuentas',
                    'expenses.view' => 'Ver gastos',
                    'expenses.create' => 'Registrar gastos',
                    'expenses.void' => 'Anular gastos',
                ],
            ],
            'management' => [
                'label' => 'Administracion',
                'icon' => '◔',
                'abilities' => [
                    'reports.view' => 'Ver reportes del negocio',
                    'promotions.view' => 'Ver promociones',
                    'promotions.manage' => 'Crear y editar promociones',
                    'users.view' => 'Ver usuarios',
                    'users.manage' => 'Dar de alta y editar usuarios',
                    'settings.view' => 'Ver la configuracion',
                    'settings.edit' => 'Cambiar la configuracion',
                ],
            ],
        ];
    }

    /**
     * Todas las claves de permiso, sin agrupar.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return collect(static::catalog())
            ->flatMap(fn (array $group) => array_keys($group['abilities']))
            ->all();
    }

    /** El texto de un permiso, o su clave si es uno que ya no existe. */
    public static function label(string $ability): string
    {
        foreach (static::catalog() as $group) {
            if (isset($group['abilities'][$ability])) {
                return $group['abilities'][$ability];
            }
        }

        return $ability;
    }

    /**
     * La clave del permiso sin puntos.
     *
     * Livewire interpreta el punto de un `wire:model` como un nivel mas
     * de arreglo, asi que "sales.create" se guardaria como
     * ['sales']['create'] y no como el permiso que es.
     */
    public static function key(string $ability): string
    {
        return str_replace('.', '__', $ability);
    }

    /** El camino de vuelta, de la clave de pantalla al permiso. */
    public static function ability(string $key): string
    {
        return str_replace('__', '.', $key);
    }

    /**
     * Deja solo los permisos que existen, en true.
     *
     * Lo que llega de una pantalla no se guarda tal cual: una clave
     * inventada quedaria en la base para siempre sin significar nada.
     *
     * @param  array<int, string>  $abilities
     * @return array<string, bool>
     */
    public static function toMap(array $abilities): array
    {
        $known = static::all();

        return collect($abilities)
            ->filter(fn ($ability) => in_array($ability, $known, true))
            ->unique()
            ->mapWithKeys(fn (string $ability) => [$ability => true])
            ->all();
    }

    /**
     * Las claves marcadas de un mapa de permisos.
     *
     * @param  array<string, bool>|null  $map
     * @return array<int, string>
     */
    public static function fromMap(?array $map): array
    {
        return collect($map ?? [])
            ->filter(fn ($enabled) => $enabled === true)
            ->keys()
            ->reject(fn (string $ability) => $ability === self::WILDCARD)
            ->values()
            ->all();
    }
}
