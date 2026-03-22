/**
 * Ingestion script for MBFD manuals into Cloudflare Vectorize.
 * Uses Cloudflare AI API for embeddings and Vectorize REST API for upsert.
 * 
 * Usage: node ingest-manuals.mjs
 * Requires: CLOUDFLARE_API_TOKEN and CLOUDFLARE_ACCOUNT_ID env vars
 */

const API_TOKEN = process.env.CLOUDFLARE_API_TOKEN;
const ACCOUNT_ID = '265122b6d6f29457b0ca950c55f3ac6e'; // Will detect
const INDEX_NAME = 'mbfd-rag-index';
const EMBEDDING_MODEL = '@cf/baai/bge-large-en-v1.5';

if (!API_TOKEN) {
  console.error('CLOUDFLARE_API_TOKEN env var required');
  process.exit(1);
}

// --- Text content from PDFs (extracted) ---

const EXTRA_INFO_TEXT = `Equipment Deficiency Reporting Procedures for Miami Beach Fire Department

To ensure timely and effective repairs, all equipment deficiencies must be reported directly to Fire Fleet. Accurate reporting helps assess operational impact and maintain continuity of service.

Purpose of Streamlining: This process is designed to simplify and centralize communication. Reporting issues to multiple contacts has previously led to missed repair requests and repeat service delays. A single reporting path improves efficiency and ensures units are repaired correctly the first time.

Reporting Instructions:
1. Submit a Detailed Report via Email. The operator or individual who discovered the issue must provide a clear, detailed list of concerns. Include all relevant information to assist with accurate diagnosis and repair. Preferred reporters: Captain, Captain 5, or Chief 300.
2. Email Format: Send all reports to FireSupportServices@MiamiBeachFL.Gov. Subject line format: [Unit number] Repairs Requested (e.g., "E1 20503 Repair Request")
3. Supplemental Contact: Phone or in-person discussions are allowed, but the deficiency list must still be emailed. This ensures proper tracking and minimizes the risk of oversight.

Phone Calls & Drive-Ups - Follow this order of contact:
1. Fire Fleet Operations Manager - (786-559-4054)
2. Captain of Support Services - (305-794-4057)
3. Chief of Support Services - (786-562-5418)
4. Technician in Shop - Last Resort Only - (786-231-7362)
Contacting technicians directly should be avoided unless absolutely necessary. This prevents workflow interruptions and supports timely, accurate repairs.

After Hours & Weekends Protocol:
- Technicians must not be contacted after hours.
- Only Chief 300 or the ranking officer on the unit may initiate support service calls.
- Use the same contact order listed above for phone calls and drive-ups.
- Chief 300 will determine whether to wait for service or initiate a unit change-out based on operational needs.

Following this directive is essential to ensure all requests are completed accurately and to prevent any oversight.`;

// L1_L11 manual was image-based, so we create a description entry
const L1_L11_DESCRIPTION = `L1 through L11 Apparatus Manual - Miami Beach Fire Department

This document covers the operational manuals for Ladder units L1 through L11 in the Miami Beach Fire Department fleet. These are Pierce fire apparatus operated by MBFD.

For specific technical information about L1-L11 apparatus operations, specifications, pump procedures, aerial operations, maintenance schedules, and safety procedures, refer to the L1_L11_manual.pdf document.

Key topics covered in the L1-L11 manual include:
- Vehicle identification and specifications
- Safety procedures and warning labels
- Pre-trip inspections and daily checks
- General vehicle operation (cab entry/exit, seat belts, HVAC, instrument panel)
- Engine operation (starting, running, stopping, emergency shutdown)
- Driving procedures (transmission, axles, brakes, steering)
- Pump operations
- Maintenance schedules and lubrication intervals
- Electrical systems
- Cooling, fuel, and exhaust systems
- Towing instructions
- Wheels and tires maintenance

Note: This is a Pierce Manufacturing apparatus. For technical support contact Pierce Customer Service at 888-Y-PIERCE (888-974-3723).`;

