<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>2026 Technical Product Comparison Analysis - Extrication & Apparatus</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&family=Oswald:wght@500;700&display=swap');
        
        /* * PALETTE SELECTION: "Deep Tech / Analytical"
         * Navy: #0B132B | Slate: #1C2541 | Steel: #3A506B
         * Vibrant Cyan: #00B4D8 | Electric Mint: #48E5C2 | Alert Coral: #FF5A5F
         */
        
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #0B132B; 
            color: #F8FAFC; 
            overflow-x: hidden;
        }
        
        h1, h2, h3, h4, h5 { 
            font-family: 'Oswald', sans-serif; 
            text-transform: uppercase; 
        }
        
        .glass-panel { 
            background: linear-gradient(145deg, rgba(28, 37, 65, 0.9), rgba(11, 19, 43, 0.95));
            border: 1px solid #3A506B; 
            box-shadow: 0 15px 35px rgba(0,0,0,0.6); 
            border-radius: 1rem;
        }

        .highlight-text {
            background: -webkit-linear-gradient(45deg, #00B4D8, #48E5C2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-weight: 800;
        }

        .metric-score {
            font-family: 'Oswald', sans-serif;
            font-weight: 700;
            color: #48E5C2;
            text-shadow: 0 0 15px rgba(72, 229, 194, 0.4);
        }

        .icon-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #1C2541, #3A506B);
            color: #00B4D8;
            font-size: 24px;
            border: 1px solid rgba(0, 180, 216, 0.3);
        }

        /* Mandatory Chart Container Constraints */
        .chart-container { 
            position: relative; 
            width: 100%; 
            max-width: 800px; 
            margin-left: auto; 
            margin-right: auto; 
            height: 350px; 
            max-height: 400px; 
        }
        
        .chart-container-large { 
            position: relative; 
            width: 100%; 
            max-width: 1000px; 
            margin-left: auto; 
            margin-right: auto; 
            height: 450px; 
            max-height: 500px; 
        }

        @media (max-width: 768px) { 
            .chart-container { height: 280px; } 
            .chart-container-large { height: 350px; } 
        }
    </style>
