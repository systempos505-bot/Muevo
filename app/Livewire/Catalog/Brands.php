<?php

namespace App\Livewire\Catalog;

use App\Livewire\Page;
use App\Models\Brand;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;

#[Layout('layouts.app')]
class Brands extends Page
{
    public bool $showForm = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('products.view'), 403);
    }

    protected function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'min:2', 'max:80',
                Rule::unique('brands', 'name')
                    ->where('tenant_id', auth()->user()->tenant_id)
                    ->ignore($this->editingId),
            ],
        ];
    }

    protected function messages(): array
    {
        return ['name.unique' => 'Ya tienes una marca con ese nombre.'];
    }

    public function create(): void
    {
        $this->reset(['editingId', 'name']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $brand = Brand::findOrFail($id);

        $this->editingId = $brand->id;
        $this->name = $brand->name;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('products.edit'), 403);

        $data = $this->validate();

        Brand::updateOrCreate(['id' => $this->editingId], ['name' => $data['name']]);

        $this->showForm = false;
        $this->reset(['editingId', 'name']);
        $this->notify('Marca guardada');
    }

    public function delete(string $id): void
    {
        abort_unless(auth()->user()->can('products.edit'), 403);

        $brand = Brand::withCount('products')->findOrFail($id);

        if ($brand->products_count > 0) {
            $this->notify(
                "Tiene {$brand->products_count} producto(s). Cambialos de marca primero.",
                'error',
            );

            return;
        }

        $brand->delete();
        $this->notify('Marca eliminada');
    }

    public function render()
    {
        return view('livewire.catalog.brands', [
            'brands' => Brand::withCount('products')
                ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
                ->orderBy('name')
                ->get(),
        ]);
    }
}
