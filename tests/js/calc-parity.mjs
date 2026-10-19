// Reads [{lines, order, rate, php}] from stdin, where `php` is what App\Services\SaleCalculator produced
// (or null when it rejected the input), and checks resources/js/pos/calc.js agrees on every figure.
// Exit code 0 = identical, 1 = a disagreement (details on stdout). Run by tests/Feature/TillJsParityTest.php.
import { calculate } from '../../resources/js/pos/calc.js';

const chunks = [];
for await (const c of process.stdin) chunks.push(c);
const cases = JSON.parse(Buffer.concat(chunks).toString());

const near = (a, b) => Math.abs(a - b) < 1e-9;
const keys = ['subtotal', 'line_discount', 'order_discount', 'discount_total', 'taxable', 'tax', 'grand_total'];
const lineKeys = ['gross', 'line_discount', 'net', 'order_discount', 'tax', 'paid_total'];
const problems = [];

cases.forEach((c, n) => {
    let js = null;

    try {
        js = calculate(c.lines, c.order, c.rate);
    } catch (e) {
        js = null;
    }

    if ((js === null) !== (c.php === null)) {
        problems.push(`#${n}: validity differs (js ${js ? 'accepted' : 'rejected'}, php ${c.php ? 'accepted' : 'rejected'}) ${JSON.stringify(c.lines)}`);
        return;
    }

    if (js === null) return;

    keys.filter((k) => !near(js[k], c.php[k])).forEach((k) => problems.push(`#${n}: ${k} js=${js[k]} php=${c.php[k]}`));

    js.lines.forEach((row, i) => lineKeys
        .filter((k) => !near(row[k], c.php.lines[i][k]))
        .forEach((k) => problems.push(`#${n}: line ${i} ${k} js=${row[k]} php=${c.php.lines[i][k]}`)));
});

console.log(problems.length ? problems.slice(0, 20).join('\n') : `${cases.length} cases agree`);
process.exit(problems.length ? 1 : 0);
