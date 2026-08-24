<?php

namespace App\Livewire\Catalog;

use App\Livewire\Page;
use App\Models\Category;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;

/**
 * Categorias y subcategorias.
 *
 * Solo se permiten dos niveles: un arbol mas profundo complica los
 * reportes y la navegacion del POS sin aportar nada real.
 */
#[Layout('layouts.app')]
class Categories extends Page
{
    public bool $showForm = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $parentId = '';

    public string $color = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('products.view'), 403);
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'parentId' => [
                'nullable',
                Rule::exists('categories', 'id')->where('tenant_id', auth()->user()->tenant_id),
            ],
            'color' => ['nullable', 'string', 'max:20'],
        ];
    }

    protected function messages(): array
    {
        return ['name.required' => 'Escribe el nombre de la categoria.'];
    }

    public function create(?string $parentId = null): void
    {
        $this->reset(['editingId', 'name', 'color']);
        $this->resetValidation();
        $this->parentId = $parentId ?? '';
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $category = Category::findOrFail($id);

        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->parentId = (string) $category->parent_id;
        $this->color = (string) $category->color;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('products.edit'), 403);

        $data = $this->validate();

        // Una subcategoria no puede colgar de otra subcategoria.
        if ($data['parentId'] !== '' && Category::whereKey($data['parentId'])->value('parent_id')) {
            $this->addError('parentId', 'Una subcategoria no puede tener subcategorias.');

            return;
        }

        // Ni una categoria puede colgar de si misma.
        if ($this->editingId !== null && $data['parentId'] === $this->editingId) {
            $this->addError('parentId', 'Una categoria no puede depender de si misma.');

            return;
        }

        Category::updateOrCreate(
            ['id' => $this->editingId],
            [
                'name' => $data['name'],
                'parent_id' => $data['parentId'] ?: null,
                'color' => $data['color'] ?: null,
            ],
        );

        $this->showForm = false;
        $this->reset(['editingId', 'name', 'parentId', 'color']);
        $this->notify('Categoria guardada');
    }

    public function delete(string $id): void
    {
        abort_unless(auth()->user()->can('products.edit'), 403);

        $category = Category::withCount(['products', 'children'])->findOrFail($id);

        // Borrar arrastraria los productos a "sin categoria" sin avisar,
        // y las subcategorias se irian con ella por la llave foranea.
        if ($category->products_count > 0) {
            $this->notify(
                "Tiene {$category->products_count} producto(s). Muevelos primero o desactivala.",
                'error',
            );

            return;
        }

        if ($category->children_count > 0) {
            $this->notify('Primero elimina o mueve sus subcategorias.', 'error');

            return;
        }

        $category->delete();
        $this->notify('Categoria eliminada');
    }

    public function toggleStatus(string $id): void
    {
        abort_unless(auth()->user()->can('products.edit'), 403);

        $category = Category::findOrFail($id);
        $category->update(['status' => $category->status === 'active' ? 'inactive' : 'active']);

        $this->notify($category->status === 'active' ? 'Categoria activada' : 'Categoria desactivada');
    }

    public function render()
    {
        return view('livewire.catalog.categories', [
            'roots' => Category::roots()
                ->with(['children' => fn ($q) => $q->withCount('products')])
                ->withCount('products')
                ->orderBy('position')
                ->orderBy('name')
                ->get(),
            // Para el selector de categoria padre: solo las de primer nivel.
            'parents' => Category::roots()->orderBy('name')->get(),
        ]);
    }
}
