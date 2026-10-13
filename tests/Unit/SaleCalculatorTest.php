<?php

use App\Services\SaleCalculator as Calc;

function line($price, $qty, $type = null, $value = null): array
{
    return ['price' => $price, 'quantity' => $qty, 'discount_type' => $type, 'discount_value' => $value];
}

function paidSum(array $r): float
{
    return round(array_sum(array_column($r['lines'], 'paid_total')), 2);
}

it('totals a plain sale', function () {
    $r = Calc::calculate([1 => line(100, 2), 2 => line(50, 1)]);

    expect($r['subtotal'])->toBe(250.0)->and($r['discount_total'])->toBe(0.0)
        ->and($r['tax'])->toBe(0.0)->and($r['grand_total'])->toBe(250.0);
});

it('applies a percent line discount', function () {
    $r = Calc::calculate([1 => line(200, 1, 'percent', 10)]);

    expect($r['line_discount'])->toBe(20.0)->and($r['grand_total'])->toBe(180.0);
});

it('applies a fixed line discount', function () {
    $r = Calc::calculate([1 => line(200, 1, 'fixed', 35)]);

    expect($r['line_discount'])->toBe(35.0)->and($r['grand_total'])->toBe(165.0);
});

it('applies the order discount to what is left after line discounts', function () {
    $r = Calc::calculate([1 => line(100, 1, 'percent', 10), 2 => line(100, 1)], ['type' => 'percent', 'value' => 10]);

    // 90 + 100 = 190, minus 10% = 171
    expect($r['order_discount'])->toBe(19.0)->and($r['grand_total'])->toBe(171.0)
        ->and($r['discount_total'])->toBe(29.0);
});

it('charges tax on the discounted amount', function () {
    $r = Calc::calculate([1 => line(100, 1)], ['type' => 'fixed', 'value' => 20], 7.5);

    expect($r['taxable'])->toBe(80.0)->and($r['tax'])->toBe(6.0)->and($r['grand_total'])->toBe(86.0);
});

it('always makes the lines add up to the grand total, whatever the rounding', function () {
    foreach ([
        [[line(33.33, 3, 'percent', 10), line(10, 1), line(10, 1)], ['type' => 'fixed', 'value' => 7], 7.5],
        [[line(10, 1), line(10, 1), line(10, 1)], ['type' => 'fixed', 'value' => 10], 0],
        [[line(0.99, 7), line(1.01, 3, 'percent', 33)], ['type' => 'percent', 'value' => 12.5], 7.5],
        [[line(19.99, 1)], null, 7.5],
    ] as [$lines, $order, $rate]) {
        $r = Calc::calculate($lines, $order, $rate);
        expect(paidSum($r))->toBe($r['grand_total']);
    }
});

it('splits an awkward discount across identical lines without losing a penny', function () {
    $r = Calc::calculate([1 => line(10, 1), 2 => line(10, 1), 3 => line(10, 1)], ['type' => 'fixed', 'value' => 10]);

    $shares = array_column($r['lines'], 'order_discount');
    expect(round(array_sum($shares), 2))->toBe(10.0)->and(max($shares) - min($shares))->toBeLessThan(0.02);
});

it('handles an empty or fully discounted sale', function () {
    expect(Calc::calculate([])['grand_total'])->toBe(0.0);

    $r = Calc::calculate([1 => line(50, 2, 'percent', 100)], null, 7.5);
    expect($r['grand_total'])->toBe(0.0)->and($r['tax'])->toBe(0.0)->and(paidSum($r))->toBe(0.0);
});

it('reports the discount as a percentage of the subtotal', function () {
    $r = Calc::calculate([1 => line(100, 1, 'percent', 10)], ['type' => 'percent', 'value' => 10]);

    expect(Calc::discountPercent($r))->toBe(19.0);
    expect(Calc::discountPercent(Calc::calculate([])))->toBe(0.0);
});

it('rejects invalid discounts and tax rates', function (array $lines, ?array $order, float $rate, string $message) {
    expect(fn() => Calc::calculate($lines, $order, $rate))->toThrow(DomainException::class, $message);
})->with([
    'percent over 100' => [[1 => ['price' => 10, 'quantity' => 1, 'discount_type' => 'percent', 'discount_value' => 101]], null, 0.0, 'exceed 100%'],
    'negative' => [[1 => ['price' => 10, 'quantity' => 1, 'discount_type' => 'fixed', 'discount_value' => -1]], null, 0.0, 'negative'],
    'fixed bigger than the line' => [[1 => ['price' => 10, 'quantity' => 1, 'discount_type' => 'fixed', 'discount_value' => 11]], null, 0.0, 'exceed'],
    'unknown type' => [[1 => ['price' => 10, 'quantity' => 1, 'discount_type' => 'bogus', 'discount_value' => 1]], null, 0.0, 'percent or fixed'],
    'order discount bigger than the sale' => [[1 => ['price' => 10, 'quantity' => 1]], ['type' => 'fixed', 'value' => 50], 0.0, 'exceed'],
    'tax rate over 100' => [[1 => ['price' => 10, 'quantity' => 1]], null, 150.0, 'Tax rate'],
]);

it('treats a blank or zero discount as no discount', function () {
    foreach ([[null, null], ['percent', 0], ['fixed', ''], ['none', 5]] as [$type, $value]) {
        expect(Calc::calculate([1 => line(10, 1, $type, $value)])['grand_total'])->toBe(10.0);
    }
});
