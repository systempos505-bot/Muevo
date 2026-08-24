<?php

namespace App\Livewire\Transfers;

use App\Livewire\Page;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Services\TransferManager;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use RuntimeException;

/**
 * Un traspaso: lo que lleva, donde va y en que momento esta.
 *
 * Las acciones cambian segun el estado, y solo se muestra la que toca:
 * un traspaso en camino se recibe, no se manda otra vez.
 */
#[Layout('layouts.app')]
class Show extends Page
{
    public StockTransfer $transfer;

    // --- Recepcion ---
    public bool $showReceive = false;

    /** [transfer_item_id => cantidad recibida] */
    public array $receivedLines = [];

    // --- Cancelacion ---
    public bool $showCancel = false;

    public string $cancelReason = '';

    public function mount(string $transferId): void
    {
        abort_unless(auth()->user()->can('inventory.view'), 403);

        $this->transfer = StockTransfer::with([
            'items.product', 'fromBranch', 'toBranch', 'creator', 'sender', 'receiver',
        ])->findOrFail($transferId);
    }

    /**
     * Existencia en el origen de cada linea.
     *
     * Se mira antes de mandar: entre que alguien arma el traspaso y lo
     * manda, la tienda sigue vendiendo.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function availability(): array
    {
        if (! $this->transfer->isDraft()) {
            return [];
        }

        $manager = app(TransferManager::class);

        return $this->transfer->items
            ->mapWithKeys(fn (StockTransferItem $item) => [
                $item->id => $manager->availableAt($this->transfer->from_branch_id, $item),
            ])
            ->all();
    }

    public function send(TransferManager $transfers): void
    {
        abort_unless(auth()->user()->can('inventory.adjust'), 403);

        $this->run(fn () => $transfers->send($this->transfer), 'Traspaso enviado');
    }

    public function sendAndReceive(TransferManager $transfers): void
    {
        abort_unless(auth()->user()->can('inventory.adjust'), 403);

        $this->run(
            fn () => $transfers->sendAndReceive($this->transfer),
            'Traspaso enviado y recibido',
        );
    }

    public function openReceive(): void
    {
        abort_unless(auth()->user()->can('inventory.adjust'), 403);

        // Se propone lo que salio: recibir completo es el caso normal, y
        // solo hay que tocar lo que llego distinto.
        $this->receivedLines = $this->transfer->items
            ->mapWithKeys(fn (StockTransferItem $item) => [$item->id => $item->quantity_sent])
            ->all();

        $this->resetValidation();
        $this->showReceive = true;
    }

    public function receive(TransferManager $transfers): void
    {
        abort_unless(auth()->user()->can('inventory.adjust'), 403);

        $received = collect($this->receivedLines)
            ->map(fn ($quantity) => (float) $quantity)
            ->all();

        $this->run(
            fn () => $transfers->receive($this->transfer, $received),
            'Traspaso recibido',
        );

        $this->showReceive = false;
    }

    public function cancel(TransferManager $transfers): void
    {
        abort_unless(auth()->user()->can('inventory.adjust'), 403);

        $this->validate(
            ['cancelReason' => ['required', 'string', 'min:5', 'max:300']],
            ['cancelReason.required' => 'Escribe por que se cancela el traspaso.'],
        );

        $this->run(
            fn () => $transfers->cancel($this->transfer, $this->cancelReason),
            'Traspaso cancelado',
        );

        $this->showCancel = false;
    }

    /**
     * Corre una accion del traspaso y refresca la pantalla.
     *
     * Los errores de negocio se muestran tal cual: estan escritos para
     * que quien esta en el mostrador sepa que hacer.
     */
    protected function run(callable $action, string $message): void
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }

        $this->transfer->refresh()->load(['items.product', 'sender', 'receiver']);
        unset($this->availability);

        $this->notify($message);
    }

    public function render()
    {
        return view('livewire.transfers.show', [
            'currency' => auth()->user()->tenant->primaryCurrency,
        ]);
    }
}
