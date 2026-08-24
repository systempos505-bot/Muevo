<?php

namespace App\Livewire\Catalog;

use App\Livewire\Page;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;

/**
 * Unidades de medida del negocio.
 *
 * Aqui solo se define el catalogo (Unidad, Caja, Docena, Kg...). Cuantas
 * unidades base trae cada una se decide por producto, porque una caja de
 * guantes y una caja de jarabe no traen lo mismo.
 */
#[Layout('layouts.app')]
class Units extends Page
{
    public bool $showForm = false;

    public ?string $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $pluralName = '';

    public bool $allowsDecimals = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('products.view'), 403);
    }

    protected function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'min:1', 'max:10',
                Rule::unique('units', 'code')
                    ->where('tenant_id', auth()->user()->tenant_id)
                    ->ignore($this->editingId),
            ],
            'name' => ['required', 'string', 'min:2', 'max:40'],
            'pluralName' => ['nullable', 'string', 'max:40'],
        ];
    }

    protected function messages(): array
    {
        return ['code.unique' => 'Ya tienes una unidad con ese codigo.'];
    }

    public function create(): void
    {
        $this->reset(['editingId', 'code', 'name', 'pluralName', 'allowsDecimals']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $unit = Unit::findOrFail($id);

        $this->editingId = $unit->id;
        $this->code = $unit->code;
        $this->name = $unit->name;
        $this->pluralName = (string) $unit->plural_name;
        $this->allowsDecimals = $unit->allows_decimals;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('products.edit'), 403);

        $data = $this->validate();

        Unit::updateOrCreate(
            ['id' => $this->editingId],
            [
                'code' => mb_strtoupper($data['code']),
                'name' => $data['name'],
                'plural_name' => $data['pluralName'] ?: null,
                'allows_decimals' => $this->allowsDecimals,
            ],
        );

        $this->showForm = false;
        $this->reset(['editingId', 'code', 'name', 'pluralName', 'allowsDecimals']);
        $this->notify('Unidad guardada');
    }

    public function delete(string $id): void
    {
        abort_unless(auth()->user()->can('products.edit'), 403);

        $inUse = Product::where('base_unit_id', $id)->count()
            + ProductUnit::where('unit_id', $id)->count();

        if ($inUse > 0) {
            $this->notify('Esta unidad esta en uso por uno o mas productos.', 'error');

            return;
        }

        Unit::findOrFail($id)->delete();
        $this->notify('Unidad eliminada');
    }

    public function render()
    {
        return view('livewire.catalog.units', [
            'units' => Unit::orderBy('name')
                ->withCount(['products' => fn ($q) => $q])
                ->get(),
        ]);
    }
}
