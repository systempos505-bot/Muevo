<?php

namespace App\Livewire\Finance;

use App\Livewire\Page;
use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Supplier;
use App\Services\ExpenseRegistrar;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use RuntimeException;

/** Gastos del negocio, con sus categorias. */
#[Layout('layouts.app')]
class Expenses extends Page
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $categoryId = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: 'registered')]
    public string $status = 'registered';

    // --- Alta ---
    public bool $showForm = false;

    public string $description = '';

    public ?float $total = null;

    public float $tax = 0;

    public string $formCategoryId = '';

    public string $accountId = '';

    public string $supplierId = '';

    public string $expenseDate = '';

    public string $reference = '';

    public bool $isRecurring = false;

    // --- Anulacion ---
    public bool $showCancel = false;

    public ?string $cancellingId = null;

    public string $cancelReason = '';

    // --- Categorias ---
    public bool $showCategories = false;

    public string $newCategory = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('expenses.view'), 403);

        $this->expenseDate = now()->toDateString();
        $this->from = now()->startOfMonth()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'categoryId', 'from', 'to', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'categoryId', 'from', 'to']);
        $this->resetPage();
    }

    protected function baseQuery(): Builder
    {
        return Expense::query()
            ->when($this->search, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('description', 'like', "%{$this->search}%")
                ->orWhere('folio', 'like', "%{$this->search}%")
                ->orWhere('reference', 'like', "%{$this->search}%")))
            ->when($this->categoryId, fn (Builder $q) => $q->where('category_id', $this->categoryId))
            ->when($this->from, fn (Builder $q) => $q->whereDate('expense_date', '>=', $this->from))
            ->when($this->to, fn (Builder $q) => $q->whereDate('expense_date', '<=', $this->to))
            ->when($this->status !== 'all', fn (Builder $q) => $q->where('status', $this->status));
    }

    #[Computed]
    public function summary(): array
    {
        $row = $this->baseQuery()
            ->selectRaw('count(*) as items, coalesce(sum(total_primary), 0) as total')
            ->first();

        return [
            'items' => (int) $row->items,
            'total' => (float) $row->total,
        ];
    }

    /** En que se va el dinero: total por categoria del periodo filtrado. */
    #[Computed]
    public function byCategory(): array
    {
        return $this->baseQuery()
            ->selectRaw('category_id, coalesce(sum(total_primary), 0) as total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->with('category')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->category?->name ?? 'Sin categoria',
                'total' => (float) $row->total,
            ])
            ->all();
    }

    // =========================================================
    // Alta
    // =========================================================

    public function create(): void
    {
        abort_unless(auth()->user()->can('expenses.create'), 403);

        $this->reset(['description', 'total', 'tax', 'supplierId', 'reference', 'isRecurring']);
        $this->expenseDate = now()->toDateString();
        $this->formCategoryId = (string) ExpenseCategory::active()->value('id');
        $this->accountId = (string) Account::active()->where('is_default', true)->value('id');
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(ExpenseRegistrar $registrar): void
    {
        abort_unless(auth()->user()->can('expenses.create'), 403);

        $data = $this->validate([
            'description' => ['required', 'string', 'min:3', 'max:200'],
            'total' => ['required', 'numeric', 'gt:0'],
            'tax' => ['required', 'numeric', 'min:0'],
            'formCategoryId' => ['nullable', Rule::exists('expense_categories', 'id')],
            'accountId' => ['nullable', Rule::exists('accounts', 'id')],
            'supplierId' => ['nullable', Rule::exists('suppliers', 'id')],
            'expenseDate' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:80'],
        ], [
            'total.gt' => 'El monto debe ser mayor que cero.',
            'description.required' => 'Escribe de que fue el gasto.',
        ]);

        try {
            $registrar->register(
                total: (float) $data['total'],
                description: $data['description'],
                categoryId: $data['formCategoryId'] ?: null,
                accountId: $data['accountId'] ?: null,
                supplierId: $data['supplierId'] ?: null,
                expenseDate: $data['expenseDate'],
                tax: (float) $data['tax'],
                reference: $data['reference'] ?: null,
                isRecurring: $this->isRecurring,
            );
        } catch (RuntimeException $e) {
            $this->addError('total', $e->getMessage());

            return;
        }

        unset($this->summary, $this->byCategory);
        $this->showForm = false;
        $this->notify('Gasto registrado');
    }

    /** Vuelve a registrar un gasto recurrente con la fecha de hoy. */
    public function repeat(string $id, ExpenseRegistrar $registrar): void
    {
        abort_unless(auth()->user()->can('expenses.create'), 403);

        try {
            $registrar->repeat(Expense::findOrFail($id));
        } catch (RuntimeException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }

        unset($this->summary, $this->byCategory);
        $this->notify('Gasto repetido con la fecha de hoy');
    }

    // =========================================================
    // Anulacion
    // =========================================================

    public function openCancel(string $id): void
    {
        abort_unless(auth()->user()->can('expenses.void'), 403);

        $this->cancellingId = $id;
        $this->cancelReason = '';
        $this->resetValidation();
        $this->showCancel = true;
    }

    public function cancel(ExpenseRegistrar $registrar): void
    {
        abort_unless(auth()->user()->can('expenses.void'), 403);

        $this->validate(
            ['cancelReason' => ['required', 'string', 'min:5', 'max:300']],
            ['cancelReason.required' => 'Escribe por que se anula el gasto.'],
        );

        try {
            $registrar->cancel(Expense::findOrFail($this->cancellingId), $this->cancelReason);
        } catch (RuntimeException $e) {
            $this->addError('cancelReason', $e->getMessage());

            return;
        }

        unset($this->summary, $this->byCategory);
        $this->showCancel = false;
        $this->notify('Gasto anulado');
    }

    // =========================================================
    // Categorias
    // =========================================================

    public function addCategory(): void
    {
        abort_unless(auth()->user()->can('expenses.create'), 403);

        $this->validate(
            ['newCategory' => [
                'required', 'string', 'min:2', 'max:60',
                Rule::unique('expense_categories', 'name')
                    ->where('tenant_id', auth()->user()->tenant_id),
            ]],
            ['newCategory.unique' => 'Ya tienes una categoria con ese nombre.'],
        );

        ExpenseCategory::create(['name' => $this->newCategory]);

        $this->newCategory = '';
        $this->notify('Categoria agregada');
    }

    public function deleteCategory(string $id): void
    {
        abort_unless(auth()->user()->can('expenses.create'), 403);

        $category = ExpenseCategory::withCount('expenses')->findOrFail($id);

        if ($category->expenses_count > 0) {
            $this->notify(
                "Tiene {$category->expenses_count} gasto(s). Desactivala en lugar de borrarla.",
                'error',
            );

            return;
        }

        $category->delete();
        $this->notify('Categoria eliminada');
    }

    public function render()
    {
        return view('livewire.finance.expenses', [
            'expenses' => $this->baseQuery()
                ->with(['category', 'account', 'supplier', 'user'])
                ->orderByDesc('expense_date')
                ->orderByDesc('created_at')
                ->paginate(25),
            'categories' => ExpenseCategory::withCount('expenses')->orderBy('name')->get(),
            'accounts' => Account::active()->with('currency')->orderBy('name')->get(),
            'suppliers' => Supplier::active()->orderBy('name')->get(),
            'currency' => auth()->user()->tenant->primaryCurrency,
        ]);
    }
}
