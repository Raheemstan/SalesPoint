/*
|--------------------------------------------------------------------------
| The provisional receipt
|--------------------------------------------------------------------------
|
| THE PROBLEM
|
| An invoice number is allocated by the server. A sale rung up with no network
| has not reached a server, so it has no invoice number - and the customer is
| standing at the counter with their money gone, wanting a piece of paper.
|
| THE CHOICE MADE HERE
|
| They are handed a receipt carrying a **provisional reference** (OFF-A4K9),
| printed from the figures the till had, and saying in plain words that it is not
| yet in the books. When the sale syncs, the server's real invoice number is
| announced against that reference, so the two are tied together: the held-sales
| panel shows "OFF-A4K9 is now INV-20261014...-7Q2M", and a customer who comes
| back with the slip can be found by either.
|
| The alternatives were worse. Pre-allocating invoice numbers to each device
| means reserving blocks and living with gaps when a device is lost, and two
| devices can still collide if a block is ever reused. Handing over nothing at
| all means a customer with no evidence they paid, which is the worst outcome in
| a shop where a dispute is settled by whoever has the paper.
|
| NOTE ON THE FIGURES
|
| Everything printed here is the till's own arithmetic, which is why the slip
| says "provisional". The server recomputes the sale from the catalogue when it
| banks it, and its figure is the one that counts. They agree unless a price
| changed, and the panel shows the banked total when the sale syncs so a
| discrepancy is visible rather than buried.
*/

/** Money, with the shop's own symbol and decimal places. Never a hardcoded one. */
function formatter(config) {
    return (amount) =>
        config.symbol +
        Number(amount || 0).toLocaleString(undefined, {
            minimumFractionDigits: config.decimals,
            maximumFractionDigits: config.decimals,
        });
}

const esc = (value) =>
    String(value ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    })[c]);

/**
 * The receipt as a standalone HTML document.
 *
 * Standalone and inline on purpose: the device is offline, so it cannot fetch a
 * stylesheet, and a receipt that renders unstyled on the one occasion it matters
 * is not much of a receipt. Sized from the shop's 58mm/80mm receipt setting.
 */
export function provisionalReceiptHtml(record, config) {
    const money = formatter(config);
    const payload = record.payload || {};
    const lines = payload.lines || [];

    const rows = lines
        .map(
            (line) => `
            <tr>
                <td class="name">${esc(line.name || '')}<span class="qty">x${esc(line.quantity)}</span></td>
                <td class="amt">${esc(money((Number(line.price) || 0) * (Number(line.quantity) || 0)))}</td>
            </tr>`,
        )
        .join('');

    const totals = payload.totals || {};

    const totalRow = (label, amount, strong = false) =>
        Number(amount || 0) === 0 && !strong
            ? ''
            : `<tr class="${strong ? 'grand' : ''}"><td>${esc(label)}</td><td class="amt">${esc(money(amount))}</td></tr>`;

    const tenders = (payload.tenders || [])
        .filter((tender) => Number(tender.amount) > 0)
        .map((tender) => totalRow(config.methodLabels[tender.method] || tender.method, tender.amount))
        .join('');

    return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>${esc(record.reference)}</title>
<style>
    @page { margin: 4mm; }
    body { width: ${config.width}; margin: 0 auto; font: 12px/1.35 "Courier New", monospace; color: #000; }
    h1 { font-size: 14px; margin: 0; text-align: center; }
    .center { text-align: center; }
    .small { font-size: 10px; }
    .held { border: 2px dashed #000; padding: 4px; margin: 6px 0; text-align: center; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 1px 0; vertical-align: top; }
    .amt { text-align: right; white-space: nowrap; padding-left: 6px; }
    .qty { display: block; font-size: 10px; }
    .rule td { border-top: 1px dashed #000; padding-top: 3px; }
    .grand td { font-weight: bold; font-size: 13px; border-top: 1px solid #000; padding-top: 3px; }
</style>
</head>
<body onload="window.print()">
    <h1>${esc(config.storeName)}</h1>
    ${config.storeMotto ? `<p class="center small">${esc(config.storeMotto)}</p>` : ''}
    ${config.storeAddress ? `<p class="center small">${esc(config.storeAddress)}</p>` : ''}
    ${config.storePhone ? `<p class="center small">${esc(config.storePhone)}</p>` : ''}

    <div class="held">
        PROVISIONAL &mdash; NOT YET BANKED<br>
        ${esc(record.reference)}
    </div>

    <p class="small">${esc(new Date(record.rung_up_at).toLocaleString())}<br>
    ${esc(config.cashierName)}${payload.customer_name ? `<br>${esc(config.customerTerm)}: ${esc(payload.customer_name)}` : ''}</p>

    <table>
        ${rows}
        <tr class="rule"><td>Subtotal</td><td class="amt">${esc(money(totals.subtotal))}</td></tr>
        ${totalRow('Discount', totals.discount)}
        ${totals.tax_rate > 0 ? totalRow(`Tax (${totals.tax_rate}%)`, totals.tax) : ''}
        ${totals.service_charge > 0 ? totalRow(payload.service_charge?.label || 'Service charge', totals.service_charge) : ''}
        ${totalRow('Total', payload.claimed_total, true)}
        ${tenders}
        ${totalRow('Change', totals.change)}
        ${totalRow('Balance', totals.balance_due)}
    </table>

    <p class="center small">
        This ${esc(config.saleTerm.toLowerCase())} was taken while the till was offline.
        Keep this slip: the reference above is how it is traced once it reaches the books.
    </p>
    ${config.footer ? `<p class="center small">${esc(config.footer)}</p>` : ''}
</body>
</html>`;
}

/**
 * Print it.
 *
 * Opened in a window rather than drawn into the page, so the cashier's till
 * screen is not replaced by a receipt at the moment they need to serve the next
 * customer. If the browser blocks the window (a submit handler is a user
 * gesture, so it rarely does), the caller is told and the panel offers the slip
 * again - a sale must never be left with no way to produce its paper.
 */
export function printProvisionalReceipt(record, config) {
    const target = window.open('', '_blank', 'width=380,height=640');

    if (!target) {
        return false;
    }

    target.document.open();
    target.document.write(provisionalReceiptHtml(record, config));
    target.document.close();

    return true;
}
