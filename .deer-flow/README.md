# DeerFlow 2.0 - MBFD Workgroup Orchestration

## Overview

This orchestration system coordinates multi-agent processing for the MBFD Mid-Mount Ladder Truck Equipment Evaluation Workgroup. It integrates data ingestion, AI-powered analysis, and report generation for the Health & Safety Committee.

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    DeerFlow 2.0 Orchestrator                     │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐      │
│  │ Data         │    │ AI Worker    │    │ Report       │      │
│  │ Ingestion    │───▶│ Client       │───▶│ Generator    │      │
│  └──────────────┘    └──────────────┘    └──────────────┘      │
│         │                    │                    │            │
│         ▼                    ▼                    ▼            │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐      │
│  │ CSV/PDF      │    │ Cloudflare   │    │ Markdown/    │      │
│  │ Files        │    │ Worker API   │    │ JSON Output  │      │
│  └──────────────┘    └──────────────┘    └──────────────┘      │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

## Components

### 1. Data Ingestion (`DataIngestion`)

Loads and parses workgroup data files:
- **Personnel CSV**: `scripts/mbfd-personnel.csv` - Evaluator roster
- **Apparatus CSV**: `apparatus_data.csv` - Fleet inventory
- **SOGs PDFs**: `sogs/*.pdf` - Standard Operating Guidelines

### 2. AI Worker Client (`AIWorkerClient`)

Interfaces with Cloudflare Worker endpoints:
- `/vectorize` - Ingest product specs into vector index
- `/analyze` - Generate AI analysis for individual products
- `/summary` - Generate category-level summaries
- `/executive-report` - Generate full executive report

### 3. Orchestrator (`WorkgroupOrchestrator`)

Main coordination class that:
- Initializes data loading
- Checks AI worker health
- Runs fleet and personnel analysis
- Generates reports

## SAVER Evaluation Rubric

The system uses the SAVER framework for equipment evaluation:

| Category | Weight | Description |
|----------|--------|-------------|
| **Capability** | 25% | Functional performance and features |
| **Usability** | 20% | Ease of use, ergonomics, training |
| **Affordability** | 20% | Cost-effectiveness, value |
| **Maintainability** | 20% | Servicing, parts, durability |
| **Deployability** | 15% | Operational readiness, reliability |

**Rating Scale**: 1 (Poor) → 5 (Excellent)

## Usage

### Command Line

```bash
# Run full analysis
python orchestrate_workgroup.py --action all

# Run analysis only
python orchestrate_workgroup.py --action analyze

# Generate report only
python orchestrate_workgroup.py --action report

# Custom session name
python orchestrate_workgroup.py --session "Q1 2026 Evaluation" --action all

# Custom output directory
python orchestrate_workgroup.py --output /path/to/output --action all
```

### Python API

```python
from orchestrate_workgroup import WorkgroupOrchestrator

# Initialize
orchestrator = WorkgroupOrchestrator()

# Run analysis
result = orchestrator.run_analysis(
    session_name="Ladder Truck Procurement Evaluation"
)

# Access results
print(f"Report: {result['report_path']}")
print(f"Data: {result['data_path']}")
```

## Output Files

Generated files are saved to `.deer-flow/outputs/`:

| File | Description |
|------|-------------|
| `fleet_status_report_YYYYMMDD_HHMMSS.md` | Fleet inventory analysis |
| `workgroup_data_YYYYMMDD_HHMMSS.json` | Raw data export |
| `category_summary_*.md` | Per-category AI summaries |
| `executive_report_*.md` | Final committee report |

## AI Worker Endpoints

The Cloudflare Worker (`workgroup-ai`) provides:

### POST /vectorize
Ingests product specifications into vector index for semantic search.

```json
{
  "text": "Product specification text...",
  "productName": "Holmatro Cutter",
  "manufacturer": "Holmatro",
  "category": "battery_hydraulics",
  "filename": "holmatro_specs.pdf",
  "chunkIndex": 0,
  "fileId": "abc123"
}
```

### POST /analyze
Generates AI analysis for a single product evaluation.

```json
{
  "productName": "Holmatro Cutter",
  "manufacturer": "Holmatro",
  "model": "DPU100",
  "category": "battery_hydraulics",
  "submissions": [...],
  "aggregateScores": {...},
  "sessionName": "Ladder Truck Procurement"
}
```

### POST /summary
Generates category-level ranking summary.

```json
{
  "category": "Battery-Powered Hydraulic Tools",
  "products": [...],
  "sessionName": "Ladder Truck Procurement",
  "rankingType": "brand"
}
```

### POST /executive-report
Generates full executive report for Health & Safety Committee.

```json
{
  "sessionName": "Ladder Truck Procurement",
  "sessionDate": "2026-03-17",
  "categories": [...],
  "overallStats": {...}
}
```

## Configuration

Edit `workgroup_config.json` to customize:
- Evaluation categories and weights
- Product categories and manufacturers
- AI worker endpoints
- Output formats

## Data Flow

```
1. Load Data
   ├── Personnel CSV → Evaluator roster
   ├── Apparatus CSV → Fleet inventory
   └── SOGs PDF → Reference documents

2. Analyze Fleet
   ├── Filter ladder apparatus
   ├── Calculate status breakdown
   └── Generate fleet report

3. AI Processing (if worker available)
   ├── Vectorize product specs
   ├── Analyze individual products
   ├── Generate category summaries
   └── Compile executive report

4. Output
   ├── Markdown reports
   └── JSON data exports
```

## Integration Points

### Laravel Backend
- API routes: `/api/public/apparatus-layout/*`
- Database: PostgreSQL JSONB snapshots
- File uploads: Filament admin panel

### Cloudflare Worker
- URL: `https://workgroup-ai.mbfdhub.com`
- Models: Llama 3.3 70B (text), BGE Large (embeddings)
- Vector Index: Cloudflare Vectorize

### React SPA
- Apparatus Layout Planner
- Evaluation submission forms
- Results dashboard

## Troubleshooting

### AI Worker Unavailable
If the AI worker is offline, the orchestrator will:
1. Log a warning
2. Proceed in offline mode
3. Generate reports without AI analysis

### Missing Data Files
If CSV files are missing:
1. Check file paths in `DataIngestion`
2. Verify `PROJECT_ROOT` is correct
3. Ensure files are in expected locations

### Rate Limiting
The AI worker enforces rate limits:
- 30 requests per minute per IP
- Wait and retry if receiving 429 errors

## License

Part of the MBFD Hub project - Miami Beach Fire Department

## Contact

- Fire Chief: Digna Abello
- Committee: Health & Safety Committee
- Project: Mid-Mount Ladder Truck Equipment Evaluation