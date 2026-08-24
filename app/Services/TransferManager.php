<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\DocumentSeries;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Traspasos de mercancia entre sucursales.
 *
 * El traspaso tiene dos momentos, salida y llegada, porque en el medio
 * hay mercancia que no esta en ninguna de las dos tiendas. Descontarla y
 * sumarla de golpe haria que el destino muestre existencia que todavia va
 * en camino, y quien vende ahi la ofreceria sin tenerla.
 *
 * Todo va en unidad base: un traspaso mueve mercancia, no presentaciones
 * de venta, y mezclar las dos cosas invita a mandar cajas creyendo que
 * son piezas.
 */
class TransferManager
{
    public function __construct(protected InventoryManager $inventory) {}

    /**
     * Arma un traspaso sin mover nada todavia.
     *
     * @param  array<int, array{product_id: string, variant_id?: ?string, quantity: float}>  $lines
     */
    public function create(
        string $fromBranchId,
        string $toBranchId,
        array $lines,
        ?string $notes = null,
    ): StockTransfer {
        if ($fromBranchId === $toBranchId) {
            throw new RuntimeException('El origen y el destino tienen que ser sucursales distintas.');
        }

        foreach ([$fromBranchId, $toBranchId] as $branchId) {
            if (! Branch::whereKey($branchId)->exists()) {
                throw new RuntimeException('Esa sucursal no existe.');
            }
        }

        return DB::transaction(function () use ($fromBranchId, $toBranchId, $lines, $notes) {
            $transfer = StockTransfer::create([
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,
                'folio' => $this->nextFolio($fromBranchId),
                'status' => StockTransfer::DRAFT,
                'notes' => $notes,
                'created_by' => auth()->id(),
            ]);

            $this->syncLines($transfer, $lines);

            return $transfer->load('items');
        });
    }

    /** Cambia las lineas de un traspaso que todavia no ha salido. */
    public function updateLines(StockTransfer $transfer, array $lines): StockTransfer
    {
        if (! $transfer->isDraft()) {
            throw new RuntimeException('Este traspaso ya salio: sus lineas no se pueden cambiar.');
        }

        return DB::transaction(function () use ($transfer, $lines) {
            $transfer->items()->delete();
            $this->syncLines($transfer, $lines);

            return $transfer->load('items');
        });
    }

    /**
     * Saca la mercancia del origen y la pone en camino.
     *
     * La existencia se comprueba aqui y no al armar el traspaso: entre que
     * alguien lo prepara y lo manda, la tienda sigue vendiendo.
     */
    public function send(StockTransfer $transfer): StockTransfer
    {
        if (! $transfer->isDraft()) {
            throw new RuntimeException('Este traspaso ya no esta en borrador.');
        }

        return DB::transaction(function () use ($transfer) {
            $transfer->load('items.product');

            if ($transfer->items->isEmpty()) {
                throw new RuntimeException('El traspaso no tiene productos.');
            }

            $cost = 0.0;

            foreach ($transfer->items as $item) {
                $product = $item->product;

                if ($product === null || ! $product->track_stock) {
                    continue;
                }

                $available = $this->availableAt($transfer->from_branch_id, $item);

                if ($item->quantity_sent > $available + 0.0005) {
                    throw new RuntimeException(
                        "No hay suficiente \"{$item->description}\" en la sucursal de origen: ".
                        'quedan '.rtrim(rtrim(number_format($available, 3), '0'), '.').'.',
                    );
                }

                // El costo se congela al salir: es lo que valia la
                // mercancia en el origen, y es lo que va a heredar el
                // destino cuando llegue.
                $unitCost = $this->costAt($transfer->from_branch_id, $item);
                $item->update(['unit_cost' => $unitCost]);
                $cost += $unitCost * $item->quantity_sent;

                $this->inventory->move(
                    product: $product,
                    branchId: $transfer->from_branch_id,
                    quantity: -$item->quantity_sent,
                    type: 'transfer_out',
                    reason: "Traspaso {$transfer->folio} a ".$transfer->toBranch->name,
                    variantId: $item->variant_id,
                    referenceType: 'transfer',
                    referenceId: $transfer->id,
                );
            }

            $transfer->update([
                'status' => StockTransfer::SENT,
                'total_cost' => Pricing::round($cost, 4),
                'sent_by' => auth()->id(),
                'sent_at' => now(),
            ]);

            return $transfer->fresh('items');
        });
    }

