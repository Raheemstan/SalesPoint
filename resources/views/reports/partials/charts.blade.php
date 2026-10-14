{{--
|--------------------------------------------------------------------------
| reports.partials.charts  -  the chart runtime every report shares
|--------------------------------------------------------------------------
|
| Include it once on any page with a canvas, inside @push('scripts'):
|
|   @push('scripts')
|       @include('reports.partials.charts')
|       <script>
|           spChart('sales-trend', (t) => ({ type: 'line', data: {...}, options: spAxes(t) }));
|       </script>
|   @endpush
|
| It gives a page three things and nothing else:
|
|   spChart(id, build)  build the chart for a canvas, and rebuild it when the
|                       theme changes. `build` is handed the colour tokens for
|                       the theme that is current at the moment it runs.
|   spTokens()          those tokens on their own, for a one-off.
|   spMoney(v)          the shop's currency symbol + grouped digits, read from
|                       the settings rather than hardcoded.
|
| Why rebuild rather than patch: a Chart.js config holds colours in a dozen
| nested places (ticks, grid, border, legend, tooltip, every dataset). Handing
| `build` the tokens and running it again is the only way to be sure none of
| them was missed, and it is cheap - these charts hold at most ninety points.
|
| The palette is not a matter of taste. It is the categorical / ordinal /
| diverging set from the project's data-visualisation reference, validated for
| colour-blind separation and for contrast against *this app's* two chart
| surfaces (white, and gray-800 in the dark) - not an automatic flip of the
| light values. Every chart here caps at three categorical series, which is the
| band that clears every gate on all pairs in both themes; past three, fold the
| tail into "Other" rather than inventing a fourth colour.
--}}

