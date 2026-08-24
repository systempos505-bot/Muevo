<?php

namespace App\Livewire\Products;

use App\Livewire\Page;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CostHistory;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PriceHistory;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\ProductUnit;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\Unit;
use App\Services\Pricing;
use App\Services\TenantProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;

/**
 * Alta y edicion de productos.
 *
 * La pantalla esta partida en pestanas para que crear un producto simple
 * sea llenar dos campos, y solo quien necesite lotes, variantes o diez
 * precios tenga que entrar a lo demas.
 */
#[Layout('layouts.app')]
class Form extends Page
{
    public ?string $productId = null;

    public string $tab = 'general';

    // --- Datos generales ---
    public string $name = '';

    public string $sku = '';

    public string $internalCode = '';

    public string $description = '';

    public string $categoryId = '';

    public string $brandId = '';

    public string $supplierId = '';

    public string $type = 'simple';

    public string $baseUnitId = '';

    public string $taxId = '';

    // --- Costo y precios ---
    public float $cost = 0;

    /** Un renglon por lista: ['price_list_id' => ..., 'price' => ..., 'margin' => ...] */
    public array $priceRows = [];

    // --- Inventario ---
    public bool $trackStock = true;

    public float $minStock = 0;

    public ?float $maxStock = null;

    public float $initialStock = 0;

    // --- Control avanzado ---
    public bool $trackLots = false;

    public bool $trackExpiry = false;

    public bool $trackSerials = false;

    public int $expiryAlertDays = 30;

    public int $expiryBlockDays = 0;

    // --- Presentaciones y codigos ---
    /** ['unit_id' => ..., 'factor' => ..., 'is_default' => ..., 'barcode' => ...] */
    public array $unitRows = [];

    public string $barcode = '';

    public function mount(?string $productId = null): void
    {
        abort_unless(auth()->user()->can('products.view'), 403);

        $this->productId = $productId;

        $productId ? $this->fillFromProduct() : $this->fillDefaults();
    }

    // =========================================================
    // Carga inicial
    // =========================================================

    /**
     * Valores por defecto de un producto nuevo, segun el giro del negocio.
     * Una farmacia arranca con lotes y vencimiento activados; una tienda
     * de ropa, con variantes sugeridas.
     */
    protected function fillDefaults(): void
    {
        $tenant = auth()->user()->tenant;
        $defaults = TenantProvisioner::PRODUCT_DEFAULTS[$tenant->business_type]
            ?? TenantProvisioner::PRODUCT_DEFAULTS['general'];

        $this->trackLots = $defaults['track_lots'];
        $this->trackExpiry = $defaults['track_expiry'];
        $this->trackSerials = $defaults['track_serials'];
        $this->type = $defaults['variants'] ? 'variable' : 'simple';

        $this->baseUnitId = Unit::active()->where('code', 'UND')->value('id')
            ?? Unit::active()->value('id') ?? '';

        $this->taxId = Tax::active()->where('is_default', true)->value('id') ?? '';

        // La unidad base siempre existe como presentacion con factor 1.
        $this->unitRows = [
            ['unit_id' => $this->baseUnitId, 'factor' => 1.0, 'is_default' => true, 'barcode' => ''],
        ];

        $this->priceRows = $this->priceLists
            ->map(fn (PriceList $list) => [
                'price_list_id' => $list->id,
                'name' => $list->name,
                'price' => 0.0,
                'margin' => null,
            ])
            ->all();
    }