// We'll chunk the L3 and PUC manuals from the extracted text
import { readFileSync } from 'fs';
import { join } from 'path';

// Helper: chunk text into segments of ~1500 chars with overlap
function chunkText(text, maxChars = 1500, overlap = 200) {
  const chunks = [];
  let start = 0;
  while (start < text.length) {
    let end = start + maxChars;
    // Try to break at a sentence or paragraph boundary
    if (end < text.length) {
      const lastPeriod = text.lastIndexOf('.', end);
      const lastNewline = text.lastIndexOf('\n', end);
      const breakPoint = Math.max(lastPeriod, lastNewline);
      if (breakPoint > start + maxChars * 0.5) {
        end = breakPoint + 1;
      }
    }
    chunks.push(text.slice(start, Math.min(end, text.length)).trim());
    start = end - overlap;
    if (start >= text.length) break;
  }
  return chunks.filter(c => c.length > 50);
}

// Generate embeddings via Cloudflare AI API
async function generateEmbeddings(texts) {
  // Use the Workers AI REST API
  const url = `https://api.cloudflare.com/client/v4/accounts/${ACCOUNT_ID}/ai/run/@cf/baai/bge-large-en-v1.5`;
  const resp = await fetch(url, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${API_TOKEN}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ text: texts }),
  });
  if (!resp.ok) {
    const errText = await resp.text();
    throw new Error(`Embedding API error ${resp.status}: ${errText}`);
  }
  const data = await resp.json();
  if (!data.success) {
    throw new Error(`Embedding API failed: ${JSON.stringify(data.errors)}`);
  }
  return data.result.data;
}

