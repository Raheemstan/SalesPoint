/*
|--------------------------------------------------------------------------
| The till's arithmetic, in the browser
|--------------------------------------------------------------------------
|
| A port of App\Services\SaleCalculator (and the service-charge sum), so the cart
| can show a line total and a grand total the instant a quantity changes instead
| of waiting for the server to answer.
|
| THESE FIGURES ARE A PREVIEW. The server is still the only thing that decides what
| a sale comes to: every change is posted, the server's own totals come back with
| the re-rendered cart panel, and they replace whatever this module showed. If the
| two ever disagreed the customer would be charged the server's figure - and
| tests/Feature/TillJsParityTest.php feeds both the same random carts to make sure
| they do not.
|
| Order of operations (identical to the PHP):
|   gross line  = price x qty
|   line net    = gross - line discount
|   order discount applies to the sum of line nets
|   tax         = (sum of nets - order discount) x rate
|   grand total = taxable + tax            (+ any service charge, added by the caller)
|
| Pure functions with no DOM, so they can be run under plain `node` as well.
*/

/**
 * Round half away from zero to 2 places the way PHP's round() does.
 *
 * Plain Math.round(x * 100) / 100 is wrong for values like 1.005 (which is stored as
 * 1.00499999999999989...): PHP pre-rounds to 15 significant digits first and gets
 * 1.01, so this does too.
 */
export function round2(value) {
    const v = Number((Math.abs(value) * 100).toPrecision(15));

    return (Math.sign(value) * Math.round(v)) / 100 || 0;
}

const TYPES = ['percent', 'fixed'];

function blank(type, value) {
    return type === null || type === undefined || type === '' || type === 'none'
        || value === null || value === undefined || value === '' || Number(value) === 0;
}

/** @throws {Error} with the same wording the server uses */
function discountAmount(type, value, of, label) {
    if (blank(type, value)) {
        return 0;
    }

    if (!TYPES.includes(type)) {
        throw new Error(`${label} type must be percent or fixed.`);
    }

    const v = Number(value);

    if (v < 0) {
        throw new Error(`${label} cannot be negative.`);
    }

    if (type === 'percent') {
        if (v > 100) {
            throw new Error(`${label} cannot exceed 100%.`);
        }

        return round2((of * v) / 100);
    }

    if (v > of + 0.004) {
        throw new Error(`${label} cannot exceed the amount it applies to.`);
    }

    return round2(v);
}

/** Split `amount` across `weights`; the last line that carries weight takes the remainder. */
function allocate(amount, weights) {
    const out = weights.map(() => 0);
    const total = weights.reduce((a, b) => a + b, 0);

    if (amount === 0 || total <= 0) {
        return out;
    }

    let last = -1;
    weights.forEach((w, i) => { if (w > 0) last = i; });

    let left = amount;

    weights.forEach((w, i) => {
        if (w <= 0) return;

        const share = i === last ? left : Math.min(left, round2((amount * w) / total));
        out[i] = round2(share);
        left = round2(left - share);
    });

    return out;
}

/**
 * @param {Array<{price:number, quantity:number, discount_type?:?string, discount_value?:?number}>} lines
 * @param {?{type:string, value:number}} orderDiscount
 * @param {number} taxRate percent
 * @returns {{lines: Array, subtotal:number, line_discount:number, order_discount:number,
 *            discount_total:number, taxable:number, tax:number, tax_rate:number, grand_total:number}}
 * @throws {Error} when a discount or the tax rate is invalid
 */
export function calculate(lines, orderDiscount = null, taxRate = 0) {
    if (taxRate < 0 || taxRate > 100) {
        throw new Error('Tax rate must be between 0 and 100.');
    }

    let subtotal = 0;
    let lineDiscountTotal = 0;
    let base = 0;

    const rows = lines.map((line) => {
        const gross = round2(Number(line.price) * Number(line.quantity));
        const discount = discountAmount(line.discount_type, line.discount_value, gross, 'Line discount');
        const net = round2(gross - discount);

        subtotal += gross;
        lineDiscountTotal += discount;
        base += net;

        return { gross, line_discount: discount, net };
    });

    subtotal = round2(subtotal);
    lineDiscountTotal = round2(lineDiscountTotal);
    base = round2(base);

    const orderAmount = orderDiscount
        ? discountAmount(orderDiscount.type, orderDiscount.value, base, 'Discount')
        : 0;

    const taxable = round2(base - orderAmount);
    const tax = round2((taxable * taxRate) / 100);

    const orderShares = allocate(orderAmount, rows.map((r) => r.net));
    const taxShares = allocate(tax, rows.map((r, i) => r.net - orderShares[i]));

    rows.forEach((row, i) => {
        row.order_discount = orderShares[i];
        row.discount_total = round2(row.line_discount + row.order_discount);
        row.tax = taxShares[i];
        row.paid_total = round2(row.net - row.order_discount + row.tax);
    });

    return {
        lines: rows,
        subtotal,
        line_discount: lineDiscountTotal,
        order_discount: orderAmount,
        discount_total: round2(lineDiscountTotal + orderAmount),
        taxable,
        tax,
        tax_rate: taxRate,
        grand_total: round2(taxable + tax),
    };
}

/** A tip / service charge on top of the bill (App\Services\TableOrderService::serviceCharge). */
export function serviceCharge(billTotal, charge) {
    if (!charge || blank(charge.type, charge.value)) {
        return 0;
    }

    const v = Number(charge.value);

    if (!TYPES.includes(charge.type) || v < 0 || (charge.type === 'percent' && v > 100)) {
        throw new Error('Invalid service charge.');
    }

    return charge.type === 'percent' ? round2((Math.max(0, billTotal) * v) / 100) : round2(v);
}

/** Format an amount the way the server's money() helper does. */
export function formatMoney(value, symbol, decimals) {
    return symbol + Number(value).toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}
