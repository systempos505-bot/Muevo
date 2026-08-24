<?php

namespace App\Livewire\Promotions;

use App\Livewire\Page;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomerType;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use App\Models\SaleItemPromotion;
use App\Services\PromotionEngine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Promociones del negocio.
 *
 * La pantalla se arma alrededor del tipo de promocion: elegido el tipo,
 * solo se piden los datos que ese tipo necesita. Un 2x1 no tiene por que
 * preguntar por un porcentaje.
 */
#[Layout('layouts.app')]
class Index extends Page
{
    use WithPagination;

    /** @var array<string, string> */
    public const TYPES = [
        Promotion::NXM => 'Lleva N y paga M (2x1, 3x2)',
        Promotion::PERCENT => 'Porcentaje de descuento',
        Promotion::AMOUNT => 'Monto fijo por unidad',
        Promotion::BUNDLE => 'Precio de paquete',
    ];

    /** @var array<int, string> */
    public const WEEKDAYS = [
        1 => 'Lun', 2 => 'Mar', 3 => 'Mie', 4 => 'Jue',
        5 => 'Vie', 6 => 'Sab', 7 => 'Dom',
    ];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $status = 'all';

    // --- Formulario ---
    public bool $showForm = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $description = '';

    public string $type = Promotion::NXM;

    public ?int $buyQuantity = 2;

    public ?int $getQuantity = 1;

    public ?float $discountPercent = null;

    public ?float $discountAmount = null;

    public ?float $bundlePrice = null;

    public float $minQuantity = 1;

    public ?int $maxUsesPerLine = null;

    public string $startsOn = '';

    public string $endsOn = '';

    /** @var array<int, int> */
    public array $weekdays = [];

    public string $startsAt = '';

    public string $endsAt = '';

    public string $branchId = '';

    public string $priceListId = '';

    public string $customerTypeId = '';

    public int $priority = 0;

    public bool $combinable = false;

    // --- A que aplica ---
    public bool $appliesToAll = true;

    public string $targetType = 'product';

    /** @var array<int, array{type: string, id: string, name: string}> */
    public array $targets = [];

    public string $targetSearch = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('promotions.view'), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    // =========================================================
    // Listado
    // =========================================================

    protected function baseQuery(): Builder
    {
        return Promotion::query()
            ->when($this->search, fn (Builder $q) => $q->where('name', 'like', "%{$this->search}%"))
            ->when($this->status !== 'all', function (Builder $q) {
                // "Vigente" no es lo mismo que "encendida": una promocion
                // activa cuya fecha ya paso no le sirve a nadie.
                return match ($this->status) {
                    'running' => $q->where('status', 'active')
                        ->where(fn ($w) => $w->whereNull('ends_on')
                            ->orWhereDate('ends_on', '>=', now()->toDateString())),
                    'expired' => $q->whereNotNull('ends_on')
                        ->whereDate('ends_on', '<', now()->toDateString()),
                    default => $q->where('status', $this->status),
                };
            });
    }

    /** Cuantas promociones estan corriendo en este momento. */
    #[Computed]
    public function runningNow(): int
    {
        return app(PromotionEngine::class)->active()->count();
    }

    // =========================================================
    // Formulario
    // =========================================================

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:200'],
            'type' => ['required', Rule::in(array_keys(self::TYPES))],

            'buyQuantity' => [
                Rule::requiredIf(fn () => in_array($this->type, [Promotion::NXM, Promotion::BUNDLE], true)),
                'nullable', 'integer', 'min:1', 'max:999',
            ],
            'getQuantity' => [
                Rule::requiredIf(fn () => $this->type === Promotion::NXM),
                'nullable', 'integer', 'min:1', 'max:998',
                // 2x3 regalaria mas de lo que se lleva.
                'lt:buyQuantity',
            ],
            'discountPercent' => [
                Rule::requiredIf(fn () => $this->type === Promotion::PERCENT),
                'nullable', 'numeric', 'min:0.01', 'max:100',
            ],
            'discountAmount' => [
                Rule::requiredIf(fn () => $this->type === Promotion::AMOUNT),
                'nullable', 'numeric', 'min:0.01',
            ],
            'bundlePrice' => [
                Rule::requiredIf(fn () => $this->type === Promotion::BUNDLE),
                'nullable', 'numeric', 'min:0.01',
            ],