// Upsert vectors to Vectorize
async function upsertVectors(vectors) {
  // Vectorize REST API uses NDJSON format
  const ndjson = vectors.map(v => JSON.stringify({
    id: v.id,
    values: v.values,
    metadata: v.metadata,
  })).join('\n');

  const url = `https://api.cloudflare.com/client/v4/accounts/${ACCOUNT_ID}/vectorize/v2/indexes/${INDEX_NAME}/upsert`;
  const resp = await fetch(url, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${API_TOKEN}`,
      'Content-Type': 'application/x-ndjson',
    },
    body: ndjson,
  });
  if (!resp.ok) {
    const errText = await resp.text();
    throw new Error(`Vectorize upsert error ${resp.status}: ${errText}`);
  }
  return await resp.json();
}

// First, detect account ID
async function getAccountId() {
  const resp = await fetch('https://api.cloudflare.com/client/v4/accounts', {
    headers: { 'Authorization': `Bearer ${API_TOKEN}` },
  });
  const data = await resp.json();
  if (data.result && data.result.length > 0) {
    return data.result[0].id;
  }
  throw new Error('Could not detect account ID');
}

async function main() {
  // Detect account ID
  let accountId = ACCOUNT_ID;
  try {
    accountId = await getAccountId();
    console.log(`Using account ID: ${accountId}`);
  } catch (e) {
    console.log(`Using default account ID: ${accountId}`);
  }
  // Override the module-level const
  globalThis._accountId = accountId;

  // Prepare documents
  const documents = [];

  // 1. Extra info (repair reporting)
  documents.push({
    source: 'extra_info_for_AI.pdf',
    text: EXTRA_INFO_TEXT,
  });

  // 2. L1-L11 description (image-based PDF)
  documents.push({
    source: 'L1_L11_manual.pdf',
    text: L1_L11_DESCRIPTION,
  });

  // 3. L3 manual - read from the extracted text we have
  // We'll use a representative subset of the most important sections
  const l3Sections = [
    `Pierce Arrow XT Operator's Manual - L3 Apparatus (Miami Beach Fire Department)

This is the operator's manual for the Pierce Arrow XT fire apparatus, designated as L3 in the Miami Beach Fire Department fleet. Published by Pierce Manufacturing Inc., Part No. PM-C-OM014-AXT-1115.

Chapter 1 - Foreword: Only trained personnel should operate this vehicle. The manual reviews basic principles of operation, highlights common safety concerns, and gives recommendations.

Chapter 2 - Safety: Includes warnings about backing the vehicle, vehicle handling characteristics, electrocution hazards from overhead power lines, high pressure hydraulic fluid safety, tanker truck characteristics, and lockout/tagout procedures.

Chapter 3 - Before Placing Vehicle in Service: Covers axle weights and capacity, tire pressure, brake balance, brake burnishing procedures (10/30/30 and 5 method), customer-installed equipment guidelines.`,

    `Pierce Arrow XT L3 - Chapter 4 General Operations

Vehicle Access: Automatic deploying side entry steps, service areas, high locations access. Always maintain three points of contact when climbing.

Safety Restraints: Seat belt monitoring system with green (occupied/belted) and red (occupied/unbelted) indicators. Side Roll Protection System activates during vehicle side roll accidents. ALWAYS WEAR YOUR SEAT BELT.

HVAC: Defroster (recirculation-only system), heater units under rear-facing seats, external air conditioning system.

Instrument Panel: Engine oil pressure, voltmeter, engine coolant temperature, tachometer, speedometer, transmission oil temperature, front/rear air pressure, fuel gauge, DEF gauge, and multiplex indicator lights.

Command Zone II and III: Control and monitoring system working with SAE J1939 data bus. Features 7" touchscreen display, WiFi connectivity, datalogging, diagnostics, prognostics, GPS, and sub-system integration.`,

    `Pierce Arrow XT L3 - Chapter 5 Operations

Pre-Trip Inspection: Daily inspection required for safety and legal compliance. Interior inspection includes engine compartment (oil, transmission fluid, coolant, power steering fluid), cab controls, safety equipment. Exterior inspection covers tires, wheels, brakes, steering, suspension, exhaust, and all lights.

Engine Operations: Starting procedure - verify parking brake, transmission in neutral, battery master ON, ignition ON, wait for prove out, push starter button. Never crank more than 15 seconds. Running the engine - avoid excessive idling over 5 minutes. Cold weather operation - avoid extended idling, use minimum 1250 RPM idle.

Driving: Allison automatic transmission starts in Neutral only. ABS prevents wheel lock-up. Traction control available. Stability control (RSC/ESC) helps prevent rollover.

Engine Exhaust After Treatment: DPF regeneration (passive, active, parked). DEF fluid requirements. Parked regeneration procedure: stop vehicle, set parking brake, keep foot off throttle, press regen switch for 5 seconds, may take 20-40 minutes.`,

    `Pierce Arrow XT L3 - Chapter 6 Maintenance

Cab Tilt System: Cold weather fluid types - TES-389 approved for 0-120°F, MIL-H-5606 for below 0°F.

Brake Maintenance: Inspect entire brake system for air leaks, condition of drums/rotors/chambers/slack adjusters, hoses/fittings condition. Non-asbestos lining material used.

Electrical: Command Zone multiplex system uses single three-wire data link. Troubleshooting tips for multiplex system.

Engine Maintenance: Air filter replacement when indicated by restriction lamp. Fan and accessory belt inspection weekly. Engine service per manufacturer schedule.

Cooling System: Check coolant level daily. Clean radiator and charge air cooler. Drain, flush, refill annually. Antifreeze types: Standard (green) or Extended (yellow).

Towing: TAK-4 front suspension - tire lift method preferred. For TAK-4 T3 rear steering, disconnect steering dwell mechanism before towing with front tire lift.

Wheels and Tires: Fire service rated tires - after 50 miles above 50 mph, stop for 20 minutes. Check tire pressure daily. Hub piloted wheel nut torque: M22x1.5 at 450-500 ft-lbs.`,

    `Pierce Arrow XT L3 - Chapter 7 Lubrication and Maintenance Intervals

Initial Inspection: Spring U-Bolts - check torque after 500 miles.
Daily: Air cleaner, air intake tubes, air tanks drain, coolant, engine oil, exhaust, throttle pedal, tires, transmission fluid.
Weekly: Aerial boom support, air dryer, axle front, belts, brake linings, brakes, cab tilt fluid, driveshafts, electrical connectors/harnesses, frame fasteners, fuel system, fuel-water separator, mirrors, seat belts, springs, steering hydraulic system, steering system, suspension, tires, wiper blades.
Monthly: Axle rear oil, battery posts, cab tilt pivot pins, driveshaft slip joints/U-joints, radiator cores, spring pins, steering links, wheel bearings, wheel nuts.
Semi-Annual: A/C, automatic slack adjusters, disc brake calipers, door hinges/latches/strikers, fifth wheel, seat adjuster, steering gear, tire alignment, transmission fluid.
Annual: Air cleaner replace, axle kingpin/tie rod lubrication, spring U-bolts, steering hydraulic fluid/filter replace.`,
  ];

  for (const section of l3Sections) {
    documents.push({ source: 'L3_manual.pdf', text: section });
  }

  // 4. PUC Engine manual sections
  const pucSections = [
    `Pierce Ultimate Configuration (PUC) Pumpers Operation & Maintenance Manual - Miami Beach Fire Department

The PUC pump is a high speed, single stage, UL rated, centrifugal Fire Fighting Pump. Features: compactness, lightweight, high efficiency, and wide range of pumping capabilities. Midship mounted rearward of the chassis engine, powered via Rear Engine Power Take-Off (REPTO).

Safety Information: Open and close valves slowly. Be prepared for high nozzle reactions. Do not exceed system rated pressure, capacity or speed. Use only hoses with pressure ratings higher than intended use. Remove all pressure from hoses before disconnecting. Shutdown and drain completely before maintenance.

Power Take-Off (PTO) Safety: Never operate controls from position that could result in getting caught in moving machinery. During extreme cold weather (32°F and lower), disengaged Powershift PTO can momentarily transmit high torque causing unexpected output shaft rotation. Rotating auxiliary driveshafts can snag clothes, skin, hair - can cause serious injury or death.`,

    `PUC Pump Operations - Engaging and Operating

Stationary Operation: 1) Pull parking brake (auto neutral engages). 2) Observe OK to Stationary Pump lamp. 3) Chock wheels. 4) Engage pump at engine idle via WATER PUMP switch. 5) Observe Pump Engaged lamp.

Never run pump dry except momentarily and at low speeds. Begin pumping water immediately after engaging. Circulate water if hoses not ready. Running pump dry for more than a few minutes will cause damage.

Pump and Roll - Basic Package: Stop vehicle, pull parking brake, engage pump at idle, set up pump panel, shift to 1st gear, release parking brake. Drive slowly - foot throttle controls both ground speed and pump pressure.

Pump and Roll - Advanced Package: Press PUMP AND ROLL switch while approaching scene. Tank to Pump and Recirculation valves open automatically. Engine speed limited to ~1200 RPM max.

Supply Water: Open Tank-to-Pump valve to flood pump intake. Open Primer Valve if pump is dry. ALWAYS keep Tank Fill Valve cracked open to circulate water - pump can heat trapped water to boiling in seconds.`,

    `PUC Pump Operations - Pressure Control and Discharge

Pressure Governor: In PRESSURE CONTROL mode, system monitors and maintains set pressure automatically. In THROTTLE (RPM) CONTROL mode, operator controls engine speed manually. ALWAYS pump in pressure control mode - pumping in throttle mode can cause dangerous pressure spikes.

Discharging Water: Close discharge valve, open drain/bleeder valve, remove cap (ALWAYS open bleeder BEFORE removing cap - trapped pressure can blow cap off with explosive force). Connect hose, close bleeder, slowly open discharge valve.

Monitoring Intake Pressure: Maintain 20 PSI intake pressure from pressurized supply. Maintain 20 in. Hg vacuum or less from draft. Avoid cavitation - indicated by fluctuating discharge pressure, gravel-churning sound, or pressure not responding to engine speed changes.

Changing Water Supply: With gated intake valve - maintain flow while switching. Without gate valve - must stop flow, withdraw fire fighters from attack area. Pierce recommends every pumper have an Intake Gate Valve.`,

    `PUC Pump Maintenance

Draining: Every part of pump system must be drained if exposed to freezing temperatures. Open all drain valves, discharge/intake valves, remove caps. Purge primer regularly in cold weather.

Flushing Drain Valves: Monthly or after pumping dirty/salty water. Forward flush with clean water tank, open each drain valve for 20 seconds steady flow. Back flush after salt water with municipal water through all inlets/outlets.

Annual Testing: Per NFPA 1911 for inspection, maintenance, testing of in-service apparatus.

Pump Transmission Lubrication: Check oil every 25 hours of pumping or every 3 months. Change oil and filter every 100 hours or 6 months. Use PUC XPL Extreme Performance Lubricant (Pierce PN 1915175). Approximate capacity 4 quarts plus 2 quarts for filter/cooler.

Mechanical Shaft Seal: Relies on water to cool and lubricate sealing surfaces. Extended dry operation causes overheating and damage. Minimize operation at pressures higher than pump rating. Seal failure may result from bearing failure, impeller blockage, dry running, or operating beyond design rating.`,
  ];

  for (const section of pucSections) {
    documents.push({ source: 'PUC_Engine_manual.pdf', text: section });
  }

  console.log(`Prepared ${documents.length} document sections for ingestion`);

  // Chunk all documents
  const allChunks = [];
  for (const doc of documents) {
    const chunks = chunkText(doc.text, 1500, 200);
    for (let i = 0; i < chunks.length; i++) {
      allChunks.push({
        id: `${doc.source.replace(/[^a-zA-Z0-9]/g, '_')}-chunk-${i}`,
        text: chunks[i],
        source: doc.source,
        chunkIndex: i,
      });
    }
  }

  console.log(`Total chunks to embed: ${allChunks.length}`);

  // Process in batches of 10 (API limit for embeddings)
  const BATCH_SIZE = 10;
  const allVectors = [];

  for (let i = 0; i < allChunks.length; i += BATCH_SIZE) {
    const batch = allChunks.slice(i, i + BATCH_SIZE);
    const texts = batch.map(c => c.text.slice(0, 2000)); // truncate for embedding

    console.log(`Generating embeddings for batch ${Math.floor(i / BATCH_SIZE) + 1}/${Math.ceil(allChunks.length / BATCH_SIZE)}...`);

    const embeddings = await generateEmbeddings(texts);

    for (let j = 0; j < batch.length; j++) {
      allVectors.push({
        id: batch[j].id,
        values: embeddings[j],
        metadata: {
          text: batch[j].text.slice(0, 2000),
          source: batch[j].source,
          chunk_index: batch[j].chunkIndex,
        },
      });
    }

    // Small delay to avoid rate limiting
    await new Promise(r => setTimeout(r, 500));
  }

  console.log(`Generated ${allVectors.length} vectors. Upserting to Vectorize...`);

  // Upsert in batches of 50
  const UPSERT_BATCH = 50;
  for (let i = 0; i < allVectors.length; i += UPSERT_BATCH) {
    const batch = allVectors.slice(i, i + UPSERT_BATCH);
    console.log(`Upserting batch ${Math.floor(i / UPSERT_BATCH) + 1}...`);

    // Use detected account ID
    const ndjson = batch.map(v => JSON.stringify({
      id: v.id,
      values: v.values,
      metadata: v.metadata,
    })).join('\n');

    const url = `https://api.cloudflare.com/client/v4/accounts/${globalThis._accountId || ACCOUNT_ID}/vectorize/v2/indexes/${INDEX_NAME}/upsert`;
    const resp = await fetch(url, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${API_TOKEN}`,
        'Content-Type': 'application/x-ndjson',
      },
      body: ndjson,
    });
    if (!resp.ok) {
      const errText = await resp.text();
      console.error(`Upsert error: ${errText}`);
    } else {
      const result = await resp.json();
      console.log(`  Upserted: ${JSON.stringify(result.result || result)}`);
    }
  }

  console.log('Ingestion complete!');
}

main().catch(console.error);
