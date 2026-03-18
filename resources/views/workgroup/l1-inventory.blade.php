<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ladder 1 Inventory Modernization Dashboard</title>
    
    <!-- React & ReactDOM -->
    <script crossorigin src="https://unpkg.com/react@18/umd/react.production.min.js"></script>
    <script crossorigin src="https://unpkg.com/react-dom@18/umd/react-dom.production.min.js"></script>
    
    <!-- Babel for JSX -->
    <script src="https://unpkg.com/@babel/standalone/babel.min.js"></script>
    
    <!-- Tailwind CSS for Premium Styling -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- SheetJS for Native Excel Formula Generation -->
    <script src="https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js"></script>

    <style>
        body { background-color: #f1f5f9; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .table-container { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03); }
    </style>
</head>
<body>
    <div id="root"></div>

    @verbatim
    <script type="text/babel">
        // --- 1. Comprehensive Data Extraction ---
        // Every item from the PDF is strictly logged here.
        // Flags: 🟢=NFPA, 🔵=ISO, 🟣=Both NFPA & ISO, **=Tactical/Dept
        const inventoryData = [
            { compartment: "FRONT BUMPER", description: "Inline With Couplings", status: "Retained", flag: "🟣", qty: 1, unitCost: 450.00 },
            { compartment: "FRONT BUMPER", description: "High Rise Bag/Pack", status: "Retained", flag: "🟣", qty: 1, unitCost: 250.00 },
            { compartment: "FRONT BUMPER", description: "Coil Pack", status: "Retained", flag: "🟣", qty: 1, unitCost: 150.00 },
            { compartment: "FRONT BUMPER", description: "Hydrant Wrench", status: "Retained", flag: "🟢", qty: 1, unitCost: 75.00 },
            { compartment: "FRONT BUMPER", description: "Triple Wrench Set / Spanner Set", status: "Retained", flag: "🟢", qty: 2, unitCost: 214.75 },
            { compartment: "FRONT BUMPER", description: "Rubber Mallet", status: "Retained", flag: "🟢", qty: 1, unitCost: 35.00 },
            { compartment: "FRONT BUMPER", description: "15 ft Soft Suction", status: "Retained", flag: "🟢", qty: 1, unitCost: 350.00 },
            { compartment: "FRONT BUMPER", description: "Storz to 6 in Male Adapter", status: "Retained", flag: "🟢", qty: 1, unitCost: 275.00 },
            { compartment: "COMP 1", description: "Holmatro V-Strut", status: "NEW", flag: "🔵", qty: 4, unitCost: 1350.00 },
            { compartment: "COMP 1", description: "ResQ Jack", status: "Retained", flag: "**", qty: 2, unitCost: 1200.00 },
            { compartment: "COMP 1", description: "Hybrid Wedge", status: "Retained", flag: "**", qty: 2, unitCost: 150.00 },
            { compartment: "COMP 1", description: "Driver Gear", status: "Retained", flag: "**", qty: 1, unitCost: 2500.00 },
            { compartment: "COMP 1", description: "Fire Wedge Cribbing (Step/Chock)", status: "NEW", flag: "🔵", qty: 4, unitCost: 500.00 },
            { compartment: "COMP 2", description: "Thermal Imager Camera", status: "Retained", flag: "🟣", qty: 1, unitCost: 4500.00 },
            { compartment: "COMP 2", description: "FoxFury Nomad 360 Scene Light", status: "NEW", flag: "🔵", qty: 1, unitCost: 2500.00 },
            { compartment: "COMP 3", description: "Irons", status: "Retained", flag: "🟣", qty: 1, unitCost: 337.80 },
            { compartment: "COMP 3", description: "8 lb Sledge Hammer", status: "Retained", flag: "🟣", qty: 2, unitCost: 48.79 },
            { compartment: "COMP 3", description: "6 lb Pickhead Axe", status: "Retained", flag: "🟣", qty: 2, unitCost: 75.20 },
            { compartment: "COMP 3", description: "6 lb Flathead Axe", status: "Retained", flag: "🟣", qty: 1, unitCost: 65.99 },
            { compartment: "COMP 3", description: "4 ft Pike Pole/Hook", status: "Retained", flag: "🟣", qty: 2, unitCost: 90.30 },
            { compartment: "COMP 3", description: "Pry Bar", status: "Retained", flag: "🟢", qty: 2, unitCost: 55.00 },
            { compartment: "COMP 3", description: "Crow Bar", status: "Retained", flag: "🟢", qty: 1, unitCost: 65.00 },
            { compartment: "COMP 3", description: "36 in Bolt Cutter", status: "Retained", flag: "🟢", qty: 1, unitCost: 111.25 },
            { compartment: "COMP 3", description: "K-Tool", status: "Retained", flag: "🟢", qty: 1, unitCost: 125.00 },
            { compartment: "COMP 3", description: "Leak Bag/Plug & Dike", status: "Retained", flag: "**", qty: 2, unitCost: 175.00 },
            { compartment: "COMP 3", description: "Small Engine Bag", status: "Retained", flag: "**", qty: 1, unitCost: 120.00 },
            { compartment: "COMP 3", description: "High Rise Bag/Pack", status: "Retained", flag: "🟣", qty: 1, unitCost: 250.00 },
            { compartment: "COMP 3", description: "1 Ton Griphoist", status: "NEW", flag: "**", qty: 1, unitCost: 1500.00 },
            { compartment: "COMP 3", description: "Safety Tape", status: "Retained", flag: "🟢", qty: 2, unitCost: 15.00 },
            { compartment: "COMP 4", description: "Piercing Nozzle", status: "Retained", flag: "🟢", qty: 1, unitCost: 650.00 },
            { compartment: "COMP 4", description: "Rubber Mallet", status: "Retained", flag: "🟢", qty: 1, unitCost: 35.00 },
            { compartment: "COMP 4", description: "1 3/4 in Fog Nozzle", status: "Retained", flag: "🟢", qty: 1, unitCost: 445.99 },
            { compartment: "COMP 4", description: "3 in Fog Nozzle", status: "Retained", flag: "🟢", qty: 1, unitCost: 601.99 },
            { compartment: "COMP 4", description: "Reducer (2.5 to 1.5)", status: "Retained", flag: "🟢", qty: 1, unitCost: 224.25 },
            { compartment: "COMP 4", description: "1.5 in Smooth Bore Tip", status: "Retained", flag: "🟢", qty: 1, unitCost: 293.00 },
            { compartment: "COMP 4", description: "Inline Ball Valve", status: "Retained", flag: "🟢", qty: 1, unitCost: 350.00 },
            { compartment: "COMP 4", description: "Storz to 4 in Female Adapter", status: "Retained", flag: "🟢", qty: 1, unitCost: 285.00 },
            { compartment: "COMP 4", description: "2.5 in Gated Wye", status: "Retained", flag: "🟢", qty: 1, unitCost: 383.21 },
            { compartment: "COMP 4", description: "Storz to 4 in Male Adapter", status: "Retained", flag: "🟢", qty: 1, unitCost: 285.00 },
            { compartment: "COMP 4", description: "Adapter 5 in Storz x 5 in NH", status: "Retained", flag: "🟢", qty: 1, unitCost: 440.63 },
            { compartment: "COMP 4", description: "Double Male/Female 2.5 in Adapters", status: "Retained", flag: "🟢", qty: 4, unitCost: 45.26 },
            { compartment: "COMP 4", description: "Cellar Nozzle", status: "Retained", flag: "🟢", qty: 1, unitCost: 850.00 },
            { compartment: "COMP 4", description: "Storz to 6 in NH Female Adapter", status: "Retained", flag: "🟢", qty: 1, unitCost: 310.00 },
            { compartment: "COMP 4", description: "Hydrant Out of Service Tags", status: "Retained", flag: "**", qty: 1, unitCost: 15.00 },
            { compartment: "COMP 4", description: "3 in Drop Section", status: "Retained", flag: "🟢", qty: 1, unitCost: 189.50 },
            { compartment: "COMP 5 & 6", description: "S 789 E3 Connect Cutter", status: "NEW", flag: "🟣", qty: 1, unitCost: 12000.00 },
            { compartment: "COMP 5 & 6", description: "CR 522 E3 Connect Ram (w/ Extensions)", status: "NEW", flag: "🟣", qty: 1, unitCost: 11250.00 },
            { compartment: "COMP 5 & 6", description: "SP 777 E3 Connect Spreader", status: "NEW", flag: "🟣", qty: 1, unitCost: 13500.00 },
            { compartment: "COMP 5 & 6", description: "E3/EWXT 9Ah Batt, Saltwater", status: "NEW", flag: "**", qty: 6, unitCost: 950.00 },
            { compartment: "COMP 5 & 6", description: "EWXT/E3 Charger 110-240V", status: "NEW", flag: "**", qty: 3, unitCost: 530.00 },
            { compartment: "COMP 5 & 6", description: "Hurst Trade-in Promo Credit", status: "NEW", flag: "**", qty: 1, unitCost: -6000.00 },
            { compartment: "COMP 5 & 6", description: "Extrication Gloves", status: "Retained", flag: "**", qty: 4, unitCost: 45.00 },
            { compartment: "COMP 5 & 6", description: "Metal Plates", status: "Retained", flag: "**", qty: 2, unitCost: 85.00 },
            { compartment: "COMP 5 & 6", description: "Tarp", status: "Retained", flag: "**", qty: 1, unitCost: 45.00 },
            { compartment: "COMP 5 & 6", description: "Chains with tips set", status: "Retained", flag: "**", qty: 1, unitCost: 350.00 },
            { compartment: "COMP 5 & 6", description: "Extrication Bag", status: "Retained", flag: "**", qty: 1, unitCost: 110.00 },
            { compartment: "BACKBOARDS", description: "Backboard", status: "Retained", flag: "🟢", qty: 2, unitCost: 180.00 },
            { compartment: "BACKBOARDS", description: "Cardboard Splints (Large & Small)", status: "Retained", flag: "**", qty: 8, unitCost: 15.00 },
            { compartment: "BACKBOARDS", description: "Adjustable C-Collar", status: "Retained", flag: "**", qty: 2, unitCost: 35.00 },
            { compartment: "COMP 3 UP", description: "SawZall (20V & 28V)", status: "Retained", flag: "**", qty: 2, unitCost: 199.00 },
            { compartment: "COMP 3 UP", description: "Tool Box", status: "Retained", flag: "**", qty: 1, unitCost: 250.00 },
            { compartment: "COMP 3 UP", description: "Air Chisel", status: "Retained", flag: "**", qty: 1, unitCost: 350.00 },
            { compartment: "COMP 3 UP", description: "DeWalt DCPS612AG2 K-Saw", status: "NEW", flag: "🔵", qty: 1, unitCost: 3000.00 },
            { compartment: "COMP 3 UP", description: "DeWalt 18\" Chainsaw w/ Bullet Blade", status: "NEW", flag: "🔵", qty: 1, unitCost: 350.00 },
            { compartment: "COMP 3 UP", description: "DeWalt 1/2\" Impact Wrench w/ Socket Set", status: "NEW", flag: "**", qty: 1, unitCost: 547.96 },
            { compartment: "COMP 3 UP", description: "Flares", status: "Retained", flag: "🟢", qty: 5, unitCost: 8.00 },
            { compartment: "COMP 3 UP", description: "Duct Tape", status: "Retained", flag: "**", qty: 1, unitCost: 12.00 },
            { compartment: "COMP 2 UP", description: "Tarps", status: "Retained", flag: "🟣", qty: 5, unitCost: 45.00 },
            { compartment: "COMP 2 UP", description: "Jumbo Stretcher", status: "Retained", flag: "**", qty: 1, unitCost: 450.00 },
            { compartment: "COMP 2 UP", description: "Mega Mover", status: "Retained", flag: "**", qty: 2, unitCost: 65.00 },
            { compartment: "COMP 6 UP", description: "5 in x 50 ft LDH", status: "Retained", flag: "🟢", qty: 1, unitCost: 637.00 },
            { compartment: "COMP 6 UP", description: "Super Vac 16\" fan w/ DeWalt batteries", status: "NEW", flag: "🔵", qty: 1, unitCost: 5597.60 },
            { compartment: "COMP 6 UP", description: "2.5 in Gate Valve", status: "Retained", flag: "🟢", qty: 2, unitCost: 581.80 },
            { compartment: "COMP 6 UP", description: "Twist Lock Extension / Pig Tails", status: "Retained", flag: "**", qty: 5, unitCost: 85.00 },
            { compartment: "COMP 6 UP", description: "Regular Extension Cord", status: "Retained", flag: "**", qty: 3, unitCost: 65.00 },
            { compartment: "COMP 5 UP", description: "EMS Consumables (Sanizide, Water, Alcohol)", status: "Retained", flag: "**", qty: 5, unitCost: 15.00 },
            { compartment: "COMP 5 UP", description: "Emergency Blanket", status: "Retained", flag: "**", qty: 2, unitCost: 25.00 },
            { compartment: "COMP 5 UP", description: "Head Immobilizer / KED / Splints", status: "Retained", flag: "**", qty: 4, unitCost: 145.00 },
            { compartment: "COMP 5 UP", description: "Carry All", status: "Retained", flag: "**", qty: 1, unitCost: 85.00 },
            { compartment: "EXTING.", description: "80-B:C Dry Chemical Extinguisher", status: "Retained", flag: "🟢", qty: 1, unitCost: 245.00 },
            { compartment: "EXTING.", description: "CO2 Extinguisher", status: "Retained", flag: "🟢", qty: 1, unitCost: 305.95 },
            { compartment: "EXTING.", description: "2.5 gal Water Extinguisher", status: "Retained", flag: "🟢", qty: 1, unitCost: 178.30 },
            { compartment: "FRONT CAB", description: "CAD terminal / Electronics", status: "Retained", flag: "**", qty: 4, unitCost: 1500.00 },
            { compartment: "FRONT CAB", description: "Portable Handlight", status: "Retained", flag: "🟢", qty: 2, unitCost: 150.00 },
            { compartment: "FRONT CAB", description: "SCBA", status: "Retained", flag: "🟣", qty: 1, unitCost: 6500.00 },
            { compartment: "FRONT CAB", description: "Manuals / ERG / Log Books", status: "Retained", flag: "🟢", qty: 10, unitCost: 15.00 },
            { compartment: "FRONT CAB", description: "Draeger Gas Meter / TIC w/ Batteries", status: "Retained", flag: "🟣", qty: 3, unitCost: 1800.00 },
            { compartment: "FRONT CAB", description: "Safety Vest", status: "Retained", flag: "🟢", qty: 2, unitCost: 45.00 },
            { compartment: "FRONT CAB", description: "Helmet Shield", status: "Retained", flag: "**", qty: 4, unitCost: 65.00 },
            { compartment: "COMP 1 MED", description: "Stabilization Bag", status: "Retained", flag: "**", qty: 1, unitCost: 150.00 },
            { compartment: "COMP 1 MED", description: "Rope Rescue Gear / Harnesses (Class II/III)", status: "Retained", flag: "🟣", qty: 6, unitCost: 350.00 },
            { compartment: "COMP 1 MED", description: "Back Up Rope Bag", status: "Retained", flag: "🟣", qty: 1, unitCost: 200.00 },
            { compartment: "COMP 4 MED", description: "Med Box / Airway / MCI Bag / Suction", status: "Retained", flag: "**", qty: 6, unitCost: 450.00 },
            { compartment: "COMP 4 MED", description: "CO Meter / H2S Meter", status: "Retained", flag: "🟣", qty: 2, unitCost: 850.00 },
            { compartment: "COMP 4 MED", description: "LifePak Monitor", status: "Retained", flag: "**", qty: 1, unitCost: 25000.00 },
            { compartment: "COMP 4 MED", description: "O2 Bottle", status: "Retained", flag: "**", qty: 1, unitCost: 150.00 },
            { compartment: "REAR CAB", description: "SCBA", status: "Retained", flag: "🟣", qty: 3, unitCost: 6500.00 },
            { compartment: "REAR CAB", description: "Keys / Tools / Handtevy", status: "Retained", flag: "**", qty: 5, unitCost: 120.00 },
            { compartment: "REAR CAB", description: "Portable Handlight", status: "Retained", flag: "🟢", qty: 2, unitCost: 150.00 },
            { compartment: "REAR CAB", description: "PPE Bag / Tyvek / Dive Rescue Gear", status: "Retained", flag: "**", qty: 4, unitCost: 350.00 },
            { compartment: "REAR CAB", description: "Safety Vest", status: "Retained", flag: "🟢", qty: 2, unitCost: 45.00 },
            { compartment: "REAR 1", description: "Pike Poles (6ft, 8ft, 10ft, 12ft)", status: "Retained", flag: "🟣", qty: 6, unitCost: 110.00 },
            { compartment: "REAR 1", description: "Trash Hook / NY Roof Hook", status: "Retained", flag: "🟣", qty: 2, unitCost: 131.25 },
            { compartment: "REAR 1", description: "Attic Ladder / Roof Ladders", status: "Retained", flag: "🟣", qty: 3, unitCost: 450.00 },
            { compartment: "REAR 1", description: "Ext. Ladders (14ft, 24ft, 35ft)", status: "Retained", flag: "🟣", qty: 3, unitCost: 950.00 },
            { compartment: "TOP COMP", description: "Traffic Cones", status: "Retained", flag: "🟢", qty: 4, unitCost: 35.00 },
            { compartment: "TOP COMP", description: "Shovel / Brooms / Squeegee", status: "Retained", flag: "**", qty: 7, unitCost: 45.00 },
            { compartment: "TOP COMP", description: "5 Gallon Foam / Absorbent", status: "Retained", flag: "🟢", qty: 3, unitCost: 110.00 },
            { compartment: "HOSE", description: "1.75 in hose (Crosslay / Bundles)", status: "Retained", flag: "🟢", qty: 2, unitCost: 323.33 },
            { compartment: "HOSE", description: "1 1/2 Fog Nozzles", status: "Retained", flag: "🟢", qty: 3, unitCost: 445.99 },
            { compartment: "HOSE", description: "400 ft 5 in Hose w/ Storz", status: "Retained", flag: "🟢", qty: 1, unitCost: 3745.60 },
            { compartment: "HOSE", description: "500 ft 3 in Hose w/ Ball Valve / Adapters", status: "Retained", flag: "🟢", qty: 1, unitCost: 2350.00 },
            { compartment: "BUCKET", description: "3 in Hose", status: "Retained", flag: "🟢", qty: 1, unitCost: 189.50 },
            { compartment: "BUCKET", description: "Ladder Belts", status: "Retained", flag: "🟣", qty: 4, unitCost: 250.00 },
            { compartment: "BUCKET", description: "6 lb Flathead Axe", status: "Retained", flag: "🟣", qty: 1, unitCost: 65.99 },
            { compartment: "BUCKET", description: "Aerial Fog Nozzle/Tips", status: "Retained", flag: "🔵", qty: 4, unitCost: 601.99 },
            { compartment: "BUCKET", description: "SCBA Facemask / Regulator / Hose", status: "Retained", flag: "🟣", qty: 4, unitCost: 450.00 },
            { compartment: "BUCKET", description: "Webbing / Spanners / Combination Forks", status: "Retained", flag: "🟢", qty: 6, unitCost: 120.00 },
            { compartment: "BUCKET", description: "1 3/4 Fog Nozzle / 2 1/2 Fog Nozzle", status: "Retained", flag: "🟢", qty: 2, unitCost: 445.99 },
            { compartment: "BUCKET", description: "1 3/4 Hose (50ft sections)", status: "Retained", flag: "🟢", qty: 2, unitCost: 323.33 },
            { compartment: "LADDER", description: "16 ft Roof Ladder", status: "Retained", flag: "🟣", qty: 1, unitCost: 450.00 },
            { compartment: "LADDER", description: "6 lb Pickhead Axe", status: "Retained", flag: "🟣", qty: 1, unitCost: 75.20 },
            { compartment: "LADDER", description: "Stokes w/ Harnesses", status: "Retained", flag: "🟣", qty: 1, unitCost: 850.00 },
            { compartment: "LADDER", description: "10 ft Pike Pole", status: "Retained", flag: "🟣", qty: 1, unitCost: 110.00 },
            { compartment: "TAILBOARD", description: "Hydrant Wrench / Spanner Wrench", status: "Retained", flag: "🟢", qty: 3, unitCost: 110.00 },
            { compartment: "TAILBOARD", description: "Rear Supply Appliance", status: "Retained", flag: "🟢", qty: 1, unitCost: 1200.00 }
        ];

        // --- 2. SVGs for Premium UI ---
        const IconDownload = () => (
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        );
        const IconBox = () => (
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        );
        const IconDollar = () => (
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        );

        // --- 3. React Application ---
        const App = () => {
            const formatCurrency = (val) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(val);

            // Calculate live totals for the Dashboard KPIs
            const totalValuation = inventoryData.reduce((sum, item) => sum + (item.qty * item.unitCost), 0);
            const totalItems = inventoryData.reduce((sum, item) => sum + item.qty, 0);
            const newUpgrades = inventoryData.filter(i => i.status === "NEW").length;

            // --- THE EXCEL EXPORT ENGINE ---
            const handleExportToExcel = () => {
                // Initialize a new workbook
                const wb = XLSX.utils.book_new();
                
                // Build the data array starting with headers
                const wsData = [
                    ["Apparatus Inventory Modernization: Ladder 1 (L1)"], // A1
                    ["Generated dynamically with native pricing formulas"], // A2
                    [], // A3 blank for spacing
                    ["Compartment", "Item Description", "Status & Flags", "Qty", "Unit Cost", "Extended Cost"] // Row 4 (Headers)
                ];

                // Append all rows and embed formulas
                inventoryData.forEach((item, index) => {
                    const rowNum = index + 5; // Data starts at Excel Row 5
                    wsData.push([
                        item.compartment,
                        item.description,
                        item.status,
                        item.flag,
                        item.qty,
                        item.unitCost,
                        // Embedded Excel Formula: =D(row)*E(row)
                        { t: 'n', f: `D${rowNum}*E${rowNum}`, z: '"$"#,##0.00' }
                    ]);
                });

                // Add a Grand Total row at the bottom
                const finalRow = wsData.length + 1;
                wsData.push([
                    "", "", "", "", "TOTAL VALUATION:", 
                    // Formula to sum all the extended costs
                    { t: 'n', f: `SUM(F5:F${finalRow - 1})`, z: '"$"#,##0.00' }
                ]);

                // Convert array of arrays to a SheetJS worksheet
                const ws = XLSX.utils.aoa_to_sheet(wsData);

                // Apply Premium Column Widths for readability in Excel
                ws['!cols'] = [
                    {wch: 18}, // A: Compartment
                    {wch: 45}, // B: Description
                    {wch: 20}, // C: Status & Flags
                    {wch: 10},  // D: Qty
                    {wch: 20}, // E: Unit Cost
                    {wch: 30}  // F: Extended Cost
                ];

                // Format the "Unit Cost" column (Col E, index 4) as Currency
                for(let R = 4; R < finalRow - 1; ++R) {
                    const cellRef = XLSX.utils.encode_cell({c:4, r:R});
                    if(ws[cellRef] && ws[cellRef].t === 'n') {
                        ws[cellRef].z = '"$"#,##0.00';
                    }
                }

                // Add sheet to workbook and trigger download
                XLSX.utils.book_append_sheet(wb, ws, "L1_Inventory_Rebuild");
                XLSX.writeFile(wb, "Ladder_1_Modernization_Pricing.xlsx");
            };

            return (
                <div className="min-h-screen bg-slate-50 text-slate-900 pb-12">
                    {/* Top Navigation */}
                    <div className="bg-slate-900 text-white pt-6 pb-24 px-6 md:px-12 border-b border-slate-800">
                        <div className="max-w-7xl mx-auto flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                            <div>
                                <h1 className="text-2xl md:text-3xl font-bold tracking-tight">L1 Apparatus Modernization</h1>
                                <p className="text-slate-400 text-sm mt-1">Professional Loadout Rebuild & Financial Dashboard</p>
                            </div>
                            <button 
                                onClick={handleExportToExcel}
                                className="flex items-center gap-2 bg-blue-600 hover:bg-blue-500 text-white px-5 py-2.5 rounded-lg font-medium transition-all shadow-lg shadow-blue-900/20 active:scale-95"
                            >
                                <IconDownload />
                                Export to Excel (.xlsx)
                            </button>
                        </div>
                    </div>

                    <div className="max-w-7xl mx-auto px-6 md:px-12 -mt-14">
                        {/* KPI Widgets */}
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                            <div className="bg-white p-6 rounded-xl table-container border border-slate-100 flex items-center gap-4">
                                <div className="bg-emerald-100 p-3 rounded-lg text-emerald-700">
                                    <IconDollar />
                                </div>
                                <div>
                                    <p className="text-sm font-semibold text-slate-500 uppercase tracking-wider">Total Equipment Value</p>
                                    <p className="text-2xl font-bold text-slate-800 mt-1">{formatCurrency(totalValuation)}</p>
                                </div>
                            </div>
                            <div className="bg-white p-6 rounded-xl table-container border border-slate-100 flex items-center gap-4">
                                <div className="bg-blue-100 p-3 rounded-lg text-blue-700">
                                    <IconBox />
                                </div>
                                <div>
                                    <p className="text-sm font-semibold text-slate-500 uppercase tracking-wider">Total Physical Items</p>
                                    <p className="text-2xl font-bold text-slate-800 mt-1">{totalItems}</p>
                                </div>
                            </div>
                            <div className="bg-white p-6 rounded-xl table-container border border-slate-100 flex items-center gap-4">
                                <div className="bg-purple-100 p-3 rounded-lg text-purple-700">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 2v20"/><path d="m17 5-5-3-5 3"/><path d="m17 19-5 3-5-3"/></svg>
                                </div>
                                <div>
                                    <p className="text-sm font-semibold text-slate-500 uppercase tracking-wider">New Upgrades / Tech</p>
                                    <p className="text-2xl font-bold text-slate-800 mt-1">{newUpgrades} Modules</p>
                                </div>
                            </div>
                        </div>

                        {/* Color & Status Legend */}
                        <div className="bg-white rounded-xl table-container border border-slate-200 overflow-hidden mb-6">
                            <div className="px-6 py-3 border-b border-slate-100 bg-white">
                                <h3 className="text-sm font-bold text-slate-700 uppercase tracking-wider">Color & Status Legend</h3>
                            </div>
                            <div className="px-6 py-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <p className="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Row Shading</p>
                                    <div className="flex flex-wrap gap-3">
                                        <span className="inline-flex items-center gap-1.5 text-xs">
                                            <span className="w-4 h-4 rounded" style={{backgroundColor: '#ecfdf5', border: '1px solid #a7f3d0'}}></span>
                                            <span className="text-slate-600 font-medium">Green Row = NEW Item</span>
                                        </span>
                                        <span className="inline-flex items-center gap-1.5 text-xs">
                                            <span className="w-4 h-4 rounded bg-white" style={{border: '1px solid #e2e8f0'}}></span>
                                            <span className="text-slate-600 font-medium">White Row = Retained Item</span>
                                        </span>
                                    </div>
                                </div>
                                <div>
                                    <p className="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2">Standards Compliance Flags</p>
                                    <div className="flex flex-wrap gap-3">
                                        <span className="text-xs text-slate-600">🟢 NFPA Only</span>
                                        <span className="text-xs text-slate-600">🔵 ISO Only</span>
                                        <span className="text-xs text-slate-600">🟣 Both NFPA & ISO</span>
                                        <span className="text-xs text-slate-600">** Tactical / Dept</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Interactive Data Table */}
                        <div className="bg-white rounded-xl table-container border border-slate-200 overflow-hidden">
                            <div className="px-6 py-5 border-b border-slate-100 flex justify-between items-center bg-white">
                                <h2 className="text-lg font-bold text-slate-800">Primary Inventory Roster</h2>
                                <span className="text-xs font-semibold px-3 py-1 bg-slate-100 text-slate-500 rounded-full">Formula Ready</span>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-left border-collapse">
                                    <thead>
                                        <tr className="bg-slate-50/80 text-xs uppercase tracking-widest text-slate-500 border-b border-slate-200">
                                            <th className="px-6 py-4 font-semibold">Compartment</th>
                                            <th className="px-6 py-4 font-semibold">Item Description</th>
                                            <th className="px-6 py-4 font-semibold text-center">Status & Flags</th>
                                            <th className="px-6 py-4 font-semibold text-right">Qty</th>
                                            <th className="px-6 py-4 font-semibold text-right">Unit Cost</th>
                                            <th className="px-6 py-4 font-semibold text-right bg-slate-100/50">Extended Cost</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 text-sm">
                                        {inventoryData.map((item, idx) => {
                                            const isNew = item.status === 'NEW';
                                            return (
                                            <tr key={idx} className={`transition-colors group ${isNew ? 'bg-emerald-50/60 hover:bg-emerald-50' : 'hover:bg-slate-50'}`}>
                                                <td className="px-6 py-3 whitespace-nowrap text-slate-600 font-medium">
                                                    {item.compartment}
                                                </td>
                                                <td className="px-6 py-3 text-slate-900 font-medium">
                                                    {item.description}
                                                </td>
                                                <td className="px-6 py-3 text-center whitespace-nowrap">
                                                    <span className={`px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider ${
                                                        isNew 
                                                        ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' 
                                                        : 'bg-slate-100 text-slate-500 border border-slate-200'
                                                    }`}>
                                                        {item.status}
                                                    </span>
                                                    <span className="ml-1.5 text-xs">{item.flag}</span>
                                                </td>
                                                <td className="px-6 py-3 text-right text-blue-700 font-medium">
                                                    {item.qty}
                                                </td>
                                                <td className="px-6 py-3 text-right text-blue-700">
                                                    {formatCurrency(item.unitCost)}
                                                </td>
                                                <td className={`px-6 py-3 text-right text-slate-900 font-bold transition-colors ${isNew ? 'bg-emerald-50/50 group-hover:bg-emerald-100/50' : 'bg-slate-50/50 group-hover:bg-blue-50/50'}`}>
                                                    {formatCurrency(item.qty * item.unitCost)}
                                                </td>
                                            </tr>
                                            );
                                        })}
                                    </tbody>
                                    <tfoot>
                                        <tr className="bg-slate-900 text-white font-bold text-sm">
                                            <td colSpan="5" className="px-6 py-4 text-right uppercase tracking-wider">
                                                Final Equipment Valuation:
                                            </td>
                                            <td className="px-6 py-4 text-right text-base text-emerald-400">
                                                {formatCurrency(totalValuation)}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            );
        };

        const root = ReactDOM.createRoot(document.getElementById('root'));
        root.render(<App />);
    </script>
    @endverbatim
</body>
</html>