<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <x-hub.head />
    <title>Final Review Teams Meeting · MBFD Hub</title>

    <!-- Reveal.js core layout; colours come from the Hub tokens in report-deck.css -->
    <link rel="stylesheet" href="{{ asset('vendor/reveal-4.3.1/reset.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/reveal-4.3.1/reveal.css') }}">

    <script src="{{ asset('vendor/chartjs-4.4.0/chart.umd.js') }}"></script>
    @vite(['resources/css/app.css', 'resources/js/report-charts.js'])
</head>
<body data-hub-ui="2" data-hub-portal="report" class="hub-report hub-report--final-presentation">
    <div class="hub-report-navigation"><x-hub-header back-href="/workgroups/links" back-label="Back to Workgroup Links" max-width="max-w-7xl" /></div>

    <div class="reveal">
        <div class="slides">

            <!-- 10:00 AM | Opening -->
            <section>
                <div class="hub-panel max-w-4xl mx-auto mt-10">
                    <div class="hub-tag hub-tag--info">10:00 AM - 10:05 AM</div>
                    <h4 class="report-deck-eyebrow mb-2">FINAL REVIEW TEAMS MEETING</h4>
                    <h1 class="leading-tight mb-6">TECHNICAL PRODUCT<br><span class="text-hub-blue">COMPARISON ANALYSIS</span></h1>
                    
                    <div class="w-24 h-1 bg-hub-red mx-auto my-6" aria-hidden="true"></div>
                    
                    <div class="text-left max-w-2xl mx-auto mt-8">
                        <p class="text-xl text-hub-header font-bold mb-4 uppercase tracking-wider text-center">Meeting Objective:</p>
                        <p class="text-lg text-hub-ink-secondary italic text-center px-6">
                            "To review the final findings, address any remaining concerns, and leave this meeting with a clear recommendation on the top equipment choices for the Mid-Mount Ladder project."
                        </p>
                    </div>
                </div>
            </section>

            <!-- 10:05 AM | Administrative Overview -->
            <section>
                <div class="hub-tag hub-tag--info">10:05 AM - 10:15 AM</div>
                <h2>ADMINISTRATIVE OVERVIEW</h2>
                
                <div class="grid grid-cols-2 gap-8 max-w-5xl mx-auto mt-8">
                    <div class="hub-panel text-left">
                        <h3 class="mb-4">Workgroup Recap</h3>
                        <p class="text-hub-ink-secondary text-lg mb-4">Five rigorous evaluation sessions focusing strictly on operational readiness, tactile capability, and engineering excellence.</p>
                        <ul>
                            <li>Forcible Entry Tools</li>
                            <li>Battery-Operated Extrication Tools</li>
                            <li>Vehicle Stabilization</li>
                        </ul>
                    </div>
                    
                    <div class="hub-panel text-left">
                        <h3 class="mb-4">The Four Pillars</h3>
                        <p class="text-hub-ink-secondary text-sm mb-4">All final technical scores reflect an equal distribution across four critical performance metrics:</p>
                        <ul>
                            <li><strong>Capability:</strong> Raw torque & structural integrity</li>
                            <li><strong>Usability:</strong> Human-factors & ergonomics</li>
                            <li><strong>Maintainability:</strong> Hardware servicing requirements</li>
                            <li><strong>Deployability:</strong> Tactical readiness & weight ratio</li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- 10:15 AM | Executive Summary -->
            <section>
                <div class="hub-tag hub-tag--info">10:15 AM - 10:30 AM</div>
                <h2>EXECUTIVE SUMMARY OF FINDINGS</h2>
                
                <div class="report-summary-metrics flex justify-center gap-12 mt-8 mb-12">
                    <div class="text-center">
                        <div class="report-deck-metric">11</div>
                        <div class="report-deck-eyebrow mt-2">Evaluators</div>
                    </div>
                    <div class="text-center border-l border-r border-hub-border px-12">
                        <div class="report-deck-metric">20</div>
                        <div class="report-deck-eyebrow mt-2">Products</div>
                    </div>
                    <div class="text-center">
                        <div class="report-deck-metric report-deck-metric--lead">115</div>
                        <div class="report-deck-eyebrow mt-2">Submissions</div>
                    </div>
                </div>

                <div class="hub-panel max-w-4xl mx-auto text-left">
                    <h3 class="mb-4">Major Evaluation Themes</h3>
                    <ul>
                        <li><span class="text-hub-ink font-bold">Reliability & Ease of Use:</span> Systems that minimize operator cognitive load performed highest.</li>
                        <li><span class="text-hub-ink font-bold">Operational Performance:</span> Immediate tactical readiness was prioritized over complex digital features.</li>
                        <li><span class="report-deck-danger font-bold">Deal-Breaker Concerns:</span> Several high-scoring products raised significant safety or operational limitations that must be addressed before procurement.</li>
                    </ul>
                </div>
            </section>

            <!-- 10:30 AM | Forcible Entry Tools (Saws) -->
            <section>
                <div class="hub-tag hub-tag--info">10:30 AM - 10:45 AM</div>
                <h2>FORCIBLE ENTRY TOOLS REVIEW</h2>
                <p class="text-hub-ink-secondary mb-6">Focus: Rotary cut-off saws for rapid breaching operations.</p>
                
                <div class="grid grid-cols-12 gap-6 max-w-6xl mx-auto items-center">
                    <div class="col-span-7 glass-panel">
                        <div class="chart-container" id="container-saws-chart">
                            <canvas id="sawsChart" role="img" aria-label="Cut-off saw evaluation scores" aria-describedby="presentation-saws-description">Cut-off saw evaluation scores</canvas>
                        </div>
                        <p id="presentation-saws-description" class="hub-chart-description">Technical scores: DeWalt 12-inch 91.25; Makita 14-inch 64.83; Husqvarna 14-inch 61.78. Chart axis: 50–100.</p>
                    </div>
                    
                    <div class="col-span-5 text-left space-y-4">
                        <div class="hub-well p-4">
                            <h3 class="text-2xl mb-1">Dewalt 12-inch (DCPS612AG2)</h3>
                            <p class="mb-2"><span class="hub-tag hub-tag--success">Category Leader</span></p>
                            <p class="text-hub-ink-secondary text-sm">Achieved unanimous advancement. Superior ergonomics and gear-driven power delivery perfectly aligned with ladder company ops.</p>
                        </div>
                        
                        <div class="hub-well p-4 mt-6">
                            <h3 class="text-xl mb-1">Husqvarna & Makita 14-inch</h3>
                            <p class="mb-2"><span class="hub-tag">Secondary Competition</span></p>
                            <p class="text-hub-ink-secondary text-sm">Lower technical scores due to deployability issues (weight/balance) and specific safety mechanisms (AFT sensors) that hinder tactical breaching binds.</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 10:30 AM | Forcible Entry Tools (T1) -->
            <section>
                <div class="hub-tag hub-tag--info">10:30 AM - 10:45 AM</div>
                <h2>FORCIBLE ENTRY: SPECIALTY BREACHING</h2>
                
                <div class="hub-panel max-w-4xl mx-auto text-left">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-3xl m-0">Holmatro T1</h3>
                        <div class="text-right">
                            <div class="report-deck-metric">82.23</div>
                            <div class="report-deck-eyebrow">Technical Score</div>
                        </div>
                    </div>
                    
                    <p class="text-hub-ink-secondary text-lg mb-6">
                        Evaluated as a highly deployable (88.13), all-in-one breaching solution intended to consolidate heavy halligans and traditional rabbit tools.
                    </p>
                    
                    <div class="report-deck-callout">
                        <h4 class="report-deck-callout__label text-sm mb-2 flex items-center gap-2">
                            <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" /> COMMITTEE DISCUSSION POINT
                        </h4>
                        <p class="text-hub-ink">
                            While achieving a competitive technical score, the T1 generated concern due to <strong>documented deal-breaker issues</strong>. Committee must resolve whether these operational limitations supersede its rapid deployability benefits for the ladder truck mission.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 10:45 AM | Extrication Tools Overview -->
            <section>
                <div class="hub-tag hub-tag--info">10:45 AM - 11:10 AM</div>
                <h2>EXTRICATION TOOLS REVIEW</h2>
                <p class="text-hub-ink-secondary mb-6">Focus: Operational fit for the ladder truck mission (Spreaders, Cutters, Rams).</p>
                
                <div class="grid grid-cols-12 gap-6 max-w-6xl mx-auto items-center">
                    <div class="col-span-5 text-left space-y-6">
                        <div class="hub-panel p-6">
                            <h3 class="text-2xl mb-2">1. Holmatro</h3>
                            <div class="report-deck-metric report-deck-metric--sm report-deck-metric--lead mb-2">90.72 <span class="text-sm font-semibold text-hub-ink-secondary">Avg</span></div>
                            <p class="text-hub-ink-secondary text-sm">Scored at the absolute top of the category, driven by the PSP40 Spreader and PRA40 Ram.</p>
                        </div>
                        
                        <div class="hub-panel p-6">
                            <h3 class="text-2xl mb-2">2. Hurst</h3>
                            <div class="report-deck-metric report-deck-metric--sm mb-2">86.53 <span class="text-sm font-semibold text-hub-ink-secondary">Avg</span></div>
                            <p class="text-hub-ink-secondary text-sm">Performed very strongly, particularly within the spreader group (SP 777 E3).</p>
                        </div>
                    </div>

                    <div class="col-span-7 glass-panel">
                        <div class="chart-container" id="container-extrication-chart">
                            <canvas id="extricationChart" role="img" aria-label="Extrication tool performance comparison" aria-describedby="presentation-extrication-description">Extrication tool performance comparison</canvas>
                        </div>
                        <p id="presentation-extrication-description" class="hub-chart-description">Holmatro scores: spreader 92.02, cutter 88.86, ram 91.29. Hurst scores: spreader 90.99, cutter 82.57, ram 86.02. Chart axis: 75–95.</p>
                    </div>
                </div>
            </section>

            <!-- 10:45 AM | Extrication Alignment -->
            <section>
                <div class="hub-tag hub-tag--info">10:45 AM - 11:10 AM</div>
                <h2>EXTRICATION: ALIGNMENT & FIT</h2>
                
                <div class="hub-panel max-w-4xl mx-auto text-left">
                    <h3 class="mb-6">Committee Action Required:</h3>
                    
                    <ul class="space-y-6">
                        <li>
                            <strong class="block mb-1">Brand-by-Brand Final Discussion:</strong> 
                            Are we aligned on a top brand/tool preference, or do we require further granular debate between the Holmatro Pentheon and Hurst E3 platforms?
                        </li>
                        <li>
                            <strong class="block mb-1">Ladder Truck Mission Fit:</strong>
                            Ensure selections factor in the spatial footprint, weight-to-power ratios, and primary extrication duties specifically assigned to mid-mount ladder companies.
                        </li>
                        <li>
                            <strong class="block mb-1">Specialty Outliers:</strong>
                            Note: The Hurst M40 (40" spreader) was evaluated separately as a heavy-duty asset and is generally considered outside standard ladder company deployment parameters due to extreme dimensions.
                        </li>
                    </ul>
                </div>
            </section>

            <!-- 11:10 AM | Vehicle Stabilization -->
            <section>
                <div class="hub-tag hub-tag--info">11:10 AM - 11:25 AM</div>
                <h2>VEHICLE STABILIZATION REVIEW</h2>
                
                <div class="grid grid-cols-3 gap-6 max-w-6xl mx-auto text-left">
                    <div class="hub-panel flex flex-col">
                        <h3 class="text-xl mb-2">Holmatro V-Strut</h3>
                        <div class="report-deck-metric report-deck-metric--sm report-deck-metric--lead mb-4">87.28</div>
                        <p class="text-hub-ink-secondary text-sm flex-grow"><strong>Strongest Score.</strong> Top performance driven by its 15-second rapid auto-locking deployment capability.</p>
                    </div>

                    <div class="hub-panel flex flex-col">
                        <h3 class="text-xl mb-2">Holmatro OmniShore</h3>
                        <div class="report-deck-metric report-deck-metric--sm mb-4">85.87</div>
                        <p class="text-hub-ink-secondary text-sm mb-4">Highly modular pneumatic system.</p>
                        <p class="report-deck-danger text-xs font-bold uppercase mt-auto border-t border-hub-border pt-2">
                            Discussion: Documented deal-breaker concern regarding complexity/setup time.
                        </p>
                    </div>

                    <div class="hub-panel flex flex-col">
                        <h3 class="text-xl mb-2">Paratech Strut Driver</h3>
                        <div class="report-deck-metric report-deck-metric--sm mb-4">76.13</div>
                        <p class="text-hub-ink-secondary text-sm flex-grow">Remains a finalist. Committee must discuss its application fit (legacy mechanical thread vs. modern pneumatic/auto-lock systems).</p>
                    </div>
                </div>
            </section>

            <!-- 11:25 AM | Open Discussion -->
            <section>
                <div class="hub-tag hub-tag--info">11:25 AM - 11:40 AM</div>
                <h2>OPEN MEMBER DISCUSSION</h2>
                
                <div class="hub-panel max-w-4xl mx-auto">
                    <p class="text-xl text-hub-blue mb-6 italic">"Address disagreements or unresolved operational issues."</p>
                    
                    <div class="grid grid-cols-2 gap-4 text-left">
                        <div class="hub-well p-4">
                            <h4 class="text-lg m-0">1. Safety</h4>
                        </div>
                        <div class="hub-well p-4">
                            <h4 class="text-lg m-0">2. Simplicity of Deployment</h4>
                        </div>
                        <div class="hub-well p-4">
                            <h4 class="text-lg m-0">3. Ladder Co. Compatibility</h4>
                        </div>
                        <div class="hub-well p-4">
                            <h4 class="text-lg m-0">4. Maintenance Implications</h4>
                        </div>
                        <div class="col-span-2 hub-well p-4">
                            <h4 class="text-lg m-0">5. Space and Apparatus Fit</h4>
                        </div>
                    </div>
                    
                    <p class="report-deck-eyebrow mt-6">Please keep discussion centered on these core parameters.</p>
                </div>
            </section>

            <!-- 11:40 AM | Deliberation & 12:00 PM Closing -->
            <section>
                <div class="flex justify-center gap-4 mb-4">
                    <div class="hub-tag hub-tag--info">11:40 AM Deliberation</div>
                    <div class="hub-tag hub-tag--info">12:00 PM Closing</div>
                </div>
                <h2>FINAL CONSENSUS & NEXT STEPS</h2>
                
                <div class="hub-panel max-w-4xl mx-auto text-left">
                    <ul class="space-y-5">
                        <li>
                            <strong>Confirm Final Choices:</strong> Lock in top selections by category (Saws, Extrication, Stabilization).
                        </li>
                        <li>
                            <strong class="text-hub-blue">Identify Action Items:</strong> Define exactly what remains unresolved (e.g., pricing follow-ups, final demos, clarifications) and assign ownership.
                        </li>
                        <li>
                            <strong class="report-deck-success">Summarize Decisions:</strong> Ensure all members are clear on the path forward.
                        </li>
                        <li>
                            <strong>Assign Responsibility:</strong> Designate point person for the final recommendation package and procurement submission.
                        </li>
                    </ul>
                </div>
            </section>

        </div>
    </div>

    <!-- Reveal.js Scripts -->
    <script src="{{ asset('vendor/reveal-4.3.1/reveal.js') }}"></script>
    
    <script type="module">
        // Initialize Reveal.js
        const mobileDeck = window.matchMedia('(max-width: 640px)');
        Reveal.initialize({
            hash: true,
            transition: 'fade', 
            backgroundTransition: 'fade',
            controls: true,
            progress: true,
            center: !mobileDeck.matches,
            embedded: true,
            disableLayout: mobileDeck.matches
        });
        mobileDeck.addEventListener('change', () => Reveal.configure({ disableLayout: mobileDeck.matches, center: !mobileDeck.matches }));

        // Recalculate slide scaling when the responsive Hub header changes size.
        new ResizeObserver(() => Reveal.layout()).observe(document.querySelector('.hub-report-navigation'));

        // Global Chart.js Styling
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
        Chart.defaults.plugins.tooltip.titleFont = { family: reportFont, size: 16 };
        Chart.defaults.plugins.tooltip.bodyFont = { family: reportFont, size: 14 };
        Chart.defaults.plugins.tooltip.padding = 12;
        Chart.defaults.plugins.tooltip.borderColor = chartColors.grid;
        Chart.defaults.plugins.tooltip.borderWidth = 1;

        let sawsChartInstance = null;
        let extricationChartInstance = null;

        const initSawsChart = () => {
            const ctx = document.getElementById('sawsChart').getContext('2d');
            if (sawsChartInstance) sawsChartInstance.destroy();

            sawsChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Dewalt 12"', 'Makita 14"', 'Husqvarna 14"'],
                    datasets: [{
                        label: 'Technical Score',
                        data: [91.25, 64.83, 61.78],
                        backgroundColor: [chartColors.series2, chartColors.series3, chartColors.series4],
                        borderRadius: 6
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { display: false }
                    },
                    scales: {
                        x: {
                            min: 50, max: 100,
                            grid: { color: chartColors.grid },
                            ticks: { font: { family: reportFont, size: 14 } }
                        },
                        y: {
                            grid: { display: false },
                            ticks: { color: chartColors.ink, font: { size: 14, weight: 'bold' } }
                        }
                    },
                    animation: { duration: 1200, easing: 'easeOutQuart' }
                }
            });
        };

        const initExtricationChart = () => {
            const ctx = document.getElementById('extricationChart').getContext('2d');
            if (extricationChartInstance) extricationChartInstance.destroy();

            extricationChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Spreader', 'Cutter', 'Ram'],
                    datasets: [
                        {
                            label: 'Holmatro',
                            data: [92.02, 88.86, 91.29],
                            backgroundColor: chartColors.series1,
                            borderRadius: 4
                        },
                        {
                            label: 'Hurst',
                            data: [90.99, 82.57, 86.02],
                            backgroundColor: chartColors.series2,
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { 
                        legend: { 
                            position: 'top',
                            labels: { color: chartColors.ink, font: { family: reportFont, size: 14 } }
                        }
                    },
                    scales: {
                        y: {
                            min: 75, max: 95,
                            grid: { color: chartColors.grid },
                            ticks: { font: { family: reportFont, size: 14 } }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: chartColors.ink, font: { size: 14, weight: 'bold' } }
                        }
                    },
                    animation: { duration: 1200, easing: 'easeOutQuart' }
                }
            });
        };

        // Render charts dynamically when slides become visible
        Reveal.on('slidechanged', event => {
            if (mobileDeck.matches) window.scrollTo(0, 0);
            if (event.currentSlide.querySelector('#container-saws-chart')) {
                initSawsChart();
            }
            if (event.currentSlide.querySelector('#container-extrication-chart')) {
                initExtricationChart();
            }
        });

    </script>
</body>
</html>
