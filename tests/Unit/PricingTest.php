<?php

use App\Services\Pricing;

describe('round', function () {
    it('redondea medio arriba', function () {
        expect(Pricing::round(1.005))->toBe(1.01)
            ->and(Pricing::round(2.675))->toBe(2.68)
            ->and(Pricing::round(1.004))->toBe(1.0);
    });

    it('respeta los decimales pedidos', function () {
        expect(Pricing::round(1.23456, 3))->toBe(1.235)
            ->and(Pricing::round(1.5, 0))->toBe(2.0);
    });

    it('no rompe con valores no finitos', function () {
        expect(Pricing::round(INF))->toBe(0.0)
            ->and(Pricing::round(NAN))->toBe(0.0);
    });
});

describe('splitTax', function () {
    it('desglosa un precio con impuesto incluido', function () {
        $parts = Pricing::splitTax(115, 15, Pricing::TAX_INCLUDED);

        expect($parts['gross'])->toBe(115.0)
            ->and($parts['net'])->toBe(100.0)
            ->and($parts['tax'])->toBe(15.0);
    });

    it('agrega el impuesto cuando no viene incluido', function () {
        $parts = Pricing::splitTax(100, 15, Pricing::TAX_ADDED);

        expect($parts['net'])->toBe(100.0)
            ->and($parts['tax'])->toBe(15.0)
            ->and($parts['gross'])->toBe(115.0);
    });

    it('mantiene neto mas impuesto igual a bruto aunque el redondeo no cierre', function () {
        foreach ([99.99, 33.33, 0.01, 7.77, 1234.56, 19.90] as $amount) {
            $parts = Pricing::splitTax($amount, 15, Pricing::TAX_INCLUDED);

            expect(Pricing::round($parts['net'] + $parts['tax']))
                ->toBe($parts['gross'], "fallo con {$amount}");
        }
    });

    it('trata un producto exento sin impuesto', function () {
        $parts = Pricing::splitTax(50, 0, Pricing::TAX_INCLUDED);

        expect($parts['net'])->toBe(50.0)
            ->and($parts['tax'])->toBe(0.0)
            ->and($parts['gross'])->toBe(50.0);
    });
});

describe('margin', function () {
    it('calcula el margen sobre el costo', function () {
        expect(Pricing::margin(100, 130))->toBe(30.0)
            ->and(Pricing::margin(80, 100))->toBe(25.0);
    });

    it('devuelve margen negativo si se vende bajo costo', function () {
        expect(Pricing::margin(100, 90))->toBe(-10.0);
    });

    it('devuelve null sin costo, en vez de dividir entre cero', function () {
        expect(Pricing::margin(0, 100))->toBeNull();
    });
});

describe('suggest', function () {
    it('sugiere precio con impuesto incluido', function () {
        // costo 100 + 30% de margen = 130 neto, +15% de impuesto = 149.50
        expect(Pricing::suggest(100, 30, 15, Pricing::TAX_INCLUDED))->toBe(149.5);
    });

    it('sugiere precio sin impuesto', function () {
        expect(Pricing::suggest(100, 30, 15, Pricing::TAX_ADDED))->toBe(130.0);
    });

    it('ida y vuelta: el margen sugerido se recupera del precio', function () {
        $price = Pricing::suggest(250, 42, 15, Pricing::TAX_INCLUDED);
        $net = Pricing::splitTax($price, 15, Pricing::TAX_INCLUDED)['net'];

        expect(round(Pricing::margin(250, $net)))->toBe(42.0);
    });
});