    protected function fillFromProduct(): void
    {
        $product = Product::with(['units', 'prices', 'barcodes'])->findOrFail($this->productId);

        $this->name = $product->name;
        $this->sku = $product->sku;
        $this->internalCode = (string) $product->internal_code;
        $this->description = (string) $product->description;
        $this->categoryId = (string) $product->category_id;
        $this->brandId = (string) $product->brand_id;
        $this->supplierId = (string) $product->supplier_id;
        $this->type = $product->type;
        $this->baseUnitId = $product->base_unit_id;
        $this->taxId = (string) $product->tax_id;
        $this->cost = (float) $product->cost;
        $this->trackStock = $product->track_stock;
        $this->minStock = (float) $product->min_stock;
        $this->maxStock = $product->max_stock;
        $this->trackLots = $product->track_lots;
        $this->trackExpiry = $product->track_expiry;
        $this->trackSerials = $product->track_serials;
        $this->expiryAlertDays = $product->expiry_alert_days;
        $this->expiryBlockDays = $product->expiry_block_days;

        $this->unitRows = $product->units
            ->map(fn (ProductUnit $pu) => [
                'unit_id' => $pu->unit_id,
                'factor' => (float) $pu->factor,
                'is_default' => $pu->is_default,
                'barcode' => (string) $product->barcodes
                    ->firstWhere('product_unit_id', $pu->id)?->code,
            ])
            ->all();

        $this->barcode = (string) $product->barcodes
            ->first(fn (ProductBarcode $b) => $b->product_unit_id === null)?->code;

        $this->priceRows = $this->priceLists
            ->map(function (PriceList $list) use ($product) {
                $price = $product->prices
                    ->where('price_list_id', $list->id)
                    ->whereNull('variant_id')
                    ->whereNull('product_unit_id')
                    ->sortBy('min_quantity')
                    ->first();

                return [
                    'price_list_id' => $list->id,
                    'name' => $list->name,
                    'price' => (float) ($price?->price ?? 0),
                    'margin' => $price
                        ? Pricing::margin($product->cost, Pricing::splitTax(
                            (float) $price->price,
                            $product->taxRate(),
                            $this->taxMode,
                        )['net'])
                        : null,
                ];
            })
            ->all();
    }

    // =========================================================
    // Datos de apoyo
    // =========================================================

    #[Computed]
    public function priceLists()
    {
        return PriceList::active()->orderBy('position')->get();
    }

    #[Computed]
    public function categories()
    {
        return Category::active()->with('parent')->orderBy('name')->get();
    }

    #[Computed]
    public function units()
    {
        return Unit::active()->orderBy('name')->get();
    }

    #[Computed]
    public function taxMode(): string
    {
        return auth()->user()->tenant->taxMode();
    }

    #[Computed]
    public function taxRate(): float
    {
        return (float) (Tax::find($this->taxId)?->rate ?? 0);
    }

    // =========================================================
    // Reacciones a lo que escribe el usuario
    // =========================================================

    /**
     * Al cambiar el costo se recalculan los margenes que se muestran,
     * sin tocar los precios: subir el costo no debe mover solo el precio
     * de venta a espaldas de quien lo captura.
     */
    public function updatedCost(): void
    {
        $this->refreshMargins();
    }

    public function updatedTaxId(): void
    {
        $this->refreshMargins();
    }

    /** Lotes y vencimiento dependen de que el producto maneje stock. */
    public function updatedTrackStock(bool $value): void
    {
        if (! $value) {
            $this->trackLots = false;
            $this->trackExpiry = false;
            $this->trackSerials = false;
            $this->initialStock = 0;
        }
    }

    public function updatedTrackLots(bool $value): void
    {
        if (! $value) {
            $this->trackExpiry = false;
        }
    }

    public function updatedTrackExpiry(bool $value): void
    {
        // No tiene sentido controlar vencimiento sin lote que lo identifique.
        if ($value) {
            $this->trackLots = true;
        }
    }

    /** Recalcula el precio de una lista a partir de un margen deseado. */
    public function applyMargin(int $index, float $margin): void
    {
        $this->priceRows[$index]['price'] = Pricing::suggest(
            $this->cost,
            $margin,
            $this->taxRate,
            $this->taxMode,
        );
        $this->priceRows[$index]['margin'] = $margin;
    }

