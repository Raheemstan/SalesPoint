<?php

/**
 * Helpers shared by several feature tests. Loaded from tests/Pest.php.
 */

/** Build a session cart from [[Product, qty], ...] (optionally with discount fields). */
function cartOf(array $lines): array
{
    $cart = [];
    foreach ($lines as $line) {
        [$product, $qty] = $line;
        $cart[$product->id] = array_merge([
            'product_id' => $product->id,
            'name' => $product->name,
            'price' => $product->sale_price,
            'quantity' => $qty,
        ], $line[2] ?? []);
    }
    return $cart;
}

/** POST /pos/checkout with a prepared session cart (and optional order-level discount). */
function checkout(array $cart, array $overrides = [], ?array $orderDiscount = null)
{
    $session = ['cart' => $cart];
    if ($orderDiscount) {
        $session['cart_discount'] = $orderDiscount;
    }

    return test()->withSession($session)->post(route('pos.checkout'), array_merge([
        'payment_method' => 'cash',
        'paid_amount' => 100000,
    ], $overrides));
}

/**
 * POST /pos/checkout with explicit tenders, for a split payment.
 *
 * The single-payment defaults are still posted and still harmless: when
 * `tenders` is present the till takes those and ignores payment_method /
 * paid_amount (POSController::tendersFrom()), which is exactly the precedence
 * this asserts.
 *
 * @param  array<int, array{method: string, amount: mixed, reference?: ?string}>  $tenders
 */
function checkoutSplit(array $cart, array $tenders, array $overrides = [], ?array $orderDiscount = null)
{
    return checkout($cart, array_merge(['tenders' => $tenders], $overrides), $orderDiscount);
}
