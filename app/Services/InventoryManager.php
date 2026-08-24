<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductLot;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Unico punto por el que se mueve el inventario.
 *
 * Cada movimiento hace dos cosas que tienen que ocurrir juntas o no
 * ocurrir: actualizar la existencia y dejar el renglon del kardex. Si se
 * hicieran por separado en cada pantalla, tarde o temprano quedaria una
 * cantidad sin explicacion.
 *
 * La fila de existencia se bloquea mientras se actualiza, para que dos
 * ajustes simultaneos no lean el mismo saldo y escriban balances
 * inconsistentes en el kardex.
 */
class InventoryManager
{
    /** Movimientos que se pueden originar a mano desde la interfaz. */
    public const MANUAL_TYPES = [
        'adjustment' => 'Ajuste de inventario',
        'loss' => 'Merma',
        'initial' => 'Inventario inicial',
    ];

    /**
     * Aplica un movimiento y devuelve la existencia resultante.
     *
     * @param  float  $quantity  positiva entra, negativa sale. En unidad base.
     */
    public function move(
        Product $product,
        string $branchId,
        float $quantity,
        string $type,
        ?string $reason = null,
        ?string $variantId = null,
        ?ProductLot $lot = null,
        ?string $referenceType = null,
        ?string $referenceId = null,
    ): Inventory {
        if ($quantity == 0.0) {
            throw new InvalidArgumentException('La cantidad del movimiento no puede ser cero.');
        }

        if (! $product->track_stock) {
            throw new InvalidArgumentException('Este producto no maneja stock.');
        }

        return DB::transaction(function () use (
            $product, $branchId, $quantity, $type, $reason, $variantId, $lot, $referenceType, $referenceId
        ) {
            $inventory = Inventory::where('branch_id', $branchId)
                ->where('product_id', $product->id)
                ->where('variant_id', $variantId)
                ->lockForUpdate()
                ->first();

            if ($inventory === null) {
                $inventory = Inventory::create([
                    'branch_id' => $branchId,
                    'product_id' => $product->id,
                    'variant_id' => $variantId,
                    'quantity' => 0,
                    'avg_cost' => $product->cost,
                ]);
            }

            // En una entrada, el costo es lo que se esta pagando ahora.
            // En una salida, lo que costo la mercancia que sale, que es el
            // promedio acumulado. Usar el promedio tambien al entrar dejaria
            // el costo congelado para siempre.
            $unitCost = $quantity > 0
                ? (float) ($lot?->cost ?? $product->cost)
                : (float) ($inventory->avg_cost ?: $product->cost);

            // El costo promedio solo se recalcula cuando entra mercancia.
            // Una salida no cambia lo que costo lo que ya estaba guardado.
            if ($quantity > 0) {
                $inventory->avg_cost = $this->weightedCost(
                    currentQty: (float) $inventory->quantity,
                    currentCost: (float) $inventory->avg_cost,
                    incomingQty: $quantity,
                    incomingCost: (float) $unitCost,
                );
            }

            $inventory->quantity = Pricing::round((float) $inventory->quantity + $quantity, 3);
            $inventory->save();

            if ($lot !== null) {
                $lot->quantity = Pricing::round((float) $lot->quantity + $quantity, 3);
                $lot->status = $lot->quantity <= 0 ? 'depleted' : $lot->status;
                $lot->save();
            }

            InventoryMovement::create([
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'variant_id' => $variantId,
                'lot_id' => $lot?->id,
                'type' => $type,
                'quantity' => $quantity,
                'balance' => $inventory->quantity,
                'unit_cost' => $unitCost,
                'reference_type' => $referenceType ?? 'manual',
                'reference_id' => $referenceId,
                'reason' => $reason,
                'created_by' => auth()->id(),
                'created_at' => now(),
            ]);

            return $inventory;
        });
    }

    /**
     * Deja la existencia en una cantidad exacta, calculando la diferencia.
     * Es lo que hace falta al contar fisicamente: el usuario sabe cuanto
     * hay, no cuanto sobra o falta.
     */
    public function setQuantity(
        Product $product,
        string $branchId,
        float $countedQuantity,
        string $reason,
        ?string $variantId = null,
    ): ?Inventory {
        $current = (float) (Inventory::where('branch_id', $branchId)
            ->where('product_id', $product->id)
            ->where('variant_id', $variantId)
            ->value('quantity') ?? 0);

        $difference = Pricing::round($countedQuantity - $current, 3);

        // Sin diferencia no hay nada que registrar: un movimiento en cero
        // solo ensuciaria el kardex.
        if ($difference == 0.0) {
            return null;
        }

        return $this->move(
            product: $product,
            branchId: $branchId,
            quantity: $difference,
            type: 'count',
            reason: $reason,
            variantId: $variantId,
        );
    }

    /**
     * Recibe mercancia en un lote, creandolo si no existe.
     */
    public function receiveLot(
        Product $product,
        string $branchId,
        string $lotNumber,
        float $quantity,
        ?string $expiryDate = null,
        ?float $cost = null,
        ?string $variantId = null,
        string $type = 'purchase',
        ?string $reason = null,
    ): Inventory {
        if (! $product->track_lots) {
            throw new InvalidArgumentException('Este producto no maneja lotes.');
        }

        if ($product->track_expiry && $expiryDate === null) {
            throw new InvalidArgumentException('Este producto exige fecha de vencimiento.');
        }

        if ($quantity <= 0) {
            throw new InvalidArgumentException('La cantidad recibida debe ser mayor que cero.');
        }

        return DB::transaction(function () use (
            $product, $branchId, $lotNumber, $quantity, $expiryDate, $cost, $variantId, $type, $reason
        ) {
            $lot = ProductLot::firstOrNew([
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'variant_id' => $variantId,
                'lot_number' => $lotNumber,
            ]);

            $lot->expiry_date = $expiryDate ?? $lot->expiry_date;
            $lot->cost = $cost ?? $lot->cost ?? $product->cost;
            $lot->status = 'active';
            $lot->quantity ??= 0;
            $lot->save();

            // move() suma la cantidad al lote, asi que aqui solo se deja
            // creado con sus datos.
            return $this->move(
                product: $product,
                branchId: $branchId,
                quantity: $quantity,
                type: $type,
                reason: $reason,
                variantId: $variantId,
                lot: $lot,
            );
        });
    }

    /**
     * Costo promedio ponderado tras una entrada.
     *
     * Con existencia negativa el promedio anterior no significa nada
     * (se vendio lo que no habia), asi que manda el costo que entra.
     */
    protected function weightedCost(
        float $currentQty,
        float $currentCost,
        float $incomingQty,
        float $incomingCost,
    ): float {
        if ($currentQty <= 0) {
            return Pricing::round($incomingCost, 4);
        }

        $total = $currentQty + $incomingQty;

        return Pricing::round(
            (($currentQty * $currentCost) + ($incomingQty * $incomingCost)) / $total,
            4,
        );
    }
}
