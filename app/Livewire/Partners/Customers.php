<?php

namespace App\Livewire\Partners;

use App\Livewire\Page;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\PriceList;
use App\Services\CustomerAccount;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/** Catalogo de clientes y su cuenta por cobrar. */
#[Layout('layouts.app')]
class Customers extends Page
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

    public string $phone = '';

    public string $whatsapp = '';

    public string $email = '';

    public string $address = '';

    public string $customerTypeId = '';

    public string $priceListId = '';

    public bool $creditEnabled = false;

    public float $creditLimit = 0;

    public int $creditDays = 0;

    public string $notes = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('customers.view'), 403);
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
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'address' => ['nullable', 'string', 'max:300'],
            'customerTypeId' => ['nullable', Rule::exists('customer_types', 'id')],
            'priceListId' => ['nullable', Rule::exists('price_lists', 'id')],
            'creditLimit' => ['required', 'numeric', 'min:0'],
            'creditDays' => ['required', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function create(): void
    {
        $this->reset([
            'editingId', 'name', 'taxId', 'phone', 'whatsapp', 'email',
            'address', 'priceListId', 'notes',
        ]);

        $this->customerTypeId = (string) CustomerType::where('is_default', true)->value('id');
        $this->creditEnabled = false;
        $this->creditLimit = 0;
        $this->creditDays = 0;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $customer = Customer::findOrFail($id);

        $this->editingId = $customer->id;
        $this->name = $customer->name;
        $this->taxId = (string) $customer->tax_id;
        $this->phone = (string) $customer->phone;
        $this->whatsapp = (string) $customer->whatsapp;
        $this->email = (string) $customer->email;
        $this->address = (string) $customer->address;
        $this->customerTypeId = (string) $customer->customer_type_id;
        $this->priceListId = (string) $customer->price_list_id;
        $this->creditEnabled = $customer->credit_enabled;
        $this->creditLimit = (float) $customer->credit_limit;
        $this->creditDays = (int) $customer->credit_days;
        $this->notes = (string) $customer->notes;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('customers.create'), 403);

        $data = $this->validate();

        // Bajar el limite por debajo de lo que ya debe dejaria al cliente
        // fuera de credito sin explicacion: se avisa en lugar de guardar.
        if ($this->editingId !== null && $this->creditEnabled && $data['creditLimit'] > 0) {
            $balance = (float) Customer::whereKey($this->editingId)->value('balance');

            if ($data['creditLimit'] < $balance) {
                $this->addError('creditLimit', 'El limite no puede ser menor que el saldo actual.');

                return;
            }
        }

        Customer::updateOrCreate(['id' => $this->editingId], [
            'name' => $data['name'],
            'tax_id' => $data['taxId'] ?: null,
            'phone' => $data['phone'] ?: null,
            'whatsapp' => $data['whatsapp'] ?: null,
            'email' => $data['email'] ?: null,
            'address' => $data['address'] ?: null,
            'customer_type_id' => $data['customerTypeId'] ?: null,
            'price_list_id' => $data['priceListId'] ?: null,
            'credit_enabled' => $this->creditEnabled,
            'credit_limit' => $this->creditEnabled ? $data['creditLimit'] : 0,
            'credit_days' => $this->creditEnabled ? $data['creditDays'] : 0,
            'notes' => $data['notes'] ?: null,
        ]);

        $this->showForm = false;
        $this->notify('Cliente guardado');
    }

    public function toggleStatus(string $id): void
    {
        abort_unless(auth()->user()->can('customers.edit'), 403);

        $customer = Customer::findOrFail($id);

        if ($customer->balance > 0 && $customer->status === 'active') {
            $this->notify('Tiene saldo pendiente. Cobra la cuenta antes de desactivarlo.', 'error');

            return;
        }

        $customer->update(['status' => $customer->status === 'active' ? 'inactive' : 'active']);
        $this->notify($customer->status === 'active' ? 'Cliente activado' : 'Cliente desactivado');
    }

    /** Total que los clientes le deben al negocio. */
    #[Computed]
    public function totalReceivable(): float
    {
        return (float) Customer::sum('balance');
    }

    public function render()
    {
        return view('livewire.partners.customers', [
            'customers' => Customer::query()
                ->with('customerType')
                ->when($this->search, fn ($q) => $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('tax_id', 'like', "%{$this->search}%")
                    ->orWhere('phone', 'like', "%{$this->search}%")))
                ->when($this->filter === 'debt', fn ($q) => $q->where('balance', '>', 0))
                ->when($this->filter === 'credit', fn ($q) => $q->where('credit_enabled', true))
                ->when($this->filter === 'active', fn ($q) => $q->where('status', 'active'))
                ->orderBy('name')
                ->paginate(25),
            'customerTypes' => CustomerType::orderBy('name')->get(),
            'priceLists' => PriceList::active()->orderBy('position')->get(),
            'currency' => auth()->user()->tenant->primaryCurrency,
            'account' => app(CustomerAccount::class),
        ]);
    }
}