            'minQuantity' => ['required', 'numeric', 'min:0'],
            'maxUsesPerLine' => ['nullable', 'integer', 'min:1', 'max:999'],

            'startsOn' => ['nullable', 'date'],
            'endsOn' => ['nullable', 'date', 'after_or_equal:startsOn'],
            'weekdays' => ['array'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'startsAt' => ['nullable', 'date_format:H:i'],
            'endsAt' => ['nullable', 'required_with:startsAt', 'date_format:H:i'],

            'priority' => ['required', 'integer', 'min:0', 'max:999'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Ponle un nombre a la promocion: es el que ve el cliente en el ticket.',
            'getQuantity.lt' => 'Lo que se regala tiene que ser menor que lo que se lleva.',
            'discountPercent.required' => 'Indica el porcentaje de descuento.',
            'discountAmount.required' => 'Indica cuanto se descuenta por unidad.',
            'bundlePrice.required' => 'Indica el precio del paquete.',
            'endsOn.after_or_equal' => 'La fecha de fin no puede ser anterior a la de inicio.',
            'endsAt.required_with' => 'Indica tambien la hora de fin.',
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $promotion = Promotion::with('targets')->findOrFail($id);

        $this->editingId = $promotion->id;
        $this->name = $promotion->name;
        $this->description = (string) $promotion->description;
        $this->type = $promotion->type;
        $this->buyQuantity = $promotion->buy_quantity ?: null;
        $this->getQuantity = $promotion->get_quantity ?: null;
        $this->discountPercent = $promotion->discount_percent ?: null;
        $this->discountAmount = $promotion->discount_amount ?: null;
        $this->bundlePrice = $promotion->bundle_price ?: null;
        $this->minQuantity = (float) $promotion->min_quantity;
        $this->maxUsesPerLine = $promotion->max_uses_per_line;
        $this->startsOn = $promotion->starts_on?->toDateString() ?? '';
        $this->endsOn = $promotion->ends_on?->toDateString() ?? '';
        $this->weekdays = array_map('intval', $promotion->weekdays ?? []);
        $this->startsAt = $promotion->starts_at ? substr((string) $promotion->starts_at, 0, 5) : '';
        $this->endsAt = $promotion->ends_at ? substr((string) $promotion->ends_at, 0, 5) : '';
        $this->branchId = (string) $promotion->branch_id;
        $this->priceListId = (string) $promotion->price_list_id;
        $this->customerTypeId = (string) $promotion->customer_type_id;
        $this->priority = $promotion->priority;
        $this->combinable = $promotion->combinable;
        $this->appliesToAll = $promotion->applies_to_all;

        $this->targets = $promotion->targets
            ->map(fn (PromotionTarget $t) => [
                'type' => $t->target_type,
                'id' => $t->target_id,
                'name' => $t->targetName() ?? 'Ya no existe',
            ])
            ->all();

        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('promotions.manage'), 403);

        $data = $this->validate();

        if (! $this->appliesToAll && $this->targets === []) {
            $this->addError('targets', 'Elige a que productos, categorias o marcas aplica.');

            return;
        }

        DB::transaction(function () use ($data) {
            $promotion = Promotion::updateOrCreate(
                ['id' => $this->editingId],
                [
                    'name' => $data['name'],
                    'description' => $data['description'] ?: null,
                    'type' => $data['type'],
                    'applies_to_all' => $this->appliesToAll,
                    'buy_quantity' => $data['buyQuantity'] ?? 0,
                    'get_quantity' => $data['getQuantity'] ?? 0,
                    'discount_percent' => $data['discountPercent'] ?? 0,
                    'discount_amount' => $data['discountAmount'] ?? 0,
                    'bundle_price' => $data['bundlePrice'] ?? 0,
                    'min_quantity' => $data['minQuantity'],
                    'max_uses_per_line' => $data['maxUsesPerLine'],
                    'starts_on' => $data['startsOn'] ?: null,
                    'ends_on' => $data['endsOn'] ?: null,
                    // Elegir los siete dias es lo mismo que no elegir
                    // ninguno; se guarda nulo para no tener dos formas de
                    // decir "todos los dias".
                    'weekdays' => count($this->weekdays) === 7 || $this->weekdays === []
                        ? null
                        : array_values(array_map('intval', $this->weekdays)),
                    'starts_at' => $data['startsAt'] ?: null,
                    'ends_at' => $data['endsAt'] ?: null,
                    'branch_id' => $this->branchId ?: null,
                    'price_list_id' => $this->priceListId ?: null,
                    'customer_type_id' => $this->customerTypeId ?: null,
                    'priority' => $data['priority'],
                    'combinable' => $this->combinable,
                    // Quien la creo se anota una sola vez: editarla
                    // despues no cambia de quien fue la idea.
                    ...($this->editingId ? [] : ['created_by' => auth()->id()]),
                ],
            );

            $promotion->targets()->delete();

            if (! $this->appliesToAll) {
                foreach ($this->targets as $target) {
                    PromotionTarget::create([
                        'promotion_id' => $promotion->id,
                        'target_type' => $target['type'],
                        'target_id' => $target['id'],
                    ]);
                }
            }
        });

        $this->showForm = false;
        $this->resetForm();
        $this->notify('Promocion guardada');
    }

