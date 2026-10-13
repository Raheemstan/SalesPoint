import {
    Chart,
    ArcElement,
    LineElement,
    BarElement,
    PointElement,
    BarController,
    LineController,
    DoughnutController,
    PieController,
    CategoryScale,
    LinearScale,
    Legend,
    Title,
    Tooltip,
    Filler,
} from 'chart.js';

/*
| Chart.js, bundled instead of fetched from jsdelivr.
|
| The tree-shakeable build is used and only the pieces the reports need are
| registered - bar, line, pie and doughnut. If a report ever needs another
| chart type, register its controller and elements here.
*/
Chart.register(
    ArcElement,
    LineElement,
    BarElement,
    PointElement,
    BarController,
    LineController,
    DoughnutController,
    PieController,
    CategoryScale,
    LinearScale,
    Legend,
    Title,
    Tooltip,
    Filler,
);

/*
| Sensible defaults so a report only has to supply data. Colours are read off
| the page so charts follow the light/dark theme instead of hardcoding a grey
| that disappears in one of them.
*/
const isDark = () => document.documentElement.classList.contains('dark');

Chart.defaults.font.family =
    'Figtree, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';
Chart.defaults.maintainAspectRatio = false;
Chart.defaults.animation = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    ? false
    : { duration: 300 };

export function applyChartTheme() {
    Chart.defaults.color = isDark() ? '#d1d5db' : '#374151';
    Chart.defaults.borderColor = isDark() ? 'rgba(255,255,255,0.08)' : 'rgba(17,24,39,0.08)';
}

applyChartTheme();

// The theme switcher adds/removes `dark` on <html>; repaint open charts to match.
new MutationObserver(() => {
    applyChartTheme();
    Object.values(Chart.instances ?? {}).forEach((chart) => chart.update('none'));
}).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

// Reports use `new Chart(...)` inline, exactly as they did with the CDN build.
window.Chart = Chart;

export default Chart;
