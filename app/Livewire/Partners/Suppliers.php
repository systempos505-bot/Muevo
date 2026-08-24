<?php

namespace App\Livewire\Partners;

use App\Livewire\Page;
use App\Models\Supplier;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/** Catalogo de proveedores y su cuenta por pagar. */
#[Layout('layouts.app')]
class Suppliers extends Page
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $filter = 'all';

    public bool $showForm = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $taxId = '';

    public string $contactName = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public int $creditDays = 0;

    public string $notes = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('purchases.view'), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'filter'], true)) {
            $this->resetPage();
        }
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'taxId' => ['nullable', 'string', 'max:60'],
            'contactName' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'address' => ['nullable', 'string', 'max:300'],
            'creditDays' => ['required', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'taxId', 'contactName', 'phone', 'email', 'address', 'notes']);
        $this->creditDays = 0;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $supplier = Supplier::findOrFail($id);

        $this->editingId = $supplier->id;
        $this->name = $supplier->name;
        $this->taxId = (string) $supplier->tax_id;
        $this->contactName = (string) $supplier->contact_name;
        $this->phone = (string) $supplier->phone;
        $this->email = (string) $supplier->email;
        $this->address = (string) $supplier->address;
        $this->creditDays = (int) $supplier->credit_days;
        $this->notes = (string) $supplier->notes;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('purchases.create'), 403);

        $data = $this->validate();

        Supplier::updateOrCreate(['id' => $this->editingId], [
            'name' => $data['name'],
            'tax_id' => $data['taxId'] ?: null,
            'contact_name' => $data['contactName'] ?: null,
            'phone' => $data['phone'] ?: null,
            'email' => $data['email'] ?: null,
            'address' => $data['address'] ?: null,
            'credit_days' => $data['creditDays'],
            'notes' => $data['notes'] ?: null,
        ]);

        $this->showForm = false;
        $this->notify('Proveedor guardado');
    }

    public function toggleStatus(string $id): void
    {
        abort_unless(auth()->user()->can('purchases.create'), 403);

        $supplier = Supplier::findOrFail($id);

        // Un proveedor con deuda pendiente se queda visible: esconderlo
        // haria que la cuenta por pagar se pierda de vista.
        if ($supplier->balance > 0 && $supplier->status === 'active') {
            $this->notify('Tiene saldo pendiente. Salda la cuenta antes de desactivarlo.', 'error');

            return;
        }

        $supplier->update(['status' => $supplier->status === 'active' ? 'inactive' : 'active']);
        $this->notify($supplier->status === 'active' ? 'Proveedor activado' : 'Proveedor desactivado');
    }

    /** Total que el negocio debe a sus proveedores. */
    #[Computed]
    public function totalPayable(): float
    {
        return (float) Supplier::sum('balance');
    }

    public function render()
    {
        return view('livewire.partners.suppliers', [
            'suppliers' => Supplier::query()
                ->when($this->search, fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('tax_id', 'like', "%{$this->search}%")
                    ->orWhere('phone', 'like', "%{$this->search}%")))
                ->when($this->filter === 'debt', fn ($q) => $q->where('balance', '>', 0))
                ->when($this->filter === 'active', fn ($q) => $q->where('status', 'active'))
                ->withCount('products')
                ->orderBy('name')
                ->paginate(25),
            'currency' => auth()->user()->tenant->primaryCurrency,
        ]);
    }
}