    public function toggle(string $id): void
    {
        abort_unless(auth()->user()->can('promotions.manage'), 403);

        $promotion = Promotion::findOrFail($id);
        $promotion->update([
            'status' => $promotion->status === 'active' ? 'inactive' : 'active',
        ]);

        $this->notify($promotion->status === 'active' ? 'Promocion encendida' : 'Promocion apagada');
    }

    public function delete(string $id): void
    {
        abort_unless(auth()->user()->can('promotions.manage'), 403);

        $promotion = Promotion::findOrFail($id);

        // Una promocion que ya se uso no se borra: los tickets que la
        // llevan tienen que seguir explicandose. Se apaga.
        if (SaleItemPromotion::where('promotion_id', $promotion->id)->exists()) {
            $promotion->update(['status' => 'inactive']);
            $this->notify('Esta promocion ya se uso en ventas, asi que se apago en lugar de borrarse.');

            return;
        }

        $promotion->targets()->delete();
        $promotion->delete();

        $this->notify('Promocion eliminada');
    }

    protected function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'description', 'discountPercent', 'discountAmount',
            'bundlePrice', 'maxUsesPerLine', 'startsOn', 'endsOn', 'weekdays',
            'startsAt', 'endsAt', 'branchId', 'priceListId', 'customerTypeId',
            'targets', 'targetSearch',
        ]);

        $this->type = Promotion::NXM;
        $this->buyQuantity = 2;
        $this->getQuantity = 1;
        $this->minQuantity = 1;
        $this->priority = 0;
        $this->combinable = false;
        $this->appliesToAll = true;
        $this->targetType = 'product';
        $this->resetValidation();
    }

    // =========================================================
    // A que aplica
    // =========================================================

    /**
     * Resultados del buscador de productos, categorias o marcas.
     *
     * @return Collection<int, array{id: string, name: string}>
     */
    #[Computed]
    public function targetResults()
    {
        $term = trim($this->targetSearch);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $chosen = collect($this->targets)
            ->where('type', $this->targetType)
            ->pluck('id')
            ->all();

        $query = match ($this->targetType) {
            'category' => Category::query(),
            'brand' => Brand::query(),
            default => Product::query()->active(),
        };

        return $query
            ->where('name', 'like', "%{$term}%")
            ->whereNotIn('id', $chosen)
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name'])
            ->map(fn ($row) => ['id' => $row->id, 'name' => $row->name]);
    }

    public function addTarget(string $id, string $name): void
    {
        $exists = collect($this->targets)
            ->contains(fn (array $t) => $t['type'] === $this->targetType && $t['id'] === $id);

        if ($exists) {
            return;
        }

        $this->targets[] = ['type' => $this->targetType, 'id' => $id, 'name' => $name];
        $this->targetSearch = '';
        $this->appliesToAll = false;
    }

    public function removeTarget(int $index): void
    {
        unset($this->targets[$index]);
        $this->targets = array_values($this->targets);
    }

    public function render()
    {
        return view('livewire.promotions.index', [
            'promotions' => $this->baseQuery()
                ->with(['targets', 'branch', 'priceList', 'customerType'])
                ->orderByDesc('status')
                ->orderByDesc('priority')
                ->orderBy('name')
                ->paginate(20),
            'branches' => Branch::active()->orderBy('name')->get(),
            'priceLists' => PriceList::active()->orderBy('name')->get(),
            'customerTypes' => CustomerType::orderBy('name')->get(),
            'currency' => auth()->user()->tenant->primaryCurrency,
        ]);
    }
}
