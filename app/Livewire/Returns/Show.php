<?php

namespace App\Livewire\Returns;

use App\Livewire\Page;
use App\Models\CreditNote;
use Livewire\Attributes\Layout;

/** Documento de una devolucion, para imprimir o consultar. */
#[Layout('layouts.app')]
class Show extends Page
{
    public CreditNote $note;

    public function mount(string $noteId): void
    {
        abort_unless(auth()->user()->can('sales.view'), 403);

        $this->note = CreditNote::with([
            'items', 'sale', 'customer', 'user', 'branch', 'paymentMethod',
        ])->findOrFail($noteId);
    }

    public function render()
    {
        return view('livewire.returns.show', [
            'currency' => auth()->user()->tenant->primaryCurrency,
            'tenant' => auth()->user()->tenant,
        ]);
    }
}
