<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Calcula el descuento que una promocion hace sobre una linea de venta.
 *
 * El precio del producto no se toca nunca: la promocion es un descuento
 * sobre la linea. Asi el ticket muestra el precio de lista y el ahorro por
 * separado, que es lo que hace que el cliente vea la oferta, y el reporte
 * de margen sigue comparando contra el precio real.
 *
 * Las cantidades se cuentan en la presentacion vendida: un 2x1 sobre cajas
 * regala una caja, no una unidad. Es como lo entiende quien pone el
 * cartel, y evita que un 2x1 pensado para piezas regale una caja entera.
 */
class PromotionEngine
{
    /**
     * Promociones que corren en ese momento para el contexto de la venta.
     *
     * Se traen todas de una vez y se filtran en PHP: son pocas por
     * negocio, y consultarlas por linea seria una consulta por producto en
     * la caja, justo donde la lentitud se nota.
     *
     * @return Collection<int, Promotion>
     */
    public function active(
        ?string $branchId = null,
        ?string $priceListId = null,
        ?string $customerTypeId = null,
        ?Carbon $moment = null,
    ): Collection {
        $moment = $moment ?? now();

        return Promotion::active()
            ->with('targets')
            // Una acotacion en nulo significa "para todos": la promocion
            // sin sucursal corre en todas, no en ninguna.
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->where(fn ($q) => $q->whereNull('price_list_id')->orWhere('price_list_id', $priceListId))
            ->where(fn ($q) => $q->whereNull('customer_type_id')->orWhere('customer_type_id', $customerTypeId))
            ->orderByDesc('priority')
            ->get()
            ->filter(fn (Promotion $p) => $p->runsAt($moment))
            ->values();
    }

    /**
     * Si una promocion alcanza a un producto.
     */
    public function reaches(Promotion $promotion, Product $product): bool
    {
        if ($promotion->applies_to_all) {
            return true;
        }

        return $promotion->targets->contains(
            fn (PromotionTarget $t) => match ($t->target_type) {
                'product' => $t->target_id === $product->id,
                'category' => $t->target_id === $product->category_id,
                'brand' => $t->target_id === $product->brand_id,
                default => false,
            },
        );
    }

    /**
     * Descuento total que las promociones hacen sobre una linea.
     *
     * Gana la de mayor prioridad y, a igual prioridad, la que mas ahorra.
     * Si la ganadora es combinable se le suman las demas combinables; si
     * no, se aplica sola. Cada una calcula sobre el importe original de la
     * linea, y la suma se topa ahi: una linea nunca queda en negativo.
     *
     * @param  Collection<int, Promotion>  $promotions
     * @return array{
     *     discount: float, free_quantity: float,
     *     applied: array<int, array{promotion: Promotion, label: string, discount: float, free_quantity: float}>
     * }
     */
    public function forLine(
        Product $product,
        float $quantity,
        float $unitPrice,
        Collection $promotions,
        int $decimals = 2,
    ): array {
        $empty = ['discount' => 0.0, 'free_quantity' => 0.0, 'applied' => []];

        if ($quantity <= 0 || $unitPrice <= 0) {
            return $empty;
        }

        $subtotal = Pricing::round($quantity * $unitPrice, $decimals);

        $candidates = $promotions
            ->filter(fn (Promotion $p) => $this->reaches($p, $product))
            ->map(fn (Promotion $p) => [
                'promotion' => $p,
                ...$this->calculate($p, $quantity, $unitPrice, $decimals),
            ])
            ->filter(fn (array $c) => $c['discount'] > 0)
            ->sortByDesc(fn (array $c) => [$c['promotion']->priority, $c['discount']])
            ->values();

        if ($candidates->isEmpty()) {
            return $empty;
        }

        $winner = $candidates->first();

        $chosen = $winner['promotion']->combinable
            ? $candidates->filter(fn (array $c) => $c['promotion']->combinable)
            : collect([$winner]);

        $discount = Pricing::round(min($subtotal, $chosen->sum('discount')), $decimals);
        $free = Pricing::round($chosen->sum('free_quantity'), 3);

        return [
            'discount' => $discount,
            'free_quantity' => $free,
            'applied' => $chosen->map(fn (array $c) => [
                'promotion' => $c['promotion'],
                'label' => $c['promotion']->name,
                'discount' => $c['discount'],
                'free_quantity' => $c['free_quantity'],
            ])->values()->all(),
        ];
    }

