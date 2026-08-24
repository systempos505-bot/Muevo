<?php

namespace App\Services;

/**
 * Motor de precios: impuesto, margen, precio sugerido y resolucion del
 * precio que aplica a una venta.
 *
 * Regla que sostiene todo el modulo:  neto + impuesto === bruto
 * siempre, al centavo. El desglose se calcula a partir de lo que el
 * cliente realmente paga, no al reves, para que el comprobante nunca
 * quede descuadrado por un redondeo.
 */
class Pricing
{
    /** Los precios capturados ya llevan el impuesto adentro. */
    public const TAX_INCLUDED = 'included';

    /** El impuesto se suma al cobrar. */
    public const TAX_ADDED = 'added';

    /**
     * Redondeo a medio arriba.
     *
     * round() de PHP ya redondea medio arriba, pero pasa por punto
     * flotante; se normaliza el valor antes para que 1.005 no caiga a
     * 1.00 por como se guarda en binario.
     */
    public static function round(float $value, int $decimals = 2): float
    {
        if (! is_finite($value)) {
            return 0.0;
        }

        $factor = 10 ** $decimals;

        return round($value * $factor + (($value >= 0 ? 1 : -1) * 1e-9)) / $factor;
    }

    /**
     * Desglosa un monto en neto, impuesto y bruto.
     *
     * El monto se interpreta segun el modo de la empresa: con impuesto
     * incluido es el bruto, sin impuesto incluido es el neto.
     *
     * @param  float  $taxRate  porcentaje, 15 significa 15%
     * @return array{net: float, tax: float, gross: float}
     */
    public static function splitTax(
        float $amount,
        float $taxRate,
        string $mode = self::TAX_INCLUDED,
        int $decimals = 2,
    ): array {
        $rate = $taxRate / 100;

        if ($mode === self::TAX_INCLUDED) {
            $gross = static::round($amount, $decimals);
            // El neto se redondea y el impuesto sale por diferencia: asi la
            // suma cierra exacta contra lo que se cobro.
            $net = static::round($gross / (1 + $rate), $decimals);

            return [
                'net' => $net,
                'tax' => static::round($gross - $net, $decimals),
                'gross' => $gross,
            ];
        }

        $net = static::round($amount, $decimals);
        $tax = static::round($net * $rate, $decimals);

        return ['net' => $net, 'tax' => $tax, 'gross' => static::round($net + $tax, $decimals)];
    }

    /**
     * Margen de ganancia sobre el costo, en porcentaje.
     * Devuelve null si no hay costo: el margen seria infinito.
     *
     * @param  float  $cost  costo de compra sin impuesto
     * @param  float  $net  precio de venta sin impuesto
     */
    public static function margin(float $cost, float $net): ?float
    {
        if ($cost <= 0) {
            return null;
        }

        return static::round((($net - $cost) / $cost) * 100, 4);
    }

    /** Utilidad en dinero por unidad vendida. */
    public static function profit(float $cost, float $net, int $decimals = 2): float
    {
        return static::round($net - $cost, $decimals);
    }

    /**
     * Precio sugerido a partir del costo y el margen deseado.
     *
     * Devuelve el valor listo para guardarse en el modo de la empresa:
     * con impuesto si es 'included', sin impuesto si es 'added'.
     */
    public static function suggest(
        float $cost,
        float $marginPercent,
        float $taxRate,
        string $mode = self::TAX_INCLUDED,
        int $decimals = 2,
    ): float {
        $net = $cost * (1 + $marginPercent / 100);

        if ($mode === self::TAX_ADDED) {
            return static::round($net, $decimals);
        }

        return static::round($net * (1 + $taxRate / 100), $decimals);
    }

    /**
     * Elige el precio unitario que aplica a una venta.
     *
     * Orden de preferencia:
     *   1. Precio de esa lista para esa presentacion, tomando el tramo de
     *      cantidad mas alto que la venta alcance.
     *   2. Precio de esa lista sin presentacion (la de por defecto),
     *      escalado por el factor de la elegida.
     *   3. Precio base de respaldo, tambien escalado.
     *
     * Devuelve null si no hay ningun precio. El POS debe impedir la venta
     * en lugar de inventar un cero.
     *
     * @param  array<int, array{price_list_id: string, product_unit_id: ?string, min_quantity: float, price: float}>  $candidates
     * @param  float  $unitFactor  cuantas unidades base trae la presentacion
     */
    public static function resolve(
        array $candidates,
        string $priceListId,
        ?string $productUnitId,
        float $quantity,
        float $unitFactor = 1.0,
        ?float $fallbackBasePrice = null,
        int $decimals = 2,
    ): ?float {
        $ofList = array_filter(
            $candidates,
            fn (array $c) => $c['price_list_id'] === $priceListId,
        );

        // De los tramos que la cantidad alcanza gana el de mayor minimo:
        // comprar 12 entra al tramo de 12, no al de 6 ni al de 1.
        $bestTier = function (array $rows) use ($quantity): ?array {
            $reachable = array_filter($rows, fn (array $c) => $quantity >= $c['min_quantity']);

            if ($reachable === []) {
                return null;
            }

            usort($reachable, fn ($a, $b) => $b['min_quantity'] <=> $a['min_quantity']);

            return $reachable[0];
        };

        if ($productUnitId !== null) {
            $own = $bestTier(array_filter(
                $ofList,
                fn (array $c) => $c['product_unit_id'] === $productUnitId,
            ));

            if ($own !== null) {
                return static::round($own['price'], $decimals);
            }
        }

        $base = $bestTier(array_filter($ofList, fn (array $c) => $c['product_unit_id'] === null));

        if ($base !== null) {
            // El tramo base esta expresado en la presentacion por defecto;
            // se escala al tamano de la elegida.
            return static::round(
                $base['price'] * ($productUnitId !== null ? $unitFactor : 1),
                $decimals,
            );
        }

        if ($fallbackBasePrice !== null) {
            return static::round($fallbackBasePrice * $unitFactor, $decimals);
        }

        return null;
    }

    /**
     * Convierte una cantidad de la presentacion vendida a unidad base,
     * que es como se guarda el inventario. 2 cajas de 24 = 48 unidades.
     */
    public static function toBaseQuantity(float $quantity, float $unitFactor, int $decimals = 3): float
    {
        return static::round($quantity * $unitFactor, $decimals);
    }

    /**
     * Totales de una linea de venta.
     *
     * El descuento se aplica antes de desglosar el impuesto, porque lo que
     * se declara es lo que efectivamente se cobro. Un descuento mayor que
     * la linea la deja en cero, nunca en negativo.
     *
     * @return array{subtotal: float, discount: float, net: float, tax: float, gross: float}
     */
    public static function line(
        float $quantity,
        float $unitPrice,
        float $taxRate,
        float $discount = 0,
        string $mode = self::TAX_INCLUDED,
        int $decimals = 2,
    ): array {
        $subtotal = static::round($quantity * $unitPrice, $decimals);
        $discount = min(static::round($discount, $decimals), $subtotal);

        $parts = static::splitTax(
            static::round($subtotal - $discount, $decimals),
            $taxRate,
            $mode,
            $decimals,
        );

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            ...$parts,
        ];
    }
}
