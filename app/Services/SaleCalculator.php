<?php

namespace App\Services;

use DomainException;

/**
 * The single source of truth for sale maths: line discounts, order discount, tax,
 * and how each of those is spread across the lines (needed so a refund of one line
 * returns exactly what that line cost the customer).
 *
 * Order of operations:
 *   gross line = price x qty
 *   line net   = gross - line discount
 *   order discount applies to the sum of line nets
 *   tax        = (sum of nets - order discount) x rate      (tax-exclusive prices)
 *   grand total = subtotal - all discounts + tax
 *
 * Every amount is rounded to 2dp at the step it is produced and the last line
 * absorbs any rounding remainder, so line totals always add up to the grand total.
 */
class SaleCalculator
{
    public const TYPES = ['percent', 'fixed'];

    /**
     * @param array<int|string, array{price: float|int|string, quantity: int, discount_type?: ?string, discount_value?: float|int|string|null}> $lines keyed by any unique key (product id)
     * @param array{type: string, value: float|int|string}|null $orderDiscount
     *
     * @throws DomainException when a discount is invalid
     */
    public static function calculate(array $lines, ?array $orderDiscount = null, float $taxRate = 0.0): array
    {
        if ($taxRate < 0 || $taxRate > 100) {
            throw new DomainException('Tax rate must be between 0 and 100.');
        }

        $out = [];
        $subtotal = 0.0;
        $lineDiscountTotal = 0.0;
        $base = 0.0;

        foreach ($lines as $key => $line) {
            $gross = round((float) $line['price'] * (int) $line['quantity'], 2);
            $discount = self::discountAmount($line['discount_type'] ?? null, $line['discount_value'] ?? null, $gross, 'Line discount');
            $net = round($gross - $discount, 2);

            $out[$key] = ['gross' => $gross, 'line_discount' => $discount, 'net' => $net];

            $subtotal += $gross;
            $lineDiscountTotal += $discount;
            $base += $net;
        }

        $subtotal = round($subtotal, 2);
        $lineDiscountTotal = round($lineDiscountTotal, 2);
        $base = round($base, 2);

        $orderDiscountAmount = $orderDiscount
            ? self::discountAmount($orderDiscount['type'] ?? null, $orderDiscount['value'] ?? null, $base, 'Discount')
            : 0.0;

        $taxable = round($base - $orderDiscountAmount, 2);
        $tax = round($taxable * $taxRate / 100, 2);

        $keys = array_keys($out);

        $netWeights = [];
        foreach ($keys as $key) {
            $netWeights[] = $out[$key]['net'];
        }
        $orderShares = self::allocate($orderDiscountAmount, $netWeights, $keys);

        // Tax is charged on what is left after the order discount, so weight by that.
        $taxWeights = [];
        foreach ($keys as $i => $key) {
            $taxWeights[] = $netWeights[$i] - $orderShares[$key];
        }
        $taxShares = self::allocate($tax, $taxWeights, $keys);

        foreach ($out as $key => &$line) {
            $line['order_discount'] = $orderShares[$key] ?? 0.0;
            $line['discount_total'] = round($line['line_discount'] + $line['order_discount'], 2);
            $line['tax'] = $taxShares[$key] ?? 0.0;
            $line['paid_total'] = round($line['net'] - $line['order_discount'] + $line['tax'], 2);
        }
        unset($line);

        return [
            'lines' => $out,
            'subtotal' => $subtotal,
            'line_discount' => $lineDiscountTotal,
            'order_discount' => $orderDiscountAmount,
            'discount_total' => round($lineDiscountTotal + $orderDiscountAmount, 2),
            'taxable' => $taxable,
            'tax' => $tax,
            'tax_rate' => $taxRate,
            'grand_total' => round($taxable + $tax, 2),
        ];
    }

    /** Total discount as a percentage of the undiscounted subtotal. */
    public static function discountPercent(array $totals): float
    {
        return $totals['subtotal'] > 0 ? round($totals['discount_total'] / $totals['subtotal'] * 100, 2) : 0.0;
    }

    private static function discountAmount(?string $type, $value, float $of, string $label): float
    {
        if ($type === null || $type === '' || $type === 'none' || $value === null || $value === '' || (float) $value == 0.0) {
            return 0.0;
        }

        if (!in_array($type, self::TYPES, true)) {
            throw new DomainException("{$label} type must be percent or fixed.");
        }

        $value = (float) $value;

        if ($value < 0) {
            throw new DomainException("{$label} cannot be negative.");
        }

        if ($type === 'percent') {
            if ($value > 100) {
                throw new DomainException("{$label} cannot exceed 100%.");
            }

            return round($of * $value / 100, 2);
        }

        if ($value > $of + 0.004) {
            throw new DomainException("{$label} cannot exceed the amount it applies to (₦" . number_format($of, 2) . ').');
        }

        return round($value, 2);
    }

    /**
     * Split $amount across keys in proportion to $weights; the last key takes the remainder.
     *
     * @param array<int, float> $weights
     * @param array<int, int|string> $keys
     * @return array<int|string, float>
     */
    private static function allocate(float $amount, array $weights, array $keys): array
    {
        $result = array_fill_keys($keys, 0.0);
        $totalWeight = array_sum($weights);

        if ($amount == 0.0 || $totalWeight <= 0) {
            return $result;
        }

        // The remainder goes to the last line that carries any weight.
        $lastIndex = null;
        foreach ($weights as $i => $w) {
            if ($w > 0) {
                $lastIndex = $i;
            }
        }

        $left = $amount;
        foreach ($keys as $i => $key) {
            if ($weights[$i] <= 0) {
                continue;
            }

            $share = $i === $lastIndex ? $left : min($left, round($amount * $weights[$i] / $totalWeight, 2));
            $result[$key] = round($share, 2);
            $left = round($left - $share, 2);
        }

        return $result;
    }
}
