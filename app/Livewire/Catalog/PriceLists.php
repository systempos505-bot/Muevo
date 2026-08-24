<?php

namespace App\Livewire\Catalog;

use App\Livewire\Page;
use App\Models\PriceList;
use App\Models\ProductPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;

/**
 * Listas de precios del negocio: Publico, Mayoreo, Distribuidor...
 *
 * Una lista puede trabajar por margen, y entonces sus precios se calculan
 * desde el costo y se recalculan solos cuando el costo cambia, salvo los
 * que alguien haya capturado a mano.
 */
#[Layout('layouts.app')]
class PriceLists extends Page
{
    public bool $showForm = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $pricingMode = 'manual';

    public ?float $marginPercent = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('products.view'), 403);
    }

    protected function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'min:2', 'max:60',
                Rule::unique('price_lists', 'name')
                    ->where('tenant_id', auth()->user()->tenant_id)
                    ->ignore($this->editingId),
            ],
            'pricingMode' => ['required', Rule::in(['manual', 'margin'])],
            'marginPercent' => [
                Rule::requiredIf(fn () => $this->pricingMode === 'margin'),
                'nullable', 'numeric', 'min:0', 'max:10000',
            ],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.unique' => 'Ya tienes una lista con ese nombre.',
            'marginPercent.required' => 'Indica el margen que usara esta lista.',
        ];
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name', 'pricingMode', 'marginPercent']);
        $this->resetValidation();

        if (PriceList::active()->count() >= PriceList::MAX_PER_TENANT) {
            $this->notify(
                'Llegaste al limite de '.PriceList::MAX_PER_TENANT.' listas de precios.',
                'error',
            );

            return;
        }

        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $list = PriceList::findOrFail($id);

        $this->editingId = $list->id;
        $this->name = $list->name;
        $this->pricingMode = $list->pricing_mode;
        $this->marginPercent = $list->margin_percent;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('products.edit'), 403);

        $data = $this->validate();

        if ($this->editingId === null && PriceList::active()->count() >= PriceList::MAX_PER_TENANT) {
            $this->notify('Llegaste al limite de listas de precios.', 'error');

            return;
        }

        PriceList::updateOrCreate(
            ['id' => $this->editingId],
            [
                'name' => $data['name'],
                'pricing_mode' => $data['pricingMode'],
                'margin_percent' => $data['pricingMode'] === 'margin' ? $data['marginPercent'] : null,
                'position' => $this->editingId
                    ? PriceList::whereKey($this->editingId)->value('position')
                    : PriceList::count(),
            ],
        );

        $this->showForm = false;
        $this->reset(['editingId', 'name', 'pricingMode', 'marginPercent']);
        $this->notify('Lista de precios guardada');
    }

    public function makeDefault(string $id): void
    {
        abort_unless(auth()->user()->can('products.edit'), 403);

        // La lista por defecto es unica, asi que se apaga la anterior en la
        // misma transaccion para no dejar dos ni ninguna.
        DB::transaction(function () use ($id) {
            PriceList::where('is_default', true)->update(['is_default' => false]);
            PriceList::whereKey($id)->update(['is_default' => true]);
        });

        $this->notify('Lista de mostrador actualizada');
    }

    public function toggleStatus(string $id): void
    {
        abort_unless(auth()->user()->can('products.edit'), 403);

        $list = PriceList::findOrFail($id);

        // Desactivar la lista de mostrador dejaria al POS sin precio.
        if ($list->is_default && $list->status === 'active') {
            $this->notify('No puedes desactivar la lista de mostrador.', 'error');

            return;
        }

        $list->update(['status' => $list->status === 'active' ? 'inactive' : 'active']);
        $this->notify($list->status === 'active' ? 'Lista activada' : 'Lista desactivada');
    }

    public function delete(string $id): void
    {
        abort_unless(auth()->user()->can('products.edit'), 403);

        $list = PriceList::findOrFail($id);

        if ($list->is_default) {
            $this->notify('No puedes eliminar la lista de mostrador.', 'error');

            return;
        }

        $priced = ProductPrice::where('price_list_id', $id)->count();

        if ($priced > 0) {
            $this->notify(
                "Hay {$priced} precio(s) capturados en esta lista. Desactivala en lugar de borrarla.",
                'error',
            );

            return;
        }

        $list->delete();
        $this->notify('Lista eliminada');
    }

    public function render()
    {
        return view('livewire.catalog.price-lists', [
            'lists' => PriceList::withCount('prices')->orderBy('position')->orderBy('name')->get(),
        ]);
    }
}