<script>
    (() => {
        // Including the partial twice on one page must not re-register anything.
        if (window.spChart) {
            return;
        }

        const isDark = () => document.documentElement.classList.contains('dark');

        /*
        | Light and dark are each selected for their own surface. Series colours
        | are for marks only - never for text, which wears the ink tokens below.
        */
        const THEMES = {
            light: {
                surface: '#ffffff',
                ink: '#374151',
                muted: '#6b7280',
                grid: 'rgba(17, 24, 39, 0.08)',
                axis: 'rgba(17, 24, 39, 0.18)',
                series: ['#2a78d6', '#eb6834', '#1baf7a'],
                ordinal: ['#86b6ef', '#5598e7', '#2a78d6', '#184f95'],
                up: '#2a78d6',
                down: '#d03b3b',
                // The de-emphasis grey: context behind the series that matters.
                context: '#9ca3af',
            },
            dark: {
                surface: '#1f2937',
                ink: '#d1d5db',
                muted: '#9ca3af',
                grid: 'rgba(255, 255, 255, 0.10)',
                axis: 'rgba(255, 255, 255, 0.22)',
                series: ['#3987e5', '#d95926', '#199e70'],
                ordinal: ['#b7d3f6', '#6da7ec', '#3987e5', '#1c5cab'],
                up: '#3987e5',
                down: '#e66767',
                context: '#6b7280',
            },
        };

        window.spTokens = () => THEMES[isDark() ? 'dark' : 'light'];

        const symbol = @json(currency());
        const decimals = @json((int) setting('currency_decimals', config('sectors.currency.decimals')));

        window.spMoney = (value) => symbol + Number(value || 0).toLocaleString(undefined, {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        });

        // Axis ticks get the compact form: a y-axis of 1,250,000 is unreadable.
        window.spCompact = (value) => {
            const n = Number(value || 0);
            const abs = Math.abs(n);

            if (abs >= 1e9) return (n / 1e9).toFixed(1).replace(/\.0$/, '') + 'B';
            if (abs >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
            if (abs >= 1e3) return (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'K';

            return String(n);
        };

        /*
        | The chrome every chart shares: a hairline horizontal grid, no vertical
        | one, solid (never dashed), one step off the surface so the data is the
        | only loud thing on the canvas.
        |
        |   money:      format ticks and tooltips as money
        |   horizontal: the value axis is x, not y (long category names)
        |   stacked:    part-to-whole
        |   legend:     show the legend (two or more series always should)
        */
        window.spAxes = (t, options = {}) => {
            const { money = true, horizontal = false, stacked = false, legend = false, zero = true } = options;

            const value = {
                stacked,
                beginAtZero: zero,
                grid: { color: t.grid, drawTicks: false, lineWidth: 1 },
                border: { color: t.axis, display: true },
                ticks: {
                    color: t.muted,
                    padding: 8,
                    callback: (v) => (money ? symbol + spCompact(v) : spCompact(v)),
                },
            };

            const category = {
                stacked,
                grid: { display: false },
                border: { color: t.axis },
                ticks: { color: t.muted, autoSkip: true, maxRotation: 0, padding: 6 },
            };

            return {
                indexAxis: horizontal ? 'y' : 'x',
                layout: { padding: { top: 4, right: 8 } },
                scales: horizontal ? { x: value, y: category } : { x: category, y: value },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: legend
                        ? {
                            display: true,
                            position: 'top',
                            align: 'end',
                            labels: {
                                color: t.ink,
                                usePointStyle: true,
                                pointStyle: 'circle',
                                boxWidth: 8,
                                boxHeight: 8,
                                padding: 14,
                            },
                        }
                        // One series needs no legend: the card's heading names it.
                        : { display: false },
                    tooltip: {
                        backgroundColor: isDark() ? '#111827' : '#1f2937',
                        titleColor: '#f9fafb',
                        bodyColor: '#e5e7eb',
                        borderColor: t.axis,
                        borderWidth: 1,
                        padding: 10,
                        cornerRadius: 6,
                        usePointStyle: true,
                        callbacks: {
                            label: (ctx) => {
                                const raw = ctx.parsed[horizontal ? 'x' : 'y'];
                                const shown = money ? spMoney(Math.abs(raw)) : Math.abs(raw).toLocaleString();

                                return ` ${ctx.dataset.label || ''}: ${shown}`.replace('  ', ' ');
                            },
                        },
                    },
                },
            };
        };

        /** A 2px line, invisible points, and a hover target big enough for a finger. */
        window.spLine = (t, label, data, colour, options = {}) => ({
            label,
            data,
            borderColor: colour,
            backgroundColor: options.fill ? colour + '1a' : colour,
            borderWidth: 2,
            borderDash: options.dashed ? [5, 4] : undefined,
            fill: options.fill ? 'origin' : false,
            tension: 0.25,
            pointRadius: 0,
            pointHoverRadius: 5,
            pointHoverBorderColor: t.surface,
            pointHoverBorderWidth: 2,
            pointHitRadius: 14,
        });

        /** A thin bar, rounded at the data end, square at the baseline. */
        window.spBar = (t, label, data, colour, options = {}) => ({
            label,
            data,
            backgroundColor: colour,
            borderRadius: 4,
            borderSkipped: 'start',
            maxBarThickness: 24,
            barPercentage: 0.85,
            categoryPercentage: 0.8,
            // On a stack, a 2px edge in the surface colour is the gap between
            // segments. It is spacing, not a stroke drawn round the mark.
            ...(options.stacked ? { borderWidth: 2, borderColor: t.surface } : {}),
        });

        /**
         * Draw a chart, and draw it again when the theme flips.
         *
         * Returns quietly when the canvas is missing or when Chart.js has not
         * been built yet: every chart on these pages has a table or a summary
         * line beside it, so a page with no canvas is still a usable page.
         */
        window.spChart = (id, build) => {
            const canvas = document.getElementById(id);

            if (!canvas || !window.Chart) {
                return;
            }

            let chart = new window.Chart(canvas, build(spTokens()));
            let wasDark = isDark();

            // The theme switcher toggles `dark` on <html>; nothing else here cares
            // about a class change, so compare before paying for a rebuild.
            new MutationObserver(() => {
                if (isDark() === wasDark) {
                    return;
                }

                wasDark = isDark();
                chart.destroy();
                chart = new window.Chart(canvas, build(spTokens()));
            }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        };
    })();
</script>
