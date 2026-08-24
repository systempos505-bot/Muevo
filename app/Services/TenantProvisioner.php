<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Currency;
use App\Models\CustomerType;
use App\Models\DocumentSeries;
use App\Models\PaymentMethod;
use App\Models\PriceList;
use App\Models\Role;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\Terminal;
use App\Models\Unit;
use App\Support\Tenancy;

/**
 * Deja lista una empresa recien registrada.
 *
 * Sin esto el usuario entraria a un sistema vacio y tendria que pasar por
 * diez pantallas de configuracion antes de poder crear su primer producto.
 * Aqui se crean sucursal, caja, moneda, impuesto, unidades, listas de
 * precios y categorias acordes a su giro.
 */
class TenantProvisioner
{
    /** Giros soportados. */
    public const BUSINESS_TYPES = [
        'pharmacy' => 'Farmacia',
        'clothing' => 'Tienda de ropa',
        'footwear' => 'Zapateria',
        'hardware' => 'Ferreteria',
        'supermarket' => 'Supermercado',
        'general' => 'Otro giro',
    ];

    /**
     * Valores por defecto al crear un producto, segun el giro.
     * El usuario los puede cambiar; esto solo evita que tenga que activar
     * lotes y vencimiento uno por uno en una farmacia.
     */
    public const PRODUCT_DEFAULTS = [
        'pharmacy' => ['track_lots' => true, 'track_expiry' => true, 'track_serials' => false, 'variants' => false],
        'supermarket' => ['track_lots' => true, 'track_expiry' => true, 'track_serials' => false, 'variants' => false],
        'clothing' => ['track_lots' => false, 'track_expiry' => false, 'track_serials' => false, 'variants' => true],
        'footwear' => ['track_lots' => false, 'track_expiry' => false, 'track_serials' => false, 'variants' => true],
        'hardware' => ['track_lots' => false, 'track_expiry' => false, 'track_serials' => true, 'variants' => false],
        'general' => ['track_lots' => false, 'track_expiry' => false, 'track_serials' => false, 'variants' => false],
    ];

    /** Categorias iniciales sugeridas por giro. */
    protected const CATEGORIES = [
        'pharmacy' => ['Medicamentos', 'Cuidado personal', 'Bebe', 'Vitaminas', 'Primeros auxilios'],
        'clothing' => ['Caballero', 'Dama', 'Nino', 'Accesorios'],
        'footwear' => ['Caballero', 'Dama', 'Nino', 'Deportivo'],
        'hardware' => ['Herramientas', 'Electrico', 'Plomeria', 'Pinturas', 'Ferreteria general'],
        'supermarket' => ['Abarrotes', 'Bebidas', 'Lacteos', 'Limpieza', 'Carnes', 'Frutas y verduras'],
        'general' => ['General'],
    ];

    /** Unidades de medida que sirven a cualquier giro. */
    protected const UNITS = [
        ['UND', 'Unidad', 'Unidades', false],
        ['CJA', 'Caja', 'Cajas', false],
        ['DOC', 'Docena', 'Docenas', false],
        ['PAQ', 'Paquete', 'Paquetes', false],
        ['KG', 'Kilogramo', 'Kilogramos', true],
        ['LB', 'Libra', 'Libras', true],
        ['LT', 'Litro', 'Litros', true],
        ['MT', 'Metro', 'Metros', true],
    ];

    /** Listas de precios iniciales. La primera es la de mostrador. */
    protected const PRICE_LISTS = ['Publico', 'Mayoreo', 'Distribuidor'];

    /**
     * Formas de pago iniciales.
     * [codigo, nombre, tipo, entra al cajon, permite cambio]
     */
    protected const PAYMENT_METHODS = [
        ['EFE', 'Efectivo', 'cash', true, true],
        ['TAR', 'Tarjeta', 'card', false, false],
        ['TRA', 'Transferencia', 'transfer', false, false],
        ['CRE', 'Credito', 'credit', false, false],
    ];

    /** Permisos de cada rol de sistema. */
    public const ROLES = [
        [
            'code' => 'admin',
            'name' => 'Administrador',
            'permissions' => ['*' => true],
        ],
        [
            'code' => 'cashier',
            'name' => 'Cajero',
            'permissions' => [
                'sales.create' => true,
                'sales.view' => true,
                'products.view' => true,
                'customers.view' => true,
                'customers.create' => true,
                'cash.open' => true,
                'cash.close' => true,
                'inventory.view' => true,
            ],
        ],
        [
            'code' => 'seller',
            'name' => 'Vendedor',
            'permissions' => [
                'sales.create' => true,
                'sales.view' => true,
                'sales.discount' => true,
                'products.view' => true,
                'customers.view' => true,
                'customers.create' => true,
                'customers.edit' => true,
                'quotes.create' => true,
            ],
        ],
        [
            'code' => 'inventory',
            'name' => 'Inventario',
            'permissions' => [
                'products.view' => true,
                'products.create' => true,
                'products.edit' => true,
                'inventory.view' => true,
                'inventory.adjust' => true,
                'inventory.count' => true,
                'purchases.view' => true,
                'purchases.create' => true,
            ],
        ],
    ];