</head>
<body class="antialiased selection:bg-[#00B4D8] selection:text-white">

    <!-- Navigation -->
    <nav class="sticky top-0 z-50 bg-[#0B132B]/90 backdrop-blur-md border-b border-[#3A506B] px-6 py-4">
        <div class="max-w-7xl mx-auto flex justify-between items-center">
            <div class="flex items-center gap-4">
                <div class="icon-box">&#9881;</div>
                <div>
                    <h1 class="text-xl md:text-2xl text-white tracking-widest leading-none">TECHNICAL EVALUATION <span class="text-[#00B4D8]">COMMAND</span></h1>
                    <span class="text-xs text-[#48E5C2] tracking-widest font-mono">Q1 2026 WORKGROUP REPORT</span>
                </div>
            </div>
            <div class="hidden lg:flex gap-8 text-sm font-semibold tracking-widest text-[#94A3B8]">
                <a href="#executive" class="hover:text-[#00B4D8] transition-colors">EXECUTIVE BRIEF</a>
                <a href="#brands" class="hover:text-[#00B4D8] transition-colors">MANUFACTURER MATRIX</a>
                <a href="#tools" class="hover:text-[#00B4D8] transition-colors">FRONTLINE ASSETS</a>
                <a href="#saws" class="hover:text-[#00B4D8] transition-colors">BREACHING & SAWS</a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 space-y-16">

        <!-- Executive Summary -->
        <section id="executive" class="glass-panel p-8 md:p-12 relative overflow-hidden">
            <div class="absolute -top-32 -right-32 w-96 h-96 rounded-full bg-[#00B4D8] opacity-10 blur-[100px]"></div>
            <div class="absolute -bottom-32 -left-32 w-96 h-96 rounded-full bg-[#48E5C2] opacity-10 blur-[100px]"></div>
            
            <div class="relative z-10 grid grid-cols-1 lg:grid-cols-3 gap-12 items-center">
                <div class="lg:col-span-2">
                    <h2 class="text-[#00B4D8] tracking-widest text-sm mb-3 font-bold border-l-4 border-[#00B4D8] pl-3">FULL TECHNICAL PRODUCT COMPARISON ANALYSIS</h2>
                    <h1 class="text-4xl md:text-5xl font-bold text-white mb-6 leading-tight uppercase">
                        2026 Apparatus & Extrication <br> Performance Assessment
                    </h1>
                    <p class="text-lg text-[#CBD5E1] leading-relaxed mb-6">
                        This executive-level report delivers the definitive technical evaluation of next-generation fire apparatus, forcible entry equipment, vehicle stabilization systems, and battery-operated extrication tools. Conducted by a highly specialized workgroup of municipal fire and rescue professionals during the first quarter of 2026, this analysis represents a rigorous, hands-on operational assessment.
                    </p>
                    <p class="text-lg text-[#CBD5E1] leading-relaxed">
                        Data presented in this document reflects absolute, uncompromised tactical capabilities. The workgroup meticulously measured each asset against four stringent criteria: <strong>Capability, Usability, Maintainability, and Deployability</strong>. The resulting performance scores isolate pure engineering excellence and field reliability, providing command staff with an unambiguous roadmap for outfitting frontline responders with top-tier equipment.
                    </p>
                </div>
                
                <div class="flex flex-col gap-6">
                    <div class="bg-[#1C2541]/80 border border-[#3A506B] p-6 rounded-xl relative overflow-hidden group hover:border-[#48E5C2] transition-colors">
                        <div class="absolute top-0 left-0 w-1 h-full bg-[#48E5C2]"></div>
                        <div class="text-[#94A3B8] text-xs uppercase tracking-widest mb-1">Highest Brand Performance Avg</div>
                        <div class="text-4xl metric-score">90.72</div>
                        <div class="text-white font-bold mt-1 text-sm tracking-wider">HOLMATRO</div>
                    </div>
                    <div class="bg-[#1C2541]/80 border border-[#3A506B] p-6 rounded-xl relative overflow-hidden group hover:border-[#00B4D8] transition-colors">
                        <div class="absolute top-0 left-0 w-1 h-full bg-[#00B4D8]"></div>
                        <div class="text-[#94A3B8] text-xs uppercase tracking-widest mb-1">Highest Single Tool Score</div>
                        <div class="text-4xl metric-score text-[#00B4D8]">92.02</div>
                        <div class="text-white font-bold mt-1 text-sm tracking-wider">PSP40 SPREADER</div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Brand Manufacturer Analysis -->
        <section id="brands" class="space-y-8">
            <div class="border-b border-[#3A506B] pb-4 flex items-end justify-between">
                <div>
                    <h2 class="text-3xl text-white">MANUFACTURER PERFORMANCE MATRIX</h2>
                    <p class="text-[#94A3B8] mt-2 max-w-2xl">
                        Aggregated operational scores for the top extrication brands. Averages are strictly derived from the performance of the mandatory frontline tactical triad: 32-inch Spreader, Cutter, and Ram platforms.
                    </p>
                </div>
                <div class="hidden md:block icon-box text-[#48E5C2] border-[#48E5C2]/30">&#128202;</div>
            </div>

            <div class="glass-panel p-6 md:p-8">
                <div class="chart-container">
                    <canvas id="brandChart"></canvas>
                </div>
                <div class="mt-8 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-[#0B132B]/50 p-5 rounded-lg border-l-4 border-l-[#48E5C2]">
                        <h4 class="text-white font-bold tracking-wide mb-2 uppercase text-sm">Tier 1 Leadership</h4>
                        <p class="text-sm text-[#CBD5E1] leading-relaxed">
                            <strong>Holmatro</strong> achieved the highest overall technical standing across the workgroup evaluation, demonstrating exceptional engineering in usability and maintainability. <strong>Hurst</strong> follows closely, maintaining a formidable presence in the Tier 1 category with highly capable and deployable systems.
                        </p>
                    </div>
                    <div class="bg-[#0B132B]/50 p-5 rounded-lg border-l-4 border-l-[#3A506B]">
                        <h4 class="text-white font-bold tracking-wide mb-2 uppercase text-sm">Tier 2 Standing</h4>
                        <p class="text-sm text-[#CBD5E1] leading-relaxed">
                            <strong>TNT</strong> and <strong>Amkus</strong> recorded operational averages in the mid-70s. While functionally adequate, workgroup feedback indicated notable performance gaps in maintainability and high-stress usability when compared directly against Tier 1 equivalents.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Frontline Tools Breakdown -->
        <section id="tools" class="space-y-8">
            <div class="border-b border-[#3A506B] pb-4">
                <h2 class="text-3xl text-white">FRONTLINE ASSET BREAKDOWN</h2>
                <p class="text-[#94A3B8] mt-2">
                    A granular comparison of the top-performing individual tools across the core extrication categories (Spreaders, Cutters, Rams) as evaluated by the specialized workgroup.
                </p>
            </div>

            <div class="glass-panel p-6 md:p-8">
                <div class="chart-container-large">
                    <canvas id="toolsCategoryChart"></canvas>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mt-10">
                    <div class="border border-[#3A506B] rounded-xl p-6 bg-gradient-to-b from-[#1C2541] to-[#0B132B]">
                        <div class="text-[#00B4D8] text-2xl mb-2">&#128295;</div>
                        <h3 class="text-white font-bold text-lg mb-3">SPREADER PLATFORMS</h3>
                        <p class="text-sm text-[#A0AEC0] leading-relaxed">
                            The <strong>Holmatro PSP40</strong> (92.02) set the benchmark for the entire evaluation, achieving near-perfect marks in speed, precision, and operator feedback. The <strong>Hurst SP 777 E3</strong> (90.99) performed exceptionally well, confirming its status as a highly reliable heavy-duty extrication asset.
                        </p>
                    </div>
                    <div class="border border-[#3A506B] rounded-xl p-6 bg-gradient-to-b from-[#1C2541] to-[#0B132B]">
                        <div class="text-[#48E5C2] text-2xl mb-2">&#9986;</div>
                        <h3 class="text-white font-bold text-lg mb-3">CUTTER PLATFORMS</h3>
                        <p class="text-sm text-[#A0AEC0] leading-relaxed">
                            The <strong>Holmatro PCU30CL</strong> (88.86) established a commanding lead in the cutting category, excelling in modern high-strength steel vehicle anatomy. The <strong>Hurst S 789 E3</strong> (82.57) delivered powerful baseline capabilities but tracked lower in ergonomic usability under continuous load.
                        </p>
                    </div>
                    <div class="border border-[#3A506B] rounded-xl p-6 bg-gradient-to-b from-[#1C2541] to-[#0B132B]">
                        <div class="text-[#00B4D8] text-2xl mb-2">&#9874;</div>
                        <h3 class="text-white font-bold text-lg mb-3">RAM PLATFORMS</h3>
                        <p class="text-sm text-[#A0AEC0] leading-relaxed">
                            The <strong>Holmatro PRA40</strong> (91.29) dominated the ram evaluations, specifically achieving a 95.00 in operator Usability. <strong>Hurst's CR 522 E3</strong> (86.02) proved to be a highly rugged alternative, favored for its watertight saltwater deployment capabilities.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Saws and Breaching -->
        <section id="saws" class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <div class="glass-panel p-8 flex flex-col">
                <h2 class="text-2xl text-white border-b border-[#3A506B] pb-3 mb-6">BATTERY FORCIBLE ENTRY SAWS</h2>
                <p class="text-[#94A3B8] text-sm mb-6 flex-grow">
                    Workgroup evaluation of battery-operated rotary cut-off saws for rapid breaching, concrete, and metal cutting. The assessment revealed a massive divergence in operational effectiveness between the leading 12-inch platform and competing 14-inch models.
                </p>
                <div class="chart-container">
                    <canvas id="sawsChart"></canvas>
                </div>
            </div>

            <div class="flex flex-col gap-6">
                <div class="glass-panel p-8 border-l-4 border-l-[#48E5C2] flex-1 flex flex-col justify-center">
                    <div class="text-[#48E5C2] font-bold tracking-widest text-xs uppercase mb-2">Category Leader</div>
                    <h3 class="text-3xl text-white font-bold mb-2">DEWALT 12-INCH <span class="text-xl font-normal text-[#94A3B8]">(DCPS612AG2)</span></h3>
                    <div class="text-5xl metric-score my-4">91.25</div>
                    <p class="text-[#CBD5E1] text-sm leading-relaxed">
                        The Dewalt platform established absolute superiority in this category. Evaluators scored it an unprecedented <strong>96.00 in Capability</strong> and <strong>92.00 in Deployability</strong>. Despite the smaller 12-inch blade diameter, its gear-driven power delivery, superior ergonomics, and immediate operational readiness rendered the larger 14-inch competitors functionally obsolete in high-speed tactical scenarios.
                    </p>
                </div>
                <div class="glass-panel p-6 border-l-4 border-l-[#3A506B] flex-none">
                    <h3 class="text-xl text-white font-bold mb-2">MAKITA &amp; HUSQVARNA 14"</h3>
                    <p class="text-[#94A3B8] text-sm leading-relaxed">
                        The Makita GEC01PL4 (64.83) and Husqvarna K1 Pace (61.78) struggled significantly in workgroup evaluations. Evaluators noted substantial penalties in Deployability and Usability due to excessive bulk, balance issues, and slower power spin-up times compared to the Dewalt.
                    </p>
                </div>
            </div>
        </section>

        <!-- Stabilization & Standalone -->
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            
            <!-- Stabilization -->
            <div class="lg:col-span-1 glass-panel p-8">
                <h2 class="text-xl text-white border-b border-[#3A506B] pb-3 mb-6">VEHICLE STABILIZATION</h2>
                <p class="text-[#94A3B8] text-sm mb-6">
                    Operational evaluation metrics for structural and vehicle stabilization systems, testing rapid deployment and load-bearing security.
                </p>
                <div class="space-y-4">
                    <div class="bg-[#1C2541] p-4 rounded-lg border border-[#00B4D8]/30 flex justify-between items-center">
                        <div>
                            <div class="text-[#00B4D8] text-xs font-bold tracking-wider">RANK 1</div>
                            <div class="text-white font-bold">Holmatro V-Strut</div>
                        </div>
                        <div class="text-2xl font-mono text-[#48E5C2] font-bold">87.28</div>
                    </div>
                    <div class="bg-[#1C2541] p-4 rounded-lg border border-[#3A506B] flex justify-between items-center">
                        <div>
                            <div class="text-[#94A3B8] text-xs font-bold tracking-wider">RANK 2</div>
                            <div class="text-white font-bold">Holmatro OmniShore</div>
                        </div>
                        <div class="text-2xl font-mono text-white font-bold">85.87</div>
                    </div>
                    <div class="bg-[#1C2541] p-4 rounded-lg border border-[#3A506B] flex justify-between items-center">
                        <div>
                            <div class="text-[#94A3B8] text-xs font-bold tracking-wider">RANK 3</div>
                            <div class="text-white font-bold">Paratech Strut Driver</div>
                        </div>
                        <div class="text-2xl font-mono text-white font-bold">76.13</div>
                    </div>
                </div>
            </div>

            <!-- Standalone Assets -->
            <div class="lg:col-span-2 glass-panel p-8 relative overflow-hidden">
                <div class="absolute top-0 right-0 p-8 text-8xl opacity-5 text-[#3A506B] font-bold">&#9888;</div>
                <h2 class="text-2xl text-white border-b border-[#3A506B] pb-3 mb-6 relative z-10">SPECIALIZED TACTICAL ASSETS</h2>
                <p class="text-[#94A3B8] text-sm mb-8 relative z-10">
                    The workgroup isolated two specific tools for standalone analysis. Due to their unique engineering, dimensions, and tactical deployment profiles, comparing them against standard frontline tools yields mathematically inaccurate capability assessments.
                </p>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 relative z-10">
                    <div class="bg-[#0B132B]/80 p-6 rounded-xl border border-[#00B4D8]/50">
                        <div class="flex justify-between items-start mb-4">
                            <h3 class="text-xl text-white font-bold">HOLMATRO T1</h3>
                            <span class="bg-[#00B4D8]/20 text-[#00B4D8] text-xs px-2 py-1 rounded font-bold">SCORE: 82.23</span>
                        </div>
                        <h4 class="text-xs text-[#48E5C2] uppercase tracking-widest mb-2">Multi-Purpose Forcible Entry</h4>
                        <p class="text-sm text-[#CBD5E1] leading-relaxed">
                            The T1 is an all-in-one breaching solution (cut, wedge, ram, spread, hammer). It scored a massive <strong>88.13 in Deployability</strong>. Workgroup consensus strongly recommends the T1 for rapid entry teams dealing with inward/outward doors and heavy rebar, operating outside standard extrication parameters.
                        </p>
                    </div>

                    <div class="bg-[#0B132B]/80 p-6 rounded-xl border border-[#3A506B]">
                        <div class="flex justify-between items-start mb-4">
                            <h3 class="text-xl text-white font-bold">HURST M40</h3>
                            <span class="bg-[#3A506B]/50 text-white text-xs px-2 py-1 rounded font-bold">SCORE: 78.80</span>
                        </div>
                        <h4 class="text-xs text-[#94A3B8] uppercase tracking-widest mb-2">40-Inch Heavy Spreader</h4>
                        <p class="text-sm text-[#CBD5E1] leading-relaxed">
                            While possessing raw power (Capability: 83.39), the M40's immense physical footprint severely limits its standard Usability (79.29). The workgroup determined this is a highly specialized asset intended for heavy rescue apparatus, and should not be deployed as a primary tool on standard frontline engines.
                        </p>
                    </div>
                </div>
            </div>

        </section>

        <!-- Footer Recommendation -->
        <footer class="text-center py-12 border-t border-[#3A506B]">
            <h2 class="text-2xl text-white mb-4 tracking-wider">PROCUREMENT ADVISORY CONCLUSION</h2>
            <p class="text-[#A0AEC0] max-w-3xl mx-auto text-sm leading-relaxed">
                Based strictly on the quantitative and qualitative operational data extracted from the 2026 workgroup evaluation, <strong>Holmatro</strong> represents the pinnacle of modern extrication and stabilization engineering. For rotary cutting operations, the <strong>Dewalt 12-inch Powershift</strong> platform vastly outperforms traditional larger-diameter competitors. Procurement commands are advised to utilize these technical scores to ensure responding units are equipped with the highest echelon of capability and deployability available.
            </p>
        </footer>

    </main>

    <script>
        const chartColors = {
            navy: '#0B132B',
            slate: '#1C2541',
            steel: '#3A506B',
            cyan: '#00B4D8',
            mint: '#48E5C2',
            white: '#FFFFFF',
            gray: '#94A3B8'
        };

        Chart.defaults.color = chartColors.gray;
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(11, 19, 43, 0.95)';
        Chart.defaults.plugins.tooltip.titleColor = chartColors.white;
        Chart.defaults.plugins.tooltip.bodyColor = chartColors.mint;
        Chart.defaults.plugins.tooltip.borderColor = chartColors.cyan;
        Chart.defaults.plugins.tooltip.borderWidth = 1;
        Chart.defaults.plugins.tooltip.padding = 12;
        Chart.defaults.plugins.tooltip.titleFont = { size: 14, family: "'Oswald', sans-serif" };

        const mandatoryTooltipConfig = {
            callbacks: {
                title: function(tooltipItems) {
                    const item = tooltipItems[0];
                    let label = item.chart.data.labels[item.dataIndex];
                    if (Array.isArray(label)) {
                        return label.join(' ');
                    } else {
                        return label;
                    }
                }
            }
        };

        function wrapText(text, max = 16) {
            if(typeof text !== 'string') return text;
            if (text.length <= max) return [text];
            let words = text.split(' ');
            let lines = [];
            let currentLine = '';
            words.forEach(word => {
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
        const brandCtx = document.getElementById('brandChart').getContext('2d');
        new Chart(brandCtx, {
            type: 'bar',
            data: {
                labels: ['Holmatro', 'Hurst', 'TNT', 'Amkus'],
                datasets: [{
                    label: 'Technical Performance Score',
                    data: [90.72, 86.53, 75.12, 73.56],
                    backgroundColor: [
                        chartColors.cyan, 
                        chartColors.steel, 
                        'rgba(58, 80, 107, 0.5)', 
                        'rgba(58, 80, 107, 0.3)'
                    ],
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
                        grid: { color: 'rgba(58, 80, 107, 0.3)', drawBorder: false },
                        ticks: { color: chartColors.gray }
                    },
                    y: {
                        grid: { display: false, drawBorder: false },
                        ticks: { font: { family: "'Oswald', sans-serif", size: 16 }, color: chartColors.white }
                    }
                }
            }
        });

        // 2. Frontline Tools (Grouped Bar)
        const toolsCtx = document.getElementById('toolsCategoryChart').getContext('2d');
        const labelsSpreader = wrapText('32" Spreader', 16);
        const labelsCutter = wrapText('Cutter', 16);
        const labelsRam = wrapText('Ram', 16);

        new Chart(toolsCtx, {
            type: 'bar',
            data: {
                labels: [labelsSpreader, labelsCutter, labelsRam],
                datasets: [
                    {
                        label: 'Holmatro',
                        data: [92.02, 88.86, 91.29],
                        backgroundColor: chartColors.cyan,
                        borderRadius: 4
                    },
                    {
                        label: 'Hurst',
                        data: [90.99, 82.57, 86.02],
                        backgroundColor: chartColors.mint,
                        borderRadius: 4
                    },
                    {
                        label: 'TNT',
                        data: [81.10, 67.11, 78.31],
                        backgroundColor: chartColors.steel,
                        borderRadius: 4
                    },
                    {
                        label: 'Amkus',
                        data: [78.91, 74.73, 72.29],
                        backgroundColor: 'rgba(58, 80, 107, 0.4)',
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
                        labels: { color: chartColors.white, font: { family: "'Oswald', sans-serif", size: 14 } } 
                    },
                    tooltip: {
                        callbacks: {
                            title: mandatoryTooltipConfig.callbacks.title
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        min: 60,
                        max: 100,
                        grid: { color: 'rgba(58, 80, 107, 0.3)' },
                        ticks: { color: chartColors.gray }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: chartColors.white, font: { family: "'Oswald', sans-serif", size: 14 } }
                    }
                }
            }
        });

        // 3. Saws Performance (Bar)
        const sawsCtx = document.getElementById('sawsChart').getContext('2d');
        new Chart(sawsCtx, {
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
                    backgroundColor: [
                        chartColors.mint, 
                        chartColors.steel, 
                        'rgba(58, 80, 107, 0.5)'
                    ],
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
                        grid: { color: 'rgba(58, 80, 107, 0.3)' },
                        ticks: { color: chartColors.gray }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: chartColors.white, font: { size: 12 } }
                    }
                }
            }
        });
    </script>
</body>
</html>