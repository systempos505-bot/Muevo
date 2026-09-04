<?php

namespace App\Livewire\Quotes;

use App\Livewire\Page;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Listado de cotizaciones.
 *
 * El alta vive en su propia pantalla porque una cotizacion se arma con
 * lineas y precios, y eso no cabe comodo en una ventana encima del
 * listado.
 */
#[Layout('layouts.app')]
class Index extends Page
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('quotes.view'), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    protected function baseQuery(): Builder
    {
        return Quote::query()
            ->when($this->search, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('folio', 'like', "%{$this->search}%")
                ->orWhere('customer_name', 'like', "%{$this->search}%")
                ->orWhere('customer_phone', 'like', "%{$this->search}%")))
            /*
             * "Vencida" no es un estado guardado sino una fecha ya pasada,
             * asi que se filtra por fecha y no por columna de estado.
             */
            ->when($this->status === 'expired', fn (Builder $q) => $q
                ->whereIn('status', [Quote::PENDING, Quote::APPROVED])
                ->whereDate('valid_until', '<', today()))
            ->when(
                $this->status !== '' && $this->status !== 'expired',
                fn (Builder $q) => $q->where('status', $this->status),
            );
    }

    /** Cuantas esperan respuesta del cliente y siguen vigentes. */
    #[Computed]
    public function waiting(): int
    {
        return Quote::pending()->whereDate('valid_until', '>=', today())->count();
    }

    /** Cuantas se pasaron de fecha sin que nadie las cerrara. */
    #[Computed]
    public function expired(): int
    {
        return Quote::whereIn('status', [Quote::PENDING, Quote::APPROVED])
            ->whereDate('valid_until', '<', today())
            ->count();
    }

    public function render()
    {
        return view('livewire.quotes.index', [
            'quotes' => $this->baseQuery()
                ->with(['customer', 'branch'])
                ->orderByDesc('created_at')
                ->paginate(20),
            'currency' => auth()->user()->tenant->primaryCurrency,
        ]);
    }
}