    /**
     * Lo que ahorra una promocion sobre una linea, segun su tipo.
     *
     * @return array{discount: float, free_quantity: float}
     */
    public function calculate(
        Promotion $promotion,
        float $quantity,
        float $unitPrice,
        int $decimals = 2,
    ): array {
        $none = ['discount' => 0.0, 'free_quantity' => 0.0];

        return match ($promotion->type) {
            Promotion::NXM => $this->nxm($promotion, $quantity, $unitPrice, $decimals),
            Promotion::PERCENT => $quantity >= $promotion->min_quantity
                ? [
                    'discount' => Pricing::round(
                        $quantity * $unitPrice * ($promotion->discount_percent / 100),
                        $decimals,
                    ),
                    'free_quantity' => 0.0,
                ]
                : $none,
            Promotion::AMOUNT => $quantity >= $promotion->min_quantity
                ? [
                    // El monto se topa al precio unitario: un descuento
                    // mayor que el producto lo dejaria en negativo.
                    'discount' => Pricing::round(
                        $quantity * min($promotion->discount_amount, $unitPrice),
                        $decimals,
                    ),
                    'free_quantity' => 0.0,
                ]
                : $none,
            Promotion::BUNDLE => $this->bundle($promotion, $quantity, $unitPrice, $decimals),
            default => $none,
        };
    }

    /**
     * Lleva N y paga M.
     *
     * De cada `buy_quantity` unidades, `get_quantity` no se cobran. El
     * resto de la linea se cobra normal: en un 3x2, llevar 7 paga 5.
     */
    protected function nxm(Promotion $promotion, float $quantity, float $unitPrice, int $decimals): array
    {
        $buy = (int) $promotion->buy_quantity;
        $get = (int) $promotion->get_quantity;

        if ($buy <= 0 || $get <= 0 || $get >= $buy) {
            return ['discount' => 0.0, 'free_quantity' => 0.0];
        }

        $uses = (int) floor($quantity / $buy);

        if ($promotion->max_uses_per_line !== null) {
            $uses = min($uses, (int) $promotion->max_uses_per_line);
        }

        if ($uses <= 0) {
            return ['discount' => 0.0, 'free_quantity' => 0.0];
        }

        $free = $uses * $get;

        return [
            'discount' => Pricing::round($free * $unitPrice, $decimals),
            'free_quantity' => (float) $free,
        ];
    }

    /**
     * Precio cerrado por un paquete.
     *
     * Si el paquete sale mas caro que el precio suelto no se aplica: una
     * promocion que encarece es un error de captura, no una oferta.
     */
    protected function bundle(Promotion $promotion, float $quantity, float $unitPrice, int $decimals): array
    {
        $buy = (int) $promotion->buy_quantity;

        if ($buy <= 0 || $promotion->bundle_price <= 0) {
            return ['discount' => 0.0, 'free_quantity' => 0.0];
        }

        $uses = (int) floor($quantity / $buy);

        if ($promotion->max_uses_per_line !== null) {
            $uses = min($uses, (int) $promotion->max_uses_per_line);
        }

        $saving = ($buy * $unitPrice) - $promotion->bundle_price;

        if ($uses <= 0 || $saving <= 0) {
            return ['discount' => 0.0, 'free_quantity' => 0.0];
        }

        return [
            'discount' => Pricing::round($uses * $saving, $decimals),
            'free_quantity' => 0.0,
        ];
    }
}
