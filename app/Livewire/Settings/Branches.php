<?php

namespace App\Livewire\Settings;

use App\Livewire\Page;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Terminal;
use App\Services\TenantProvisioner;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;

/**
 * Sucursales del negocio.
 *
 * Crear una sucursal deja tambien su caja y sus series de folios: una
 * sucursal sin caja existe pero no sirve, porque no se puede abrir turno
 * ahi y sin turno no se puede vender.
 */
#[Layout('layouts.app')]
class Branches extends Page
{
    public bool $showForm = false;

    public ?string $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $address = '';

    public string $phone = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('settings.view'), 403);
    }

    protected function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'min:2', 'max:10', 'alpha_dash',
                Rule::unique('branches', 'code')
                    ->where('tenant_id', auth()->user()->tenant_id)
                    ->ignore($this->editingId),
            ],
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'address' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:40'],
        ];
    }

    protected function messages(): array
    {
        return [
            'code.unique' => 'Ya tienes una sucursal con ese codigo.',
            'code.alpha_dash' => 'El codigo va sin espacios ni acentos.',
            'name.required' => 'Ponle un nombre a la sucursal.',
        ];
    }

    public function create(): void
    {
        $this->reset(['editingId', 'code', 'name', 'address', 'phone']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $branch = Branch::findOrFail($id);

        $this->editingId = $branch->id;
        $this->code = $branch->code;
        $this->name = $branch->name;
        $this->address = (string) $branch->address;
        $this->phone = (string) $branch->phone;

        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(TenantProvisioner $provisioner): void
    {
        abort_unless(auth()->user()->can('settings.edit'), 403);

        $data = $this->validate();

        if ($this->editingId !== null) {
            Branch::whereKey($this->editingId)->update([
                'code' => $data['code'],
                'name' => $data['name'],
                'address' => $data['address'] ?: null,
                'phone' => $data['phone'] ?: null,
            ]);
        } else {
            $provisioner->provisionBranch(
                code: $data['code'],
                name: $data['name'],
                address: $data['address'] ?: null,
                phone: $data['phone'] ?: null,
            );
        }

        $this->showForm = false;
        $this->reset(['editingId', 'code', 'name', 'address', 'phone']);
        $this->notify('Sucursal guardada');
    }

    /** Marca cual es la principal. Solo puede haber una. */
    public function makeDefault(string $id): void
    {
        abort_unless(auth()->user()->can('settings.edit'), 403);

        Branch::query()->update(['is_default' => false]);
        Branch::whereKey($id)->update(['is_default' => true, 'status' => 'active']);

        $this->notify('Sucursal principal actualizada');
    }

    /**
     * Apaga o enciende una sucursal.
     *
     * No se borra nunca: sus ventas, su inventario y su kardex tienen que
     * seguir siendo legibles. Apagada deja de aparecer donde se elige una.
     */
    public function toggle(string $id): void
    {
        abort_unless(auth()->user()->can('settings.edit'), 403);

        $branch = Branch::findOrFail($id);

        if ($branch->is_default && $branch->status === 'active') {
            $this->notify('La sucursal principal no se puede apagar.', 'error');

            return;
        }

        $branch->update(['status' => $branch->status === 'active' ? 'inactive' : 'active']);

        $this->notify($branch->status === 'active' ? 'Sucursal encendida' : 'Sucursal apagada');
    }

    public function render()
    {
        $branches = Branch::orderByDesc('is_default')->orderBy('name')->get();

        return view('livewire.settings.branches', [
            'branches' => $branches,
            'terminals' => Terminal::whereIn('branch_id', $branches->pluck('id'))
                ->get()
                ->groupBy('branch_id'),
            // Cuanta mercancia tiene cada una, para no apagar por error la
            // que guarda el inventario.
            'stock' => Inventory::whereIn('branch_id', $branches->pluck('id'))
                ->selectRaw('branch_id, coalesce(sum(quantity), 0) as units')
                ->groupBy('branch_id')
                ->pluck('units', 'branch_id'),
        ]);
    }
}