    /**
     * Recalcula el margen mostrado cuando se escribe un precio.
     *
     * Livewire pasa `$key` con la ruta del campo tocado, pero llega null
     * cuando se reemplaza el arreglo completo. En ese caso se recalculan
     * todos los renglones.
     */
    public function updatedPriceRows(mixed $value = null, ?string $key = null): void
    {
        if ($key === null) {
            $this->refreshMargins();

            return;
        }

        [$index, $field] = array_pad(explode('.', $key), 2, null);

        if ($field !== 'price') {
            return;
        }

        $net = Pricing::splitTax((float) $value, $this->taxRate, $this->taxMode)['net'];
        $this->priceRows[(int) $index]['margin'] = Pricing::margin($this->cost, $net);
    }

    protected function refreshMargins(): void
    {
        foreach ($this->priceRows as $index => $row) {
            $net = Pricing::splitTax((float) $row['price'], $this->taxRate, $this->taxMode)['net'];
            $this->priceRows[$index]['margin'] = Pricing::margin($this->cost, $net);
        }
    }

    // =========================================================
    // Presentaciones
    // =========================================================

    public function addUnitRow(): void
    {
        $this->unitRows[] = [
            'unit_id' => '',
            'factor' => 1.0,
            'is_default' => false,
            'barcode' => '',
        ];
    }

    public function removeUnitRow(int $index): void
    {
        if (count($this->unitRows) <= 1) {
            $this->notify('El producto necesita al menos una presentacion', 'error');

            return;
        }

        $wasDefault = $this->unitRows[$index]['is_default'] ?? false;
        unset($this->unitRows[$index]);
        $this->unitRows = array_values($this->unitRows);

        // Si se quito la predeterminada, la primera toma su lugar: el POS
        // necesita siempre una para preseleccionar.
        if ($wasDefault) {
            $this->unitRows[0]['is_default'] = true;
        }
    }

    public function setDefaultUnit(int $index): void
    {
        foreach ($this->unitRows as $i => $row) {
            $this->unitRows[$i]['is_default'] = $i === $index;
        }
    }

