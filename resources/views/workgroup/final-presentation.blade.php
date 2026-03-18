<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Final Review Teams Meeting - Technical Product Comparison</title>
    
    <!-- Reveal.js CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/reveal.js/4.3.1/reset.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/reveal.js/4.3.1/reveal.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/reveal.js/4.3.1/theme/black.min.css">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        @@import url('https://fonts.googleapis.com/css2?family=Oswald:wght@400;700&family=Inter:wght@300;400;600;700&display=swap');

        .reveal {
            font-family: 'Inter', sans-serif;
            color: #F8FAFC;
            background: #0B132B; /* Dark navy background */
            background-image: radial-gradient(circle at 50% 50%, #1C2541 0%, #0B132B 100%);
        }

        .reveal h1, .reveal h2, .reveal h3, .reveal h4, .reveal h5 {
            font-family: 'Oswald', sans-serif;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.05em;
            margin-bottom: 1rem;
        }

        .reveal h1 { color: #FFFFFF; font-size: 3.5rem; }
        .reveal h2 { color: #00B4D8; font-size: 2.5rem; border-bottom: 2px solid #3A506B; padding-bottom: 0.5rem; display: inline-block; }
        .reveal h3 { color: #48E5C2; font-size: 1.8rem; }
        
        .accent-text {
            background: -webkit-linear-gradient(45deg, #00B4D8, #48E5C2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .glass-panel {
            background: rgba(28, 37, 65, 0.7);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(72, 229, 194, 0.2);
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }

        .time-badge {
            display: inline-block;
            background: rgba(0, 180, 216, 0.15);
            color: #00B4D8;
            border: 1px solid #00B4D8;
            padding: 0.25rem 1rem;
            border-radius: 9999px;
            font-family: 'Oswald', sans-serif;
            font-size: 1.2rem;
            margin-bottom: 1.5rem;
            letter-spacing: 2px;
        }

        .chart-container {
            position: relative;
            height: 45vh;
            width: 100%;
            margin: 0 auto;
        }

        .metric-score {
            font-family: 'Oswald', sans-serif;
            font-size: 3.5rem;
            line-height: 1;
            color: #48E5C2;
            text-shadow: 0 0 15px rgba(72, 229, 194, 0.4);
        }

        .reveal ul {
            list-style-type: none;
            padding: 0;
            margin: 0;
        }

        .reveal li {
            position: relative;
            padding-left: 1.5rem;
            margin-bottom: 0.75rem;
            font-size: 1.2rem;
            color: #CBD5E1;
            text-align: left;
        }

        .reveal li::before {
            content: '■';
            position: absolute;
            left: 0;
            color: #00B4D8;
            font-size: 0.8rem;
            top: 0.4rem;
        }

        /* Prevent tailwind preflight from messing with reveal's core sizing */
        .reveal section img { margin: 0; background: none; border: none; box-shadow: none; }

        /* === VIEWPORT FIT OVERRIDES === */
        /* Scale content to fit within a single browser window */
        .reveal h1 { font-size: 2.5rem; }
        .reveal h2 { font-size: 1.8rem; }
        .reveal h3 { font-size: 1.3rem; }

        .reveal p, .reveal li {
            font-size: 0.95rem;
        }

        .glass-panel {
            padding: 1.25rem;
        }

        .chart-container {
            height: 32vh;
        }

        .metric-score {
            font-size: 2.5rem;
        }

        .time-badge {
            font-size: 0.9rem;
            padding: 0.2rem 0.75rem;
            margin-bottom: 0.75rem;
        }

        .reveal .slides section {
            padding-top: 10px;
            padding-bottom: 10px;
        }

        /* Tighten the T1 specialty breaching slide */
        .reveal .slides section .glass-panel p {
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .reveal .slides section .glass-panel h4 {
            font-size: 0.85rem;
        }

        /* Keep grid gaps compact */
        .reveal .slides section .grid {
            gap: 1rem;
        }
    </style>
</head>
<body>

    <div class="reveal">
        <div class="slides">

            <!-- 10:00 AM | Opening -->
            <section>
                <div class="glass-panel max-w-4xl mx-auto mt-10">
                    <div class="time-badge">10:00 AM - 10:05 AM</div>
                    <h4 class="text-gray-400 tracking-widest text-sm mb-2">FINAL REVIEW TEAMS MEETING</h4>
                    <h1 class="leading-tight mb-6">TECHNICAL PRODUCT<br><span class="accent-text">COMPARISON ANALYSIS</span></h1>
                    
                    <div class="w-24 h-1 bg-[#48E5C2] mx-auto my-6"></div>
                    
                    <div class="text-left max-w-2xl mx-auto mt-8">
                        <p class="text-xl text-[#00B4D8] font-bold mb-4 uppercase tracking-wider text-center">Meeting Objective:</p>
                        <p class="text-lg text-gray-300 italic text-center border-l-4 border-r-4 border-[#3A506B] px-6">
                            "To review the final findings, address any remaining concerns, and leave this meeting with a clear recommendation on the top equipment choices for the Mid-Mount Ladder project."
                        </p>
                    </div>
                </div>
            </section>

            <!-- 10:05 AM | Administrative Overview -->
            <section>
                <div class="time-badge">10:05 AM - 10:15 AM</div>
                <h2>ADMINISTRATIVE OVERVIEW</h2>
                
                <div class="grid grid-cols-2 gap-8 max-w-5xl mx-auto mt-8">
                    <div class="glass-panel text-left">
                        <h3 class="mb-4">Workgroup Recap</h3>
                        <p class="text-gray-300 text-lg mb-4">Five rigorous evaluation sessions focusing strictly on operational readiness, tactile capability, and engineering excellence.</p>
                        <ul>
                            <li>Forcible Entry Tools</li>
                            <li>Battery-Operated Extrication Tools</li>
                            <li>Vehicle Stabilization</li>
                        </ul>
                    </div>
                    
                    <div class="glass-panel text-left">
                        <h3 class="mb-4 text-[#00B4D8]">The Four Pillars</h3>
                        <p class="text-gray-300 text-sm mb-4">All final technical scores reflect an equal distribution across four critical performance metrics:</p>
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
                <div class="time-badge">10:15 AM - 10:30 AM</div>
                <h2>EXECUTIVE SUMMARY OF FINDINGS</h2>
                
                <div class="flex justify-center gap-12 mt-8 mb-12">
                    <div class="text-center">
                        <div class="metric-score text-white">11</div>
                        <div class="text-sm tracking-widest text-gray-400 uppercase mt-2">Evaluators</div>
                    </div>
                    <div class="text-center border-l border-r border-[#3A506B] px-12">
                        <div class="metric-score text-[#00B4D8]">20</div>
                        <div class="text-sm tracking-widest text-gray-400 uppercase mt-2">Products</div>
                    </div>
                    <div class="text-center">
                        <div class="metric-score text-[#48E5C2]">115</div>
                        <div class="text-sm tracking-widest text-gray-400 uppercase mt-2">Submissions</div>
                    </div>
                </div>

                <div class="glass-panel max-w-4xl mx-auto text-left">
                    <h3 class="mb-4">Major Evaluation Themes</h3>
                    <ul>
                        <li><span class="text-white font-bold">Reliability & Ease of Use:</span> Systems that minimize operator cognitive load performed highest.</li>
                        <li><span class="text-white font-bold">Operational Performance:</span> Immediate tactical readiness was prioritized over complex digital features.</li>
                        <li><span class="text-[#FF5A5F] font-bold">Deal-Breaker Concerns:</span> Several high-scoring products raised significant safety or operational limitations that must be addressed before procurement.</li>
                    </ul>
                </div>
            </section>

            <!-- 10:30 AM | Forcible Entry Tools (Saws) -->
            <section>
                <div class="time-badge">10:30 AM - 10:45 AM</div>
                <h2>FORCIBLE ENTRY TOOLS REVIEW</h2>
                <p class="text-gray-400 mb-6">Focus: Rotary cut-off saws for rapid breaching operations.</p>
                
                <div class="grid grid-cols-12 gap-6 max-w-6xl mx-auto items-center">
                    <div class="col-span-7 glass-panel">
                        <div class="chart-container" id="container-saws-chart">
                            <canvas id="sawsChart"></canvas>
                        </div>
                    </div>
                    
                    <div class="col-span-5 text-left space-y-4">
                        <div class="border-l-4 border-l-[#48E5C2] pl-4">
                            <h3 class="text-white text-2xl mb-1">Dewalt 12-inch (DCPS612AG2)</h3>
                            <p class="text-[#48E5C2] font-bold mb-2">Category Leader</p>
                            <p class="text-gray-300 text-sm">Achieved unanimous advancement. Superior ergonomics and gear-driven power delivery perfectly aligned with ladder company ops.</p>
                        </div>
                        
                        <div class="border-l-4 border-l-[#3A506B] pl-4 mt-6 opacity-80">
                            <h3 class="text-gray-300 text-xl mb-1">Husqvarna & Makita 14-inch</h3>
                            <p class="text-gray-400 font-bold mb-2">Secondary Competition</p>
                            <p class="text-gray-400 text-sm">Lower technical scores due to deployability issues (weight/balance) and specific safety mechanisms (AFT sensors) that hinder tactical breaching binds.</p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 10:30 AM | Forcible Entry Tools (T1) -->
            <section>
                <div class="time-badge">10:30 AM - 10:45 AM</div>
                <h2>FORCIBLE ENTRY: SPECIALTY BREACHING</h2>
                
                <div class="glass-panel max-w-4xl mx-auto border-l-4 border-[#FF5A5F] text-left">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-3xl text-white m-0">Holmatro T1</3>
                        <div class="text-right">
                            <div class="text-3xl font-bold font-mono text-[#00B4D8]">82.23</div>
                            <div class="text-xs text-gray-400 uppercase">Technical Score</div>
                        </div>
                    </div>
                    
                    <p class="text-gray-300 text-lg mb-6">
                        Evaluated as a highly deployable (88.13), all-in-one breaching solution intended to consolidate heavy halligans and traditional rabbit tools.
                    </p>
                    
                    <div class="bg-[#FF5A5F]/10 p-4 rounded-lg border border-[#FF5A5F]/30">
                        <h4 class="text-[#FF5A5F] text-sm mb-2 flex items-center gap-2">
                            <span>&#9888;</span> COMMITTEE DISCUSSION POINT
                        </h4>
                        <p class="text-gray-300 text-md">
                            While achieving a competitive technical score, the T1 generated concern due to <strong>documented deal-breaker issues</strong>. Committee must resolve whether these operational limitations supersede its rapid deployability benefits for the ladder truck mission.
                        </p>
                    </div>
                </div>
            </section>

            <!-- 10:45 AM | Extrication Tools Overview -->
            <section>
                <div class="time-badge">10:45 AM - 11:10 AM</div>
                <h2>EXTRICATION TOOLS REVIEW</h2>
                <p class="text-gray-400 mb-6">Focus: Operational fit for the ladder truck mission (Spreaders, Cutters, Rams).</p>
                
                <div class="grid grid-cols-12 gap-6 max-w-6xl mx-auto items-center">
                    <div class="col-span-5 text-left space-y-6">
                        <div class="glass-panel p-6 border-t-4 border-[#48E5C2]">
                            <h3 class="text-white text-2xl mb-2">1. Holmatro</h3>
                            <div class="text-3xl font-mono text-[#48E5C2] mb-2">90.72 <span class="text-sm text-gray-400">Avg</span></div>
                            <p class="text-gray-300 text-sm">Scored at the absolute top of the category, driven by the PSP40 Spreader and PRA40 Ram.</p>
                        </div>
                        
                        <div class="glass-panel p-6 border-t-4 border-[#00B4D8]">
                            <h3 class="text-white text-2xl mb-2">2. Hurst</h3>
                            <div class="text-3xl font-mono text-[#00B4D8] mb-2">86.53 <span class="text-sm text-gray-400">Avg</span></div>
                            <p class="text-gray-300 text-sm">Performed very strongly, particularly within the spreader group (SP 777 E3).</p>
                        </div>
                    </div>

                    <div class="col-span-7 glass-panel">
                        <div class="chart-container" id="container-extrication-chart">
                            <canvas id="extricationChart"></canvas>
                        </div>
                    </div>
                </div>
            </section>

            <!-- 10:45 AM | Extrication Alignment -->
            <section>
                <div class="time-badge">10:45 AM - 11:10 AM</div>
                <h2>EXTRICATION: ALIGNMENT & FIT</h2>
                
                <div class="glass-panel max-w-4xl mx-auto text-left">
                    <h3 class="mb-6 text-[#00B4D8]">Committee Action Required:</h3>
                    
                    <ul class="space-y-6">
                        <li>
                            <strong class="text-white block mb-1">Brand-by-Brand Final Discussion:</strong> 
                            Are we aligned on a top brand/tool preference, or do we require further granular debate between the Holmatro Pentheon and Hurst E3 platforms?
                        </li>
                        <li>
                            <strong class="text-white block mb-1">Ladder Truck Mission Fit:</strong>
                            Ensure selections factor in the spatial footprint, weight-to-power ratios, and primary extrication duties specifically assigned to mid-mount ladder companies.
                        </li>
                        <li>
                            <strong class="text-white block mb-1">Specialty Outliers:</strong>
                            Note: The Hurst M40 (40" spreader) was evaluated separately as a heavy-duty asset and is generally considered outside standard ladder company deployment parameters due to extreme dimensions.
                        </li>
                    </ul>
                </div>
            </section>

            <!-- 11:10 AM | Vehicle Stabilization -->
            <section>
                <div class="time-badge">11:10 AM - 11:25 AM</div>
                <h2>VEHICLE STABILIZATION REVIEW</h2>
                
                <div class="grid grid-cols-3 gap-6 max-w-6xl mx-auto text-left">
                    <div class="glass-panel border-b-4 border-[#48E5C2] flex flex-col">
                        <h3 class="text-white text-xl mb-2">Holmatro V-Strut</h3>
                        <div class="text-3xl font-mono text-[#48E5C2] mb-4">87.28</div>
                        <p class="text-gray-300 text-sm flex-grow"><strong>Strongest Score.</strong> Top performance driven by its 15-second rapid auto-locking deployment capability.</p>
                    </div>

                    <div class="glass-panel border-b-4 border-[#FF5A5F] flex flex-col">
                        <h3 class="text-white text-xl mb-2">Holmatro OmniShore</h3>
                        <div class="text-3xl font-mono text-[#00B4D8] mb-4">85.87</div>
                        <p class="text-gray-300 text-sm mb-4">Highly modular pneumatic system.</p>
                        <p class="text-[#FF5A5F] text-xs font-bold uppercase mt-auto border-t border-[#FF5A5F]/30 pt-2">
                            Discussion: Documented deal-breaker concern regarding complexity/setup time.
                        </p>
                    </div>

                    <div class="glass-panel border-b-4 border-[#3A506B] flex flex-col">
                        <h3 class="text-gray-300 text-xl mb-2">Paratech Strut Driver</h3>
                        <div class="text-3xl font-mono text-gray-400 mb-4">76.13</div>
                        <p class="text-gray-400 text-sm flex-grow">Remains a finalist. Committee must discuss its application fit (legacy mechanical thread vs. modern pneumatic/auto-lock systems).</p>
                    </div>
                </div>
            </section>

            <!-- 11:25 AM | Open Discussion -->
            <section>
                <div class="time-badge">11:25 AM - 11:40 AM</div>
                <h2>OPEN MEMBER DISCUSSION</h2>
                
                <div class="glass-panel max-w-4xl mx-auto">
                    <p class="text-xl text-[#00B4D8] mb-6 italic">"Address disagreements or unresolved operational issues."</p>
                    
                    <div class="grid grid-cols-2 gap-4 text-left">
                        <div class="bg-[#0B132B]/50 p-4 rounded border border-[#3A506B]">
                            <h4 class="text-white text-lg m-0">1. Safety</h4>
                        </div>
                        <div class="bg-[#0B132B]/50 p-4 rounded border border-[#3A506B]">
                            <h4 class="text-white text-lg m-0">2. Simplicity of Deployment</h4>
                        </div>
                        <div class="bg-[#0B132B]/50 p-4 rounded border border-[#3A506B]">
                            <h4 class="text-white text-lg m-0">3. Ladder Co. Compatibility</h4>
                        </div>
                        <div class="bg-[#0B132B]/50 p-4 rounded border border-[#3A506B]">
                            <h4 class="text-white text-lg m-0">4. Maintenance Implications</h4>
                        </div>
                        <div class="col-span-2 bg-[#0B132B]/50 p-4 rounded border border-[#3A506B]">
                            <h4 class="text-white text-lg m-0">5. Space and Apparatus Fit</h4>
                        </div>
                    </div>
                    
                    <p class="text-gray-400 text-sm mt-6 uppercase tracking-widest">Please keep discussion centered on these core parameters.</p>
                </div>
            </section>

            <!-- 11:40 AM | Deliberation & 12:00 PM Closing -->
            <section>
                <div class="flex justify-center gap-4 mb-4">
                    <div class="time-badge">11:40 AM Deliberation</div>
                    <div class="time-badge" style="border-color:#48E5C2; color:#48E5C2; background:rgba(72,229,194,0.1)">12:00 PM Closing</div>
                </div>
                <h2>FINAL CONSENSUS & NEXT STEPS</h2>
                
                <div class="glass-panel max-w-4xl mx-auto text-left">
                    <ul class="space-y-5">
                        <li>
                            <strong class="text-white">Confirm Final Choices:</strong> Lock in top selections by category (Saws, Extrication, Stabilization).
                        </li>
                        <li>
                            <strong class="text-[#00B4D8]">Identify Action Items:</strong> Define exactly what remains unresolved (e.g., pricing follow-ups, final demos, clarifications) and assign ownership.
                        </li>
                        <li>
                            <strong class="text-[#48E5C2]">Summarize Decisions:</strong> Ensure all members are clear on the path forward.
                        </li>
                        <li>
                            <strong class="text-white">Assign Responsibility:</strong> Designate point person for the final recommendation package and procurement submission.
                        </li>
                    </ul>
                </div>
            </section>

        </div>
    </div>

    <!-- Reveal.js Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/reveal.js/4.3.1/reveal.min.js"></script>
    
    <script>
        // Initialize Reveal.js
        Reveal.initialize({
            hash: true,
            transition: 'fade', 
            backgroundTransition: 'fade',
            controls: true,
            progress: true,
            center: true,
            disableLayout: false
        });

        // Global Chart.js Styling
        Chart.defaults.color = '#94A3B8';
        Chart.defaults.font.family = "'Inter', sans-serif";
        Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(11, 19, 43, 0.95)';
        Chart.defaults.plugins.tooltip.titleFont = { family: "'Oswald', sans-serif", size: 16 };
        Chart.defaults.plugins.tooltip.bodyFont = { family: "'Inter', sans-serif", size: 14 };
        Chart.defaults.plugins.tooltip.padding = 12;
        Chart.defaults.plugins.tooltip.borderColor = 'rgba(72, 229, 194, 0.5)';
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
                        backgroundColor: ['#48E5C2', 'rgba(58, 80, 107, 0.7)', 'rgba(58, 80, 107, 0.4)'],
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
                            grid: { color: 'rgba(255, 255, 255, 0.05)' },
                            ticks: { font: { family: "'Oswald', sans-serif", size: 14 } }
                        },
                        y: {
                            grid: { display: false },
                            ticks: { color: '#fff', font: { size: 14, weight: 'bold' } }
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
                            backgroundColor: '#48E5C2',
                            borderRadius: 4
                        },
                        {
                            label: 'Hurst',
                            data: [90.99, 82.57, 86.02],
                            backgroundColor: '#00B4D8',
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
                            labels: { color: '#fff', font: { family: "'Oswald', sans-serif", size: 14 } }
                        }
                    },
                    scales: {
                        y: {
                            min: 75, max: 95,
                            grid: { color: 'rgba(255, 255, 255, 0.05)' },
                            ticks: { font: { family: "'Oswald', sans-serif", size: 14 } }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: '#fff', font: { size: 14, weight: 'bold' } }
                        }
                    },
                    animation: { duration: 1200, easing: 'easeOutQuart' }
                }
            });
        };

        // Render charts dynamically when slides become visible
        Reveal.on('slidechanged', event => {
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