<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <x-hub.head />
    <title>2026 Technical Product Comparison Analysis · MBFD Hub</title>

    <script src="{{ asset('vendor/chartjs-4.4.0/chart.umd.js') }}"></script>
    @vite(['resources/css/app.css', 'resources/js/report-charts.js'])
</head>
<body data-hub-ui="2" data-hub-portal="report" class="hub-report hub-report--analysis-report antialiased">
    <div class="hub-report-navigation"><x-hub-header back-href="/workgroups/links" back-label="Back to Workgroup Links" max-width="max-w-7xl" /></div>

    <nav class="report-deck-nav px-4 py-3 sm:px-6" aria-label="Report sections">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-8 gap-y-2">
            <div>
                <p class="report-deck-heading">Technical Evaluation Command</p>
                <p class="report-deck-eyebrow">Q1 2026 Workgroup Report</p>
            </div>
            <div class="flex flex-wrap gap-x-6">
                <a href="#executive">Executive brief</a>
                <a href="#brands">Manufacturer matrix</a>
                <a href="#tools">Frontline assets</a>
                <a href="#saws">Breaching &amp; saws</a>
            </div>
        </div>
    </nav>

    <main class="mx-auto max-w-7xl space-y-12 px-4 py-10 sm:px-6 lg:px-8">

        <!-- Executive Summary -->
        <section id="executive" class="hub-panel p-6 md:p-10">
            <div class="grid grid-cols-1 items-center gap-10 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <h2 class="report-deck-eyebrow mb-3">Full Technical Product Comparison Analysis</h2>
                    <h1 class="mb-6 text-3xl font-bold leading-tight text-hub-header md:text-4xl">
                        2026 Apparatus &amp; Extrication Performance Assessment
                    </h1>
                    <p class="report-deck-body mb-4">
                        This executive-level report delivers the definitive technical evaluation of next-generation fire apparatus, forcible entry equipment, vehicle stabilization systems, and battery-operated extrication tools. Conducted by a highly specialized workgroup of municipal fire and rescue professionals during the first quarter of 2026, this analysis represents a rigorous, hands-on operational assessment.
                    </p>
                    <p class="report-deck-body">
                        Data presented in this document reflects absolute, uncompromised tactical capabilities. The workgroup meticulously measured each asset against four stringent criteria: <strong>Capability, Usability, Maintainability, and Deployability</strong>. The resulting performance scores isolate pure engineering excellence and field reliability, providing command staff with an unambiguous roadmap for outfitting frontline responders with top-tier equipment.
                    </p>
                </div>

                <div class="flex flex-col gap-4">
                    <div class="hub-well p-5">
                        <div class="report-deck-eyebrow mb-1">Highest Brand Performance Avg</div>
                        <div class="report-deck-metric report-deck-metric--lead">90.72</div>
                        <div class="mt-1 text-sm font-bold text-hub-ink">HOLMATRO</div>
                    </div>
                    <div class="hub-well p-5">
                        <div class="report-deck-eyebrow mb-1">Highest Single Tool Score</div>
                        <div class="report-deck-metric">92.02</div>
                        <div class="mt-1 text-sm font-bold text-hub-ink">PSP40 SPREADER</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Brand Manufacturer Analysis -->
        <section id="brands" class="space-y-6">
            <div class="report-deck-section-head flex items-end justify-between gap-4">
                <div>
                    <h2 class="report-deck-title">Manufacturer Performance Matrix</h2>
                    <p class="report-deck-body mt-2 max-w-2xl">
                        Aggregated operational scores for the top extrication brands. Averages are strictly derived from the performance of the mandatory frontline tactical triad: 32-inch Spreader, Cutter, and Ram platforms.
                    </p>
                </div>
                <x-heroicon-o-chart-bar class="report-deck-icon hidden md:block" aria-hidden="true" />
            </div>

            <div class="hub-panel p-6 md:p-8">
                <div class="chart-container">
                    <canvas id="brandChart" role="img" aria-label="Manufacturer performance comparison" aria-describedby="analysis-brand-description">Manufacturer performance comparison</canvas>
                </div>
                <p id="analysis-brand-description" class="hub-chart-description">Technical performance scores for the three frontline tool categories: Holmatro 90.72, Hurst 86.53, TNT 75.12, and Amkus 73.56. The chart axis runs from 65 to 95; the complete values are listed below.</p>
                <div class="hub-chart-data" tabindex="0" role="region" aria-label="Manufacturer performance chart data">
                    <table>
                        <caption>Manufacturer technical performance scores</caption>
                        <thead><tr><th scope="col">Manufacturer</th><th scope="col">Score</th></tr></thead>
                        <tbody>
                            <tr><th scope="row">Holmatro</th><td>90.72</td></tr>
                            <tr><th scope="row">Hurst</th><td>86.53</td></tr>
                            <tr><th scope="row">TNT</th><td>75.12</td></tr>
                            <tr><th scope="row">Amkus</th><td>73.56</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="hub-well p-5">
                        <h3 class="report-deck-heading mb-2">Tier 1 Leadership</h3>
                        <p class="report-deck-body text-sm">
                            <strong>Holmatro</strong> achieved the highest overall technical standing across the workgroup evaluation, demonstrating exceptional engineering in usability and maintainability. <strong>Hurst</strong> follows closely, maintaining a formidable presence in the Tier 1 category with highly capable and deployable systems.
                        </p>
                    </div>
                    <div class="hub-well p-5">
                        <h3 class="report-deck-heading mb-2">Tier 2 Standing</h3>
                        <p class="report-deck-body text-sm">
                            <strong>TNT</strong> and <strong>Amkus</strong> recorded operational averages in the mid-70s. While functionally adequate, workgroup feedback indicated notable performance gaps in maintainability and high-stress usability when compared directly against Tier 1 equivalents.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Frontline Tools Breakdown -->
        <section id="tools" class="space-y-6">
            <div class="report-deck-section-head">
                <h2 class="report-deck-title">Frontline Asset Breakdown</h2>
                <p class="report-deck-body mt-2">
                    A granular comparison of the top-performing individual tools across the core extrication categories (Spreaders, Cutters, Rams) as evaluated by the specialized workgroup.
                </p>
            </div>

            <div class="hub-panel p-6 md:p-8">
                <div class="chart-container-large">
                    <canvas id="toolsCategoryChart" role="img" aria-label="Tool category performance comparison" aria-describedby="analysis-tools-description">Tool category performance comparison</canvas>
                </div>
                <p id="analysis-tools-description" class="hub-chart-description">Composite scores compare four manufacturers across 32-inch spreaders, cutters, and rams. Holmatro has the highest score in each category. The chart axis runs from 60 to 100; every plotted score appears in the table.</p>
                <div class="hub-chart-data" tabindex="0" role="region" aria-label="Frontline tool chart data">
                    <table>
                        <caption>Frontline tool composite scores by manufacturer</caption>
                        <thead><tr><th scope="col">Tool category</th><th scope="col">Holmatro</th><th scope="col">Hurst</th><th scope="col">TNT</th><th scope="col">Amkus</th></tr></thead>
                        <tbody>
                            <tr><th scope="row">32-inch spreader</th><td>92.02</td><td>90.99</td><td>81.10</td><td>78.91</td></tr>
                            <tr><th scope="row">Cutter</th><td>88.86</td><td>82.57</td><td>67.11</td><td>74.73</td></tr>
                            <tr><th scope="row">Ram</th><td>91.29</td><td>86.02</td><td>78.31</td><td>72.29</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div class="hub-well p-5">
                        <x-heroicon-o-wrench-screwdriver class="report-deck-icon mb-2" aria-hidden="true" />
                        <h3 class="report-deck-heading mb-3">Spreader Platforms</h3>
                        <p class="report-deck-body text-sm">
                            The <strong>Holmatro PSP40</strong> (92.02) set the benchmark for the entire evaluation, achieving near-perfect marks in speed, precision, and operator feedback. The <strong>Hurst SP 777 E3</strong> (90.99) performed exceptionally well, confirming its status as a highly reliable heavy-duty extrication asset.
                        </p>
                    </div>
                    <div class="hub-well p-5">
                        <x-heroicon-o-scissors class="report-deck-icon mb-2" aria-hidden="true" />
                        <h3 class="report-deck-heading mb-3">Cutter Platforms</h3>
                        <p class="report-deck-body text-sm">
                            The <strong>Holmatro PCU30CL</strong> (88.86) established a commanding lead in the cutting category, excelling in modern high-strength steel vehicle anatomy. The <strong>Hurst S 789 E3</strong> (82.57) delivered powerful baseline capabilities but tracked lower in ergonomic usability under continuous load.
                        </p>
                    </div>
                    <div class="hub-well p-5">
                        <x-heroicon-o-wrench class="report-deck-icon mb-2" aria-hidden="true" />
                        <h3 class="report-deck-heading mb-3">Ram Platforms</h3>
                        <p class="report-deck-body text-sm">
                            The <strong>Holmatro PRA40</strong> (91.29) dominated the ram evaluations, specifically achieving a 95.00 in operator Usability. <strong>Hurst's CR 522 E3</strong> (86.02) proved to be a highly rugged alternative, favored for its watertight saltwater deployment capabilities.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Saws and Breaching -->
        <section id="saws" class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="hub-panel flex flex-col p-6 md:p-8">
                <h2 class="report-deck-title report-deck-section-head mb-6">Battery Forcible Entry Saws</h2>
                <p class="report-deck-body mb-6 flex-grow text-sm">
                    Workgroup evaluation of battery-operated rotary cut-off saws for rapid breaching, concrete, and metal cutting. The assessment revealed a massive divergence in operational effectiveness between the leading 12-inch platform and competing 14-inch models.
                </p>
                <div class="chart-container">
                    <canvas id="sawsChart" role="img" aria-label="Cut-off saw evaluation scores" aria-describedby="analysis-saws-description">Cut-off saw evaluation scores</canvas>
                </div>
                <p id="analysis-saws-description" class="hub-chart-description">Technical scores on a 0–100 chart scale: DeWalt 12-inch DCPS612AG2, 91.25; Makita 14-inch GEC01PL4, 64.83; Husqvarna 14-inch K1 Pace, 61.78.</p>
                <div class="hub-chart-data" tabindex="0" role="region" aria-label="Cut-off saw chart data">
                    <table>
                        <caption>Cut-off saw technical scores</caption>
                        <thead><tr><th scope="col">Saw</th><th scope="col">Score</th></tr></thead>
                        <tbody>
                            <tr><th scope="row">DeWalt 12-inch DCPS612AG2</th><td>91.25</td></tr>
                            <tr><th scope="row">Makita 14-inch GEC01PL4</th><td>64.83</td></tr>
                            <tr><th scope="row">Husqvarna 14-inch K1 Pace</th><td>61.78</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="flex flex-col gap-6">
                <div class="hub-panel flex flex-1 flex-col justify-center p-6 md:p-8">
                    <span class="hub-tag hub-tag--success mb-3 self-start">Category Leader</span>
                    <h3 class="report-deck-title mb-2">DEWALT 12-INCH <span class="text-lg font-normal text-hub-ink-secondary">(DCPS612AG2)</span></h3>
                    <div class="report-deck-metric report-deck-metric--lead my-4">91.25</div>
                    <p class="report-deck-body text-sm">
                        The Dewalt platform established absolute superiority in this category. Evaluators scored it an unprecedented <strong>96.00 in Capability</strong> and <strong>92.00 in Deployability</strong>. Despite the smaller 12-inch blade diameter, its gear-driven power delivery, superior ergonomics, and immediate operational readiness rendered the larger 14-inch competitors functionally obsolete in high-speed tactical scenarios.
                    </p>
                </div>
                <div class="hub-panel flex-none p-6">
                    <h3 class="report-deck-heading mb-2">MAKITA &amp; HUSQVARNA 14"</h3>
                    <p class="report-deck-body text-sm">
                        The Makita GEC01PL4 (64.83) and Husqvarna K1 Pace (61.78) struggled significantly in workgroup evaluations. Evaluators noted substantial penalties in Deployability and Usability due to excessive bulk, balance issues, and slower power spin-up times compared to the Dewalt.
                    </p>
                </div>
            </div>
        </section>

        <!-- Stabilization & Standalone -->
        <section class="grid grid-cols-1 gap-6 lg:grid-cols-3">

            <!-- Stabilization -->
            <div class="hub-panel p-6 md:p-8 lg:col-span-1">
                <h2 class="report-deck-heading report-deck-section-head mb-6">Vehicle Stabilization</h2>
                <p class="report-deck-body mb-6 text-sm">
                    Operational evaluation metrics for structural and vehicle stabilization systems, testing rapid deployment and load-bearing security.
                </p>
                <ol class="space-y-3">
                    <li class="hub-well flex items-center justify-between gap-4 p-4">
                        <div>
                            <div class="report-deck-eyebrow">Rank 1</div>
                            <div class="font-bold text-hub-ink">Holmatro V-Strut</div>
                        </div>
                        <div class="report-deck-metric report-deck-metric--sm report-deck-metric--lead">87.28</div>
                    </li>
                    <li class="hub-well flex items-center justify-between gap-4 p-4">
                        <div>
                            <div class="report-deck-eyebrow">Rank 2</div>
                            <div class="font-bold text-hub-ink">Holmatro OmniShore</div>
                        </div>
                        <div class="report-deck-metric report-deck-metric--sm">85.87</div>
                    </li>
                    <li class="hub-well flex items-center justify-between gap-4 p-4">
                        <div>
                            <div class="report-deck-eyebrow">Rank 3</div>
                            <div class="font-bold text-hub-ink">Paratech Strut Driver</div>
                        </div>
                        <div class="report-deck-metric report-deck-metric--sm">76.13</div>
                    </li>
                </ol>
            </div>

            <!-- Standalone Assets -->
            <div class="hub-panel p-6 md:p-8 lg:col-span-2">
                <h2 class="report-deck-title report-deck-section-head mb-6">Specialized Tactical Assets</h2>
                <p class="report-deck-body mb-8 text-sm">
                    The workgroup isolated two specific tools for standalone analysis. Due to their unique engineering, dimensions, and tactical deployment profiles, comparing them against standard frontline tools yields mathematically inaccurate capability assessments.
                </p>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="hub-well p-5">
                        <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
                            <h3 class="report-deck-heading">HOLMATRO T1</h3>
                            <span class="hub-tag hub-tag--info">SCORE: 82.23</span>
                        </div>
                        <h4 class="report-deck-eyebrow mb-2">Multi-Purpose Forcible Entry</h4>
                        <p class="report-deck-body text-sm">
                            The T1 is an all-in-one breaching solution (cut, wedge, ram, spread, hammer). It scored a massive <strong>88.13 in Deployability</strong>. Workgroup consensus strongly recommends the T1 for rapid entry teams dealing with inward/outward doors and heavy rebar, operating outside standard extrication parameters.
                        </p>
                    </div>

                    <div class="hub-well p-5">
                        <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
                            <h3 class="report-deck-heading">HURST M40</h3>
                            <span class="hub-tag">SCORE: 78.80</span>
                        </div>
                        <h4 class="report-deck-eyebrow mb-2">40-Inch Heavy Spreader</h4>
                        <p class="report-deck-body text-sm">
                            While possessing raw power (Capability: 83.39), the M40's immense physical footprint severely limits its standard Usability (79.29). The workgroup determined this is a highly specialized asset intended for heavy rescue apparatus, and should not be deployed as a primary tool on standard frontline engines.
                        </p>
                    </div>
                </div>
            </div>

        </section>

        <!-- Footer Recommendation -->
        <footer class="border-t border-hub-border py-10 text-center">
            <h2 class="report-deck-title mb-4">Procurement Advisory Conclusion</h2>
            <p class="report-deck-body mx-auto max-w-3xl text-sm">
                Based strictly on the quantitative and qualitative operational data extracted from the 2026 workgroup evaluation, <strong>Holmatro</strong> represents the pinnacle of modern extrication and stabilization engineering. For rotary cutting operations, the <strong>Dewalt 12-inch Powershift</strong> platform vastly outperforms traditional larger-diameter competitors. Procurement commands are advised to utilize these technical scores to ensure responding units are equipped with the highest echelon of capability and deployability available.
            </p>
        </footer>

    </main>

    <script type="module">
        // Chart colours come from the mbfd-theme.css tokens (hubColor is provided by resources/js/report-charts.js).
        const rootStyle = getComputedStyle(document.documentElement);
        const chartColors = {
            series1: hubColor('--hub-chart-1'),
            series2: hubColor('--hub-chart-2'),
            series3: hubColor('--hub-chart-3'),
            series4: hubColor('--hub-chart-4'),
            grid: hubColor('--hub-chart-grid'),
            label: hubColor('--hub-chart-label'),
            ink: hubColor('--hub-ink'),
            surface: hubColor('--hub-surface'),
        };

        const reportFont = rootStyle.getPropertyValue('--hub-font-sans').trim();
        Chart.defaults.color = chartColors.label;
        Chart.defaults.font.family = reportFont;
        Chart.defaults.plugins.tooltip.backgroundColor = hubColor('--hub-header');
        Chart.defaults.plugins.tooltip.titleColor = chartColors.surface;
        Chart.defaults.plugins.tooltip.bodyColor = chartColors.surface;
        Chart.defaults.plugins.tooltip.borderColor = chartColors.grid;
        Chart.defaults.plugins.tooltip.borderWidth = 1;
        Chart.defaults.plugins.tooltip.padding = 12;
        Chart.defaults.plugins.tooltip.titleFont = { size: 14, family: reportFont };

        const mandatoryTooltipConfig = {
            callbacks: {
                title: function(tooltipItems) {
                    const item = tooltipItems[0];
                    const label = item.chart.data.labels[item.dataIndex];
                    return Array.isArray(label) ? label.join(' ') : label;
                }
            }
        };

        function wrapText(text, max = 16) {
            if (typeof text !== 'string') return text;
            if (text.length <= max) return [text];
            const lines = [];
            let currentLine = '';
            text.split(' ').forEach(word => {
                if ((currentLine + word).length > max) {
                    if (currentLine.length > 0) lines.push(currentLine.trim());
                    currentLine = word + ' ';
                } else {
                    currentLine += word + ' ';
                }
            });
            if (currentLine.trim().length > 0) lines.push(currentLine.trim());
            return lines;
        }

        // 1. Brand Overall Chart (Horizontal Bar)
        new Chart(document.getElementById('brandChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: ['Holmatro', 'Hurst', 'TNT', 'Amkus'],
                datasets: [{
                    label: 'Technical Performance Score',
                    data: [90.72, 86.53, 75.12, 73.56],
                    backgroundColor: [chartColors.series1, chartColors.series2, chartColors.series3, chartColors.series4],
                    borderRadius: 4,
                    borderWidth: 0
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: mandatoryTooltipConfig
                },
                scales: {
                    x: {
                        beginAtZero: false,
                        min: 65,
                        max: 95,
                        grid: { color: chartColors.grid, drawBorder: false },
                        ticks: { color: chartColors.label }
                    },
                    y: {
                        grid: { display: false, drawBorder: false },
                        ticks: { font: { family: reportFont, size: 16 }, color: chartColors.ink }
                    }
                }
            }
        });

        // 2. Frontline Tools (Grouped Bar)
        new Chart(document.getElementById('toolsCategoryChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: [wrapText('32" Spreader', 16), wrapText('Cutter', 16), wrapText('Ram', 16)],
                datasets: [
                    { label: 'Holmatro', data: [92.02, 88.86, 91.29], backgroundColor: chartColors.series1, borderRadius: 4 },
                    { label: 'Hurst', data: [90.99, 82.57, 86.02], backgroundColor: chartColors.series2, borderRadius: 4 },
                    { label: 'TNT', data: [81.10, 67.11, 78.31], backgroundColor: chartColors.series3, borderRadius: 4 },
                    { label: 'Amkus', data: [78.91, 74.73, 72.29], backgroundColor: chartColors.series4, borderRadius: 4 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { color: chartColors.ink, font: { family: reportFont, size: 14 } }
                    },
                    tooltip: { callbacks: { title: mandatoryTooltipConfig.callbacks.title } }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        min: 60,
                        max: 100,
                        grid: { color: chartColors.grid },
                        ticks: { color: chartColors.label }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: chartColors.ink, font: { family: reportFont, size: 14 } }
                    }
                }
            }
        });

        // 3. Saws Performance (Bar)
        new Chart(document.getElementById('sawsChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: [
                    wrapText('Dewalt 12" DCPS612AG2', 16),
                    wrapText('Makita 14" GEC01PL4', 16),
                    wrapText('Husqvarna 14" K1 Pace', 16)
                ],
                datasets: [{
                    label: 'Technical Score',
                    data: [91.25, 64.83, 61.78],
                    backgroundColor: [chartColors.series2, chartColors.series3, chartColors.series4],
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: mandatoryTooltipConfig
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        grid: { color: chartColors.grid },
                        ticks: { color: chartColors.label }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: chartColors.ink, font: { size: 12 } }
                    }
                }
            }
        });
    </script>
    @include('components.hub-support-widget')
</body>
</html>