    /**
     * Crea todo lo que la empresa necesita para operar.
     *
     * Debe correr dentro de una transaccion junto con la creacion del
     * tenant: una empresa a medio configurar no sirve para nada.
     *
     * @return array{branch: Branch, terminal: Terminal, admin_role: Role, price_list: PriceList, base_unit: Unit}
     */
    public function provision(
        Tenant $tenant,
        string $currencyCode = 'USD',
        string $currencySymbol = '$',
        float $taxRate = 0,
    ): array {
        return Tenancy::forTenant($tenant->id, function () use ($tenant, $currencyCode, $currencySymbol, $taxRate) {
            $roles = $this->createRoles();
            $this->createCurrency($currencyCode, $currencySymbol);
            $this->createTax($taxRate);
            $units = $this->createUnits();
            $priceLists = $this->createPriceLists();
            $this->createPaymentMethods();
            $this->createCategories($tenant->business_type);

            CustomerType::create([
                'name' => 'General',
                'price_list_id' => $priceLists[0]->id,
                'is_default' => true,
            ]);

            $branch = Branch::create([
                'code' => 'PRIN',
                'name' => 'Sucursal Principal',
                'is_default' => true,
            ]);

            $terminal = Terminal::create([
                'branch_id' => $branch->id,
                'code' => 'CAJA1',
                'name' => 'Caja 1',
                'folio_prefix' => 'C1',
            ]);

            $this->createDocumentSeries($branch);

            return [
                'branch' => $branch,
                'terminal' => $terminal,
                'admin_role' => $roles['admin'],
                'price_list' => $priceLists[0],
                'base_unit' => $units['UND'],
            ];
        });
    }

    /** @return array<string, Role> */
    protected function createRoles(): array
    {
        $roles = [];

        foreach (self::ROLES as $role) {
            $roles[$role['code']] = Role::create([
                'code' => $role['code'],
                'name' => $role['name'],
                'permissions' => $role['permissions'],
                'is_system' => true,
            ]);
        }

        return $roles;
    }

    protected function createCurrency(string $code, string $symbol): void
    {
        Currency::create([
            'code' => $code,
            'name' => $code,
            'symbol' => $symbol,
            'is_primary' => true,
            'rate' => 1,
        ]);
    }

    protected function createTax(float $rate): void
    {
        Tax::create([
            'code' => $rate > 0 ? 'IVA' : 'EXENTO',
            'name' => $rate > 0 ? "IVA {$rate}%" : 'Exento',
            'rate' => $rate,
            'is_default' => true,
        ]);
    }

    /** @return array<string, Unit> */
    protected function createUnits(): array
    {
        $units = [];

        foreach (self::UNITS as [$code, $name, $plural, $decimals]) {
            $units[$code] = Unit::create([
                'code' => $code,
                'name' => $name,
                'plural_name' => $plural,
                'allows_decimals' => $decimals,
            ]);
        }

        return $units;
    }

    /** @return array<int, PriceList> */
    protected function createPriceLists(): array
    {
        $lists = [];

        foreach (self::PRICE_LISTS as $index => $name) {
            $lists[] = PriceList::create([
                'name' => $name,
                'position' => $index,
                'is_default' => $index === 0,
            ]);
        }

        return $lists;
    }

    protected function createPaymentMethods(): void
    {
        foreach (self::PAYMENT_METHODS as $position => [$code, $name, $type, $drawer, $change]) {
            PaymentMethod::create([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'affects_drawer' => $drawer,
                'allows_change' => $change,
                'position' => $position,
            ]);
        }
    }

    protected function createCategories(string $businessType): void
    {
        $names = self::CATEGORIES[$businessType] ?? self::CATEGORIES['general'];

        foreach ($names as $index => $name) {
            Category::create(['name' => $name, 'position' => $index]);
        }
    }

    protected function createDocumentSeries(Branch $branch): void
    {
        foreach (['sale', 'quote', 'credit_note', 'purchase', 'expense'] as $docType) {
            DocumentSeries::create([
                'branch_id' => $branch->id,
                'doc_type' => $docType,
                'prefix' => $docType === 'sale' ? 'V-' : '',
            ]);
        }
    }
}