describe('resolve', function () {
    $LIST = 'lista-publico';
    $OTHER = 'lista-mayoreo';
    $BOX = 'unidad-caja';

    $candidates = [
        ['price_list_id' => $LIST, 'product_unit_id' => null, 'min_quantity' => 1.0, 'price' => 10.0],
        ['price_list_id' => $LIST, 'product_unit_id' => null, 'min_quantity' => 6.0, 'price' => 9.0],
        ['price_list_id' => $LIST, 'product_unit_id' => null, 'min_quantity' => 12.0, 'price' => 8.0],
        ['price_list_id' => $OTHER, 'product_unit_id' => null, 'min_quantity' => 1.0, 'price' => 7.0],
    ];

    it('toma el precio de la lista pedida', function () use ($candidates, $OTHER) {
        expect(Pricing::resolve($candidates, $OTHER, null, 1))->toBe(7.0);
    });

    it('aplica el tramo por cantidad mas alto que se alcanza', function () use ($candidates, $LIST) {
        expect(Pricing::resolve($candidates, $LIST, null, 1))->toBe(10.0)
            ->and(Pricing::resolve($candidates, $LIST, null, 5))->toBe(10.0)
            ->and(Pricing::resolve($candidates, $LIST, null, 6))->toBe(9.0)
            ->and(Pricing::resolve($candidates, $LIST, null, 11))->toBe(9.0)
            ->and(Pricing::resolve($candidates, $LIST, null, 12))->toBe(8.0)
            ->and(Pricing::resolve($candidates, $LIST, null, 100))->toBe(8.0);
    });

    it('prefiere el precio propio de la presentacion sobre el escalado', function () use ($candidates, $LIST, $BOX) {
        $withBox = [
            ...$candidates,
            ['price_list_id' => $LIST, 'product_unit_id' => $BOX, 'min_quantity' => 1.0, 'price' => 200.0],
        ];

        // 200 propio, no 10 x 24 = 240
        expect(Pricing::resolve($withBox, $LIST, $BOX, 1, 24))->toBe(200.0);
    });

    it('escala por el factor cuando la presentacion no tiene precio propio', function () use ($candidates, $LIST, $BOX) {
        expect(Pricing::resolve($candidates, $LIST, $BOX, 1, 24))->toBe(240.0);
    });

    it('usa el precio base de respaldo si la lista no tiene ninguno', function () {
        expect(Pricing::resolve([], 'lista-vacia', null, 1, 1, 15.0))->toBe(15.0);
    });

    it('devuelve null cuando no hay precio, en vez de vender en cero', function () {
        expect(Pricing::resolve([], 'lista-vacia', null, 1))->toBeNull();
    });
});

describe('toBaseQuantity', function () {
    it('convierte presentaciones a unidad base', function () {
        expect(Pricing::toBaseQuantity(2, 24))->toBe(48.0)
            ->and(Pricing::toBaseQuantity(1, 12))->toBe(12.0)
            ->and(Pricing::toBaseQuantity(0.5, 12))->toBe(6.0);
    });
});

describe('line', function () {
    it('multiplica cantidad por precio y desglosa el impuesto', function () {
        $line = Pricing::line(3, 115, 15);

        expect($line['subtotal'])->toBe(345.0)
            ->and($line['gross'])->toBe(345.0)
            ->and($line['net'])->toBe(300.0)
            ->and($line['tax'])->toBe(45.0);
    });

    it('descuenta antes de calcular el impuesto', function () {
        $line = Pricing::line(2, 115, 15, discount: 30);

        expect($line['subtotal'])->toBe(230.0)
            ->and($line['discount'])->toBe(30.0)
            ->and($line['gross'])->toBe(200.0)
            ->and(Pricing::round($line['net'] + $line['tax']))->toBe(200.0);
    });

    it('no deja la linea en negativo por un descuento excesivo', function () {
        $line = Pricing::line(1, 100, 15, discount: 500);

        expect($line['discount'])->toBe(100.0)
            ->and($line['gross'])->toBe(0.0)
            ->and($line['net'])->toBe(0.0)
            ->and($line['tax'])->toBe(0.0);
    });

    it('maneja cantidades decimales de productos por peso', function () {
        $line = Pricing::line(1.75, 46, 15);

        expect($line['gross'])->toBe(80.5)
            ->and(Pricing::round($line['net'] + $line['tax']))->toBe(80.5);
    });
});