    // =========================================================
    // Guardar
    // =========================================================

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'sku' => [
                'required', 'string', 'max:60',
                Rule::unique('products', 'sku')
                    ->where('tenant_id', auth()->user()->tenant_id)
                    ->ignore($this->productId),
            ],
            'internalCode' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:2000'],
            'categoryId' => ['nullable', 'exists:categories,id'],
            'brandId' => ['nullable', 'exists:brands,id'],
            'supplierId' => ['nullable', 'exists:suppliers,id'],
            'type' => ['required', Rule::in(['simple', 'variable', 'combo'])],
            'baseUnitId' => ['required', 'exists:units,id'],
            'taxId' => ['nullable', 'exists:taxes,id'],
            'cost' => ['required', 'numeric', 'min:0'],
            'minStock' => ['required', 'numeric', 'min:0'],
            'maxStock' => ['nullable', 'numeric', 'min:0'],
            'initialStock' => ['required', 'numeric', 'min:0'],
            'expiryAlertDays' => ['required', 'integer', 'min:0', 'max:3650'],
            'expiryBlockDays' => ['required', 'integer', 'min:0', 'max:3650'],
            'barcode' => [
                'nullable', 'string', 'max:60',
                Rule::unique('product_barcodes', 'code')
                    ->where('tenant_id', auth()->user()->tenant_id)
                    ->ignore($this->productId, 'product_id'),
            ],
            'unitRows' => ['required', 'array', 'min:1'],
            'unitRows.*.unit_id' => ['required', 'exists:units,id'],
            'unitRows.*.factor' => ['required', 'numeric', 'gt:0'],
            'priceRows.*.price' => ['required', 'numeric', 'min:0'],
        ];
    }

    protected function messages(): array
    {
        return [
            'sku.unique' => 'Ya tienes un producto con ese SKU.',
            'barcode.unique' => 'Ese codigo de barra ya esta en uso por otro producto.',
            'unitRows.*.factor.gt' => 'La equivalencia debe ser mayor que cero.',
            'unitRows.*.unit_id.required' => 'Elige la unidad de la presentacion.',
        ];
    }

    public function save(): void
    {
        abort_unless(
            auth()->user()->can($this->productId ? 'products.edit' : 'products.create'),
            403,
        );

        $data = $this->validate();
        $this->validateBusinessRules();

        DB::transaction(function () use ($data) {
            $product = $this->productId
                ? Product::findOrFail($this->productId)
                : new Product;

            $previousCost = $this->productId ? (float) $product->cost : null;

            $product->fill([
                'name' => $data['name'],
                'sku' => $data['sku'],
                'internal_code' => $data['internalCode'] ?: null,
                'description' => $data['description'] ?: null,
                'category_id' => $data['categoryId'] ?: null,
                'brand_id' => $data['brandId'] ?: null,
                'supplier_id' => $data['supplierId'] ?: null,
                'type' => $data['type'],
                'base_unit_id' => $data['baseUnitId'],
                'tax_id' => $data['taxId'] ?: null,
                'cost' => $data['cost'],
                'track_stock' => $this->trackStock,
                'min_stock' => $data['minStock'],
                'max_stock' => $data['maxStock'],
                'track_lots' => $this->trackLots,
                'track_expiry' => $this->trackExpiry,
                'track_serials' => $this->trackSerials,
                'expiry_alert_days' => $data['expiryAlertDays'],
                'expiry_block_days' => $data['expiryBlockDays'],
            ])->save();

            $unitIds = $this->syncUnits($product);
            $this->syncBarcodes($product, $unitIds);
            $this->syncPrices($product);

            if ($previousCost === null || $previousCost !== (float) $data['cost']) {
                CostHistory::create([
                    'product_id' => $product->id,
                    'old_cost' => $previousCost,
                    'new_cost' => $data['cost'],
                    'source' => 'manual',
                    'changed_by' => auth()->id(),
                ]);
            }

            // El stock inicial solo se aplica al crear: en una edicion
            // sumarlo otra vez duplicaria la existencia.
            if (! $this->productId && $this->trackStock && $this->initialStock > 0) {
                $this->applyInitialStock($product);
            }

            $this->productId = $product->id;
        });

        $this->notify('Producto guardado');
        $this->redirectRoute('products', navigate: true);
    }

    /**
     * Reglas que no caben en las de validacion normales porque dependen
     * de la combinacion de varios campos.
     */
    protected function validateBusinessRules(): void
    {
        if ($this->trackExpiry && ! $this->trackLots) {
            $this->addError('trackExpiry', 'Para controlar vencimientos hay que activar lotes.');
        }

        if (($this->trackLots || $this->trackSerials) && ! $this->trackStock) {
            $this->addError('trackStock', 'Lotes y series requieren que el producto maneje stock.');
        }

        if ($this->maxStock !== null && $this->maxStock > 0 && $this->maxStock < $this->minStock) {
            $this->addError('maxStock', 'El maximo no puede ser menor que el minimo.');
        }

        $unitIds = array_column($this->unitRows, 'unit_id');
        if (count($unitIds) !== count(array_unique($unitIds))) {
            $this->addError('unitRows', 'Hay una unidad repetida entre las presentaciones.');
        }

        if (count(array_filter(array_column($this->unitRows, 'is_default'))) !== 1) {
            $this->addError('unitRows', 'Marca exactamente una presentacion como predeterminada.');
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            $this->tab = $this->getErrorBag()->has('unitRows') ? 'units' : 'inventory';

            throw ValidationException::withMessages(
                $this->getErrorBag()->toArray(),
            );
        }
    }

    /** @return array<int, string> ids de presentacion, en el orden capturado */
    protected function syncUnits(Product $product): array
    {
        $keep = [];
        $ids = [];

        foreach ($this->unitRows as $position => $row) {
            $unit = ProductUnit::updateOrCreate(
                ['product_id' => $product->id, 'unit_id' => $row['unit_id']],
                [
                    'factor' => $row['factor'],
                    'is_default' => (bool) $row['is_default'],
                    'position' => $position,
                    'status' => 'active',
                ],
            );

            $keep[] = $unit->id;
            $ids[$position] = $unit->id;
        }

        // Las presentaciones que el usuario quito se desactivan en vez de
        // borrarse: pueden estar referenciadas por ventas anteriores.
        ProductUnit::where('product_id', $product->id)
            ->whereNotIn('id', $keep)
            ->update(['status' => 'inactive']);

        return $ids;
    }

    protected function syncBarcodes(Product $product, array $unitIds): void
    {
        // Codigo principal, el del producto sin presentacion especifica.
        if ($this->barcode !== '') {
            ProductBarcode::updateOrCreate(
                ['product_id' => $product->id, 'product_unit_id' => null, 'variant_id' => null],
                ['code' => $this->barcode, 'is_primary' => true],
            );
        } else {
            ProductBarcode::where('product_id', $product->id)
                ->whereNull('product_unit_id')
                ->whereNull('variant_id')
                ->delete();
        }

        // Codigo propio de cada presentacion: la caja trae el suyo.
        foreach ($this->unitRows as $position => $row) {
            $unitId = $unitIds[$position] ?? null;

            if ($unitId === null) {
                continue;
            }

            if (trim((string) $row['barcode']) === '') {
                ProductBarcode::where('product_unit_id', $unitId)->delete();

                continue;
            }

            ProductBarcode::updateOrCreate(
                ['product_id' => $product->id, 'product_unit_id' => $unitId],
                ['code' => trim($row['barcode'])],
            );
        }
    }

    protected function syncPrices(Product $product): void
    {
        foreach ($this->priceRows as $row) {
            $existing = ProductPrice::where('product_id', $product->id)
                ->where('price_list_id', $row['price_list_id'])
                ->whereNull('variant_id')
                ->whereNull('product_unit_id')
                ->where('min_quantity', 1)
                ->first();

            $newPrice = (float) $row['price'];

            if ($existing && (float) $existing->price === $newPrice) {
                continue;
            }

            ProductPrice::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'price_list_id' => $row['price_list_id'],
                    'variant_id' => null,
                    'product_unit_id' => null,
                    'min_quantity' => 1,
                ],
                [
                    'price' => $newPrice,
                    'margin_percent' => $row['margin'],
                    'is_manual' => true,
                ],
            );

            // Todo cambio de precio queda registrado: es lo que permite
            // explicar despues por que cambio el margen de un producto.
            PriceHistory::create([
                'product_id' => $product->id,
                'price_list_id' => $row['price_list_id'],
                'old_price' => $existing?->price,
                'new_price' => $newPrice,
                'changed_by' => auth()->id(),
            ]);
        }
    }

    /**
     * Deja la existencia inicial junto con su movimiento de kardex, para
     * que ninguna cantidad exista sin un origen documentado.
     */
    protected function applyInitialStock(Product $product): void
    {
        $branchId = auth()->user()->branch_id
            ?? Branch::active()->value('id');

        if ($branchId === null) {
            return;
        }

        Inventory::create([
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'quantity' => $this->initialStock,
            'avg_cost' => $this->cost,
        ]);

        InventoryMovement::create([
            'branch_id' => $branchId,
            'product_id' => $product->id,
            'type' => 'initial',
            'quantity' => $this->initialStock,
            'balance' => $this->initialStock,
            'unit_cost' => $this->cost,
            'reference_type' => 'manual',
            'reason' => 'Inventario inicial',
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);
    }

    public function render()
    {
        return view('livewire.products.form', [
            'brands' => Brand::active()->orderBy('name')->get(),
            'suppliers' => Supplier::active()->orderBy('name')->get(),
            'taxes' => Tax::active()->orderBy('rate')->get(),
            'currency' => auth()->user()->tenant->primaryCurrency,
        ]);
    }
}