    /**
     * Recibe la mercancia en el destino.
     *
     * Se puede recibir menos de lo que salio. La diferencia no entra en
     * ningun lado: ya salio del origen y nunca llego, que es justo lo que
     * paso. El kardex de las dos sucursales lo cuenta, y el documento deja
     * el faltante a la vista.
     *
     * @param  array<string, float>|null  $received  [transfer_item_id => cantidad recibida]
     */
    public function receive(StockTransfer $transfer, ?array $received = null): StockTransfer
    {
        if (! $transfer->isInTransit()) {
            throw new RuntimeException('Solo se puede recibir un traspaso que va en camino.');
        }

        return DB::transaction(function () use ($transfer, $received) {
            $transfer->load('items.product');

            foreach ($transfer->items as $item) {
                $quantity = $received === null
                    ? $item->quantity_sent
                    : (float) ($received[$item->id] ?? 0);

                $quantity = Pricing::round(max(0, min($quantity, $item->quantity_sent)), 3);

                $item->update(['quantity_received' => $quantity]);

                $product = $item->product;

                if ($quantity <= 0 || $product === null || ! $product->track_stock) {
                    continue;
                }

                $this->inventory->move(
                    product: $product,
                    branchId: $transfer->to_branch_id,
                    quantity: $quantity,
                    type: 'transfer_in',
                    reason: "Traspaso {$transfer->folio} desde ".$transfer->fromBranch->name,
                    variantId: $item->variant_id,
                    referenceType: 'transfer',
                    referenceId: $transfer->id,
                    // Con el costo del origen: sin el, mover mercancia
                    // entre tiendas le cambiaria el costo al destino.
                    unitCost: (float) $item->unit_cost,
                );
            }

            $transfer->update([
                'status' => StockTransfer::RECEIVED,
                'received_by' => auth()->id(),
                'received_at' => now(),
            ]);

            return $transfer->fresh('items');
        });
    }

    /**
     * Manda y recibe de una sola vez.
     *
     * Es lo que hace falta cuando las dos tiendas estan a una cuadra y la
     * mercancia se lleva a mano: obligar a dos pasos ahi solo agrega
     * trabajo.
     */
    public function sendAndReceive(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer) {
            $this->send($transfer);

            return $this->receive($transfer->fresh('items'));
        });
    }

    /**
     * Cancela el traspaso.
     *
     * Si ya habia salido, la mercancia regresa al origen: esta en algun
     * lado y ese lado es de donde salio.
     */
    public function cancel(StockTransfer $transfer, string $reason): StockTransfer
    {
        if ($transfer->isReceived()) {
            throw new RuntimeException('Este traspaso ya se recibio: haz otro en sentido contrario.');
        }

        if ($transfer->isCancelled()) {
            throw new RuntimeException('Este traspaso ya estaba cancelado.');
        }

        return DB::transaction(function () use ($transfer, $reason) {
            if ($transfer->isInTransit()) {
                $transfer->load('items.product');

                foreach ($transfer->items as $item) {
                    $product = $item->product;

                    if ($product === null || ! $product->track_stock) {
                        continue;
                    }

                    $this->inventory->move(
                        product: $product,
                        branchId: $transfer->from_branch_id,
                        quantity: $item->quantity_sent,
                        type: 'transfer_in',
                        reason: "Cancelacion del traspaso {$transfer->folio}: {$reason}",
                        variantId: $item->variant_id,
                        referenceType: 'transfer',
                        referenceId: $transfer->id,
                        unitCost: (float) $item->unit_cost,
                    );
                }
            }

            $transfer->update([
                'status' => StockTransfer::CANCELLED,
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return $transfer->fresh('items');
        });
    }

    // =========================================================
    // Lineas
    // =========================================================

    protected function syncLines(StockTransfer $transfer, array $lines): void
    {
        $position = 0;

        foreach ($lines as $line) {
            $quantity = Pricing::round((float) ($line['quantity'] ?? 0), 3);

            if ($quantity <= 0) {
                continue;
            }

            $product = Product::with('baseUnit')->find($line['product_id']);

            if ($product === null) {
                throw new RuntimeException('Uno de los productos ya no existe.');
            }

            if (! $product->track_stock) {
                throw new RuntimeException("\"{$product->name}\" no maneja stock: no hay nada que traspasar.");
            }

            StockTransferItem::create([
                'transfer_id' => $transfer->id,
                'product_id' => $product->id,
                'variant_id' => $line['variant_id'] ?? null,
                'description' => $product->name,
                'sku' => $product->sku,
                'unit_label' => $product->baseUnit?->name,
                'quantity_sent' => $quantity,
                'unit_cost' => $this->costAt($transfer->from_branch_id, null, $product, $line['variant_id'] ?? null),
                'position' => $position++,
            ]);
        }

        if ($position === 0) {
            throw new RuntimeException('El traspaso no tiene productos.');
        }
    }

    /** Existencia del producto de una linea en una sucursal. */
    public function availableAt(string $branchId, StockTransferItem $item): float
    {
        return (float) (Inventory::where('branch_id', $branchId)
            ->where('product_id', $item->product_id)
            ->where('variant_id', $item->variant_id)
            ->value('quantity') ?? 0);
    }

    /**
     * Costo promedio de un producto en una sucursal.
     *
     * Cae al costo del catalogo cuando la sucursal todavia no lo ha
     * tenido: es mejor referencia que un cero.
     */
    protected function costAt(
        string $branchId,
        ?StockTransferItem $item = null,
        ?Product $product = null,
        ?string $variantId = null,
    ): float {
        $productId = $item?->product_id ?? $product?->id;
        $variantId = $item?->variant_id ?? $variantId;

        $cost = Inventory::where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('variant_id', $variantId)
            ->value('avg_cost');

        if ($cost !== null && (float) $cost > 0) {
            return (float) $cost;
        }

        return (float) ($product?->cost ?? Product::whereKey($productId)->value('cost') ?? 0);
    }

    protected function nextFolio(string $branchId): string
    {
        $series = DocumentSeries::firstOrCreate(
            ['branch_id' => $branchId, 'doc_type' => 'transfer'],
            ['tenant_id' => Tenancy::id(), 'prefix' => 'T-'],
        );

        return $series->nextFolio();
    }
}
