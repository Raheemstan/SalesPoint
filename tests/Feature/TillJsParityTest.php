<?php

use App\Services\SaleCalculator;

/*
| The till shows a line total and a grand total the instant a quantity changes, working them out in
| the browser (resources/js/pos/calc.js) before the server has answered. That is only safe if the
| browser's sum IS the server's sum, so this feeds both the same random carts - including the awkward
| half-cent prices where PHP and JavaScript round differently if you let them - and fails on the first
| disagreement. Needs `node`; skipped where it is not installed.
*/

function nodeAvailable(): bool
{
    return trim((string) @shell_exec('command -v node 2>/dev/null')) !== '';
}

test('the browser calculator agrees with SaleCalculator on random carts', function () {
    mt_srand(20261017); // the same carts every run, so a failure can be reproduced

    $prices = [0.99, 1.005, 1.01, 2.675, 3.335, 9.995, 10, 19.99, 33.33, 49.5, 120.1, 250, 999.99, 1234.56, 0.285, 7.125];
    $pick = fn (array $a) => $a[mt_rand(0, count($a) - 1)];
    $cases = [];

    for ($n = 0; $n < 1500; $n++) {
        $lines = [];

        for ($i = 0, $count = mt_rand(1, 6); $i < $count; $i++) {
            $type = $pick([null, null, 'percent', 'percent', 'fixed']);
            $lines[] = [
                'price' => $pick($prices),
                'quantity' => mt_rand(1, 12),
                'discount_type' => $type,
                'discount_value' => match ($type) {
                    'percent' => $pick([5, 7.5, 10, 12.5, 15, 33.33, 0.5, 100, 101]),
                    'fixed' => $pick([0.5, 1, 2.5, 10, 33.33, 99.99, 5000]),
                    default => null,
                },
            ];
        }

        $order = $pick([null, null, ['type' => 'percent', 'value' => $pick([1, 5, 10, 12.5, 33.33])], ['type' => 'fixed', 'value' => $pick([0.01, 1, 3.33, 50, 100000])]]);
        $rate = $pick([0, 0, 5, 7.5, 7.25, 12.5, 15, 20, 0.5]);

        try {
            $php = SaleCalculator::calculate($lines, $order, (float) $rate);
            $php['lines'] = array_values($php['lines']);
        } catch (DomainException) {
            $php = null; // an invalid discount: JavaScript has to refuse it as well
        }

        $cases[] = compact('lines', 'order', 'rate', 'php');
    }

    $process = proc_open(
        ['node', base_path('tests/js/calc-parity.mjs')],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
    );

    fwrite($pipes[0], json_encode($cases));
    fclose($pipes[0]);

    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    $exit = proc_close($process);

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('1500 cases agree');
})->skip(fn () => !nodeAvailable(), 'node is not installed');
