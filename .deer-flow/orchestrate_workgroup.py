#!/usr/bin/env python3
"""
DeerFlow 2.0 Orchestration Script
=================================
MBFD Mid-Mount Ladder Truck Equipment Evaluation Workgroup Analysis

This script orchestrates multi-agent processing for workgroup evaluation data:
- Data ingestion from CSV and PDF sources
- AI-powered analysis via Cloudflare Worker endpoints
- Report generation for Health & Safety Committee

Usage:
    python orchestrate_workgroup.py --session "Ladder Truck Procurement" --action analyze
    python orchestrate_workgroup.py --session "Ladder Truck Procurement" --action report
    python orchestrate_workgroup.py --help
"""

import argparse
import csv
import json
import os
import re
import sys
from dataclasses import dataclass, field
from datetime import datetime
from enum import Enum
from pathlib import Path
from typing import Any, Optional
import urllib.request
import urllib.error

# =============================================================================
# CONFIGURATION
# =============================================================================

# Cloudflare Worker endpoints
WORKER_BASE_URL = os.environ.get("MBFD_WORKER_URL", "https://workgroup-ai.mbfdhub.com")
WORKER_ENDPOINTS = {
    "vectorize": f"{WORKER_BASE_URL}/vectorize",
    "analyze": f"{WORKER_BASE_URL}/analyze",
    "summary": f"{WORKER_BASE_URL}/summary",
    "executive-report": f"{WORKER_BASE_URL}/executive-report",
    "health": f"{WORKER_BASE_URL}/health",
}

# File paths - Use absolute paths for mbfd-hub project
PROJECT_ROOT = Path("/mnt/user-data/workspace/mbfd-hub")
DATA_DIR = PROJECT_ROOT
PERSONNEL_FILE = PROJECT_ROOT / "scripts" / "mbfd-personnel.csv"
SOGS_DIR = PROJECT_ROOT / "sogs"
OUTPUT_DIR = Path("/mnt/user-data/outputs")

# SAVER Evaluation Categories
class SAVERCategory(str, Enum):
    CAPABILITY = "capability"
    USABILITY = "usability"
    AFFORDABILITY = "affordability"
    MAINTAINABILITY = "maintainability"
    DEPLOYABILITY = "deployability"

# Recommendation types
class Recommendation(str, Enum):
    ADVANCE = "Advance as Finalist"
    MAYBE = "Needs Further Review"
    NO = "Do Not Advance"

# =============================================================================
# DATA CLASSES
# =============================================================================

@dataclass
class Evaluator:
    """Represents a workgroup evaluator."""
    name: str
    rank: str
    employee_id: str
    
    @classmethod
    def from_csv_row(cls, row: dict) -> "Evaluator":
        return cls(
            name=row.get("Name", "").strip(),
            rank=row.get("Rank", "").strip(),
            employee_id=row.get("Employee ID", "").strip(),
        )

@dataclass
class Apparatus:
    """Represents a fire apparatus."""
    vehicle_number: str
    designation: str
    assignment: str
    current_location: str
    status: str
    notes: str
    class_description: str
    
    @classmethod
    def from_csv_row(cls, row: dict) -> "Apparatus":
        return cls(
            vehicle_number=row.get("Vehicle No", "").strip(),
            designation=row.get("Designation", "").strip(),
            assignment=row.get("Assignment", "").strip(),
            current_location=row.get("Current Location", "").strip(),
            status=row.get("Status", "").strip(),
            notes=row.get("Notes", "").strip(),
            class_description=row.get("Class Description", "").strip(),
        )

@dataclass
class EvaluationScore:
    """SAVER rubric evaluation scores."""
    capability: float = 0.0
    usability: float = 0.0
    affordability: float = 0.0
    maintainability: float = 0.0
    deployability: float = 0.0
    
    @property
    def overall(self) -> float:
        """Calculate weighted overall score."""
        weights = {
            SAVERCategory.CAPABILITY: 0.25,
            SAVERCategory.USABILITY: 0.20,
            SAVERCategory.AFFORDABILITY: 0.20,
            SAVERCategory.MAINTAINABILITY: 0.20,
            SAVERCategory.DEPLOYABILITY: 0.15,
        }
        return (
            self.capability * weights[SAVERCategory.CAPABILITY] +
            self.usability * weights[SAVERCategory.USABILITY] +
            self.affordability * weights[SAVERCategory.AFFORDABILITY] +
            self.maintainability * weights[SAVERCategory.MAINTAINABILITY] +
            self.deployability * weights[SAVERCategory.DEPLOYABILITY]
        )

@dataclass
class ProductSubmission:
    """Single evaluator submission for a product."""
    evaluator: Evaluator
    product_name: str
    manufacturer: str
    model: str
    category: str
    scores: EvaluationScore
    recommendation: Recommendation
    confidence: str
    has_deal_breaker: bool = False
    deal_breaker_note: str = ""
    narrative: dict = field(default_factory=dict)

@dataclass
class ProductAnalysis:
    """Aggregated analysis for a product."""
    product_name: str
    manufacturer: str
    model: str
    category: str
    submissions: list[ProductSubmission] = field(default_factory=list)
    ai_analysis: str = ""
    average_scores: dict = field(default_factory=dict)
    finalist_votes: int = 0
    deal_breaker_count: int = 0

@dataclass
class CategorySummary:
    """Summary for a product category."""
    category_name: str
    products: list[ProductAnalysis]
    ranking_type: str  # 'individual' or 'brand'
    ai_summary: str = ""
    evaluator_count: int = 0

@dataclass
class ExecutiveReport:
    """Final executive report for Health & Safety Committee."""
    session_name: str
    session_date: str
    categories: list[CategorySummary]
    overall_stats: dict
    ai_report: str = ""

# =============================================================================
# DATA INGESTION
# =============================================================================

class DataIngestion:
    """Handles loading and parsing workgroup data files."""
    
    def __init__(self, data_dir: Path = None):
        self.data_dir = data_dir or PROJECT_ROOT
        self.evaluators: list[Evaluator] = []
        self.apparatus: list[Apparatus] = []
        self.sog_files: list[Path] = []
    
    def load_personnel(self) -> list[Evaluator]:
        """Load evaluator personnel from CSV."""
        personnel_path = self.data_dir / "scripts" / "mbfd-personnel.csv"
        if not personnel_path.exists():
            print(f"⚠️ Personnel file not found: {personnel_path}")
            return []
        
        with open(personnel_path, "r", encoding="utf-8") as f:
            reader = csv.DictReader(f)
            self.evaluators = [Evaluator.from_csv_row(row) for row in reader]
        
        print(f"✅ Loaded {len(self.evaluators)} personnel records")
        return self.evaluators
    
    def load_apparatus(self) -> list[Apparatus]:
        """Load apparatus inventory from CSV."""
        apparatus_path = self.data_dir / "apparatus_data.csv"
        if not apparatus_path.exists():
            print(f"⚠️ Apparatus file not found: {apparatus_path}")
            return []
        
        apparatus_list = []
        with open(apparatus_path, "r", encoding="utf-8") as f:
            # Skip metadata rows (first 3 lines: indices, empty, title)
            lines = f.readlines()
            
            # Find the actual header row (contains "Vehicle No")
            header_line = None
            start_idx = 0
            for i, line in enumerate(lines):
                if "Vehicle No" in line:
                    header_line = line.strip()
                    start_idx = i
                    break
            
            if not header_line:
                print("⚠️ Could not find header row in apparatus CSV")
                return []
            
            # Parse from header row onwards
            from io import StringIO
            csv_content = StringIO("".join(lines[start_idx:]))
            reader = csv.DictReader(csv_content)
            
            for row in reader:
                # Skip empty rows
                if not row.get("Vehicle No") or row["Vehicle No"].strip() in ["", "Vehicle No"]:
                    continue
                apparatus_list.append(Apparatus.from_csv_row(row))
        
        self.apparatus = apparatus_list
        print(f"✅ Loaded {len(self.apparatus)} apparatus records")
        return self.apparatus
    
    def load_sogs(self) -> list[Path]:
        """Find all SOG PDF files."""
        sogs_dir = self.data_dir / "sogs"
        if not sogs_dir.exists():
            print(f"⚠️ SOGs directory not found: {sogs_dir}")
            return []
        
        self.sog_files = list(sogs_dir.glob("*.pdf"))
        print(f"✅ Found {len(self.sog_files)} SOG PDF files")
        return self.sog_files
    
    def get_ladder_apparatus(self) -> list[Apparatus]:
        """Filter apparatus to ladder trucks only."""
        ladder_keywords = ["LADDER", "L", "LAD"]
        return [
            a for a in self.apparatus
            if any(kw in a.class_description.upper() for kw in ladder_keywords) or
               any(kw in a.designation.upper() for kw in ladder_keywords)
        ]
    
    def get_frontline_apparatus(self) -> list[Apparatus]:
        """Filter apparatus to frontline (in-service) units."""
        return [a for a in self.apparatus if a.status.lower() == "in service"]
    
    def get_reserve_apparatus(self) -> list[Apparatus]:
        """Filter apparatus to reserve units."""
        return [a for a in self.apparatus if a.assignment.lower() == "reserve"]

# =============================================================================
# AI WORKER CLIENT
# =============================================================================

class AIWorkerClient:
    """Client for Cloudflare Worker AI endpoints."""
    
    def __init__(self, base_url: str = None):
        self.base_url = base_url or WORKER_BASE_URL
        self.endpoints = WORKER_ENDPOINTS
    
    def health_check(self) -> dict:
        """Check worker health status."""
        try:
            with urllib.request.urlopen(self.endpoints["health"], timeout=10) as response:
                return json.loads(response.read().decode())
        except Exception as e:
            return {"status": "error", "error": str(e)}
    
    def _post(self, endpoint: str, data: dict) -> dict:
        """Make POST request to worker endpoint."""
        url = self.endpoints.get(endpoint)
        if not url:
            raise ValueError(f"Unknown endpoint: {endpoint}")
        
        headers = {"Content-Type": "application/json"}
        body = json.dumps(data).encode("utf-8")
        req = urllib.request.Request(url, data=body, headers=headers, method="POST")
        
        try:
            with urllib.request.urlopen(req, timeout=60) as response:
                return json.loads(response.read().decode())
        except urllib.error.HTTPError as e:
            return {"error": f"HTTP {e.code}", "detail": e.read().decode()}
        except Exception as e:
            return {"error": str(e)}
    
    def vectorize(self, text: str, metadata: dict) -> dict:
        """Ingest text chunk into vector index."""
        return self._post("vectorize", {
            "text": text,
            **metadata
        })
    
    def analyze_product(self, product: ProductAnalysis, session_name: str) -> dict:
        """Generate AI analysis for a product."""
        submissions_data = [
            {
                "evaluatorRole": s.evaluator.rank,
                "overallScore": s.scores.overall,
                "capabilityScore": s.scores.capability,
                "usabilityScore": s.scores.usability,
                "affordabilityScore": s.scores.affordability,
                "maintainabilityScore": s.scores.maintainability,
                "deployabilityScore": s.scores.deployability,
                "recommendationLabel": s.recommendation.value,
                "confidenceLabel": s.confidence,
                "hasDealBreaker": s.has_deal_breaker,
                "dealBreakerNote": s.deal_breaker_note,
                "narrative": s.narrative,
            }
            for s in product.submissions
        ]
        
        return self._post("analyze", {
            "productName": product.product_name,
            "manufacturer": product.manufacturer,
            "model": product.model,
            "category": product.category,
            "submissions": submissions_data,
            "aggregateScores": product.average_scores,
            "sessionName": session_name,
        })
    
    def generate_summary(self, category: CategorySummary, session_name: str) -> dict:
        """Generate category summary."""
        products_data = [
            {
                "name": p.product_name,
                "manufacturer": p.manufacturer,
                "averageScore": p.average_scores.get("overall", 0),
                "capabilityScore": p.average_scores.get("capability", 0),
                "usabilityScore": p.average_scores.get("usability", 0),
                "affordabilityScore": p.average_scores.get("affordability", 0),
                "maintainabilityScore": p.average_scores.get("maintainability", 0),
                "deployabilityScore": p.average_scores.get("deployability", 0),
                "submissionCount": len(p.submissions),
                "finalistVotes": p.finalist_votes,
                "dealBreakerCount": p.deal_breaker_count,
            }
            for p in category.products
        ]
        
        return self._post("summary", {
            "category": category.category_name,
            "products": products_data,
            "sessionName": session_name,
            "rankingType": category.ranking_type,
        })
    
    def generate_executive_report(self, report: ExecutiveReport) -> dict:
        """Generate full executive report."""
        categories_data = [
            {
                "name": c.category_name,
                "rankingType": c.ranking_type,
                "evaluatorCount": c.evaluator_count,
                "products": [
                    {
                        "name": p.product_name,
                        "manufacturer": p.manufacturer,
                        "averageScore": p.average_scores.get("overall", 0),
                        "isFinalist": p.finalist_votes > 0,
                        "hasDealBreaker": p.deal_breaker_count > 0,
                    }
                    for p in c.products
                ],
                "notes": c.ai_summary,
            }
            for c in report.categories
        ]
        
        return self._post("executive-report", {
            "sessionName": report.session_name,
            "sessionDate": report.session_date,
            "categories": categories_data,
            "overallStats": report.overall_stats,
        })

# =============================================================================
# ORCHESTRATOR
# =============================================================================

class WorkgroupOrchestrator:
    """Main orchestrator for DeerFlow 2.0 workgroup analysis."""
    
    def __init__(self):
        self.ingestion = DataIngestion()
        self.ai_client = AIWorkerClient()
        self.output_dir = OUTPUT_DIR
        self.output_dir.mkdir(parents=True, exist_ok=True)
    
    def initialize(self) -> bool:
        """Load all data and check AI worker health."""
        print("\n" + "="*60)
        print("🦌 DeerFlow 2.0 - MBFD Workgroup Orchestration")
        print("="*60 + "\n")
        
        # Load data
        print("📂 Loading data files...")
        self.ingestion.load_personnel()
        self.ingestion.load_apparatus()
        self.ingestion.load_sogs()
        
        # Check AI worker
        print("\n🔌 Checking AI Worker health...")
        health = self.ai_client.health_check()
        if health.get("status") == "ok":
            print(f"✅ AI Worker online: {health.get('worker', 'unknown')} v{health.get('version', '?')}")
            print(f"   Models: {health.get('models', {})}")
            return True
        else:
            print(f"⚠️ AI Worker not available: {health}")
            print("   Proceeding in offline mode (analysis will be limited)")
            return False
    
    def analyze_apparatus_fleet(self) -> dict:
        """Analyze apparatus fleet status."""
        print("\n📊 Analyzing apparatus fleet...")
        
        all_apparatus = self.ingestion.apparatus
        ladder_apparatus = self.ingestion.get_ladder_apparatus()
        frontline = self.ingestion.get_frontline_apparatus()
        reserve = self.ingestion.get_reserve_apparatus()
        
        # Status breakdown
        status_counts = {}
        for a in all_apparatus:
            status = a.status.lower()
            status_counts[status] = status_counts.get(status, 0) + 1
        
        # Class breakdown
        class_counts = {}
        for a in all_apparatus:
            cls = a.class_description.upper()
            class_counts[cls] = class_counts.get(cls, 0) + 1
        
        analysis = {
            "total_apparatus": len(all_apparatus),
            "ladder_apparatus": len(ladder_apparatus),
            "frontline_units": len(frontline),
            "reserve_units": len(reserve),
            "status_breakdown": status_counts,
            "class_breakdown": class_counts,
            "ladder_details": [
                {
                    "vehicle_number": a.vehicle_number,
                    "designation": a.designation,
                    "location": a.current_location,
                    "status": a.status,
                    "notes": a.notes,
                }
                for a in ladder_apparatus
            ],
        }
        
        print(f"   Total apparatus: {analysis['total_apparatus']}")
        print(f"   Ladder trucks: {analysis['ladder_apparatus']}")
        print(f"   Frontline units: {analysis['frontline_units']}")
        print(f"   Reserve units: {analysis['reserve_units']}")
        
        return analysis
    
    def analyze_personnel(self) -> dict:
        """Analyze workgroup personnel."""
        print("\n👥 Analyzing personnel...")
        
        evaluators = self.ingestion.evaluators
        
        # Rank breakdown
        rank_counts = {}
        for e in evaluators:
            rank = e.rank
            rank_counts[rank] = rank_counts.get(rank, 0) + 1
        
        analysis = {
            "total_personnel": len(evaluators),
            "rank_breakdown": rank_counts,
            "division_chiefs": [e.name for e in evaluators if e.rank == "Division Chief"],
            "captains": [e.name for e in evaluators if e.rank == "Captain"],
            "lieutenants": [e.name for e in evaluators if e.rank == "Lieutenant"],
            "firefighters": [e.name for e in evaluators if e.rank == "Firefighter"],
        }
        
        print(f"   Total personnel: {analysis['total_personnel']}")
        print(f"   Division Chiefs: {len(analysis['division_chiefs'])}")
        print(f"   Captains: {len(analysis['captains'])}")
        print(f"   Lieutenants: {len(analysis['lieutenants'])}")
        print(f"   Firefighters: {len(analysis['firefighters'])}")
        
        return analysis
    
    def generate_fleet_report(self, apparatus_analysis: dict, personnel_analysis: dict) -> str:
        """Generate fleet status report."""
        report_lines = [
            "# MBFD Fleet Status Report",
            f"\nGenerated: {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}",
            "\n---\n",
            "## Executive Summary\n",
            f"Total apparatus: **{apparatus_analysis['total_apparatus']}**",
            f"Ladder trucks: **{apparatus_analysis['ladder_apparatus']}**",
            f"Frontline units: **{apparatus_analysis['frontline_units']}**",
            f"Reserve units: **{apparatus_analysis['reserve_units']}**\n",
            "## Status Breakdown\n",
        ]
        
        for status, count in apparatus_analysis["status_breakdown"].items():
            report_lines.append(f"- **{status.title()}**: {count}")
        
        report_lines.append("\n## Class Breakdown\n")
        for cls, count in apparatus_analysis["class_breakdown"].items():
            report_lines.append(f"- **{cls}**: {count}")
        
        report_lines.append("\n## Ladder Apparatus Details\n")
        for ladder in apparatus_analysis["ladder_details"]:
            status_icon = "✅" if ladder["status"].lower() == "in service" else "⚠️"
            report_lines.append(
                f"{status_icon} **{ladder['designation']}** ({ladder['vehicle_number']}): "
                f"{ladder['location']} - {ladder['status']}"
            )
            if ladder["notes"]:
                report_lines.append(f"   - Notes: {ladder['notes']}")
        
        report_lines.append("\n## Personnel Summary\n")
        report_lines.append(f"Total personnel: **{personnel_analysis['total_personnel']}**\n")
        report_lines.append(f"- Division Chiefs: {len(personnel_analysis['division_chiefs'])}")
        report_lines.append(f"- Captains: {len(personnel_analysis['captains'])}")
        report_lines.append(f"- Lieutenants: {len(personnel_analysis['lieutenants'])}")
        report_lines.append(f"- Firefighters: {len(personnel_analysis['firefighters'])}")
        
        return "\n".join(report_lines)
    
    def save_report(self, report: str, filename: str) -> Path:
        """Save report to output directory."""
        output_path = self.output_dir / filename
        with open(output_path, "w", encoding="utf-8") as f:
            f.write(report)
        print(f"📄 Report saved: {output_path}")
        return output_path
    
    def run_analysis(self, session_name: str = "Ladder Truck Procurement Evaluation") -> dict:
        """Run complete workgroup analysis."""
        print("\n" + "="*60)
        print("🔍 Running Workgroup Analysis")
        print("="*60)
        
        # Initialize
        ai_available = self.initialize()
        
        # Analyze data
        apparatus_analysis = self.analyze_apparatus_fleet()
        personnel_analysis = self.analyze_personnel()
        
        # Generate report
        print("\n📝 Generating fleet report...")
        fleet_report = self.generate_fleet_report(apparatus_analysis, personnel_analysis)
        
        # Save report
        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        report_path = self.save_report(
            fleet_report,
            f"fleet_status_report_{timestamp}.md"
        )
        
        # Generate JSON data export
        data_export = {
            "session_name": session_name,
            "generated_at": datetime.now().isoformat(),
            "apparatus": apparatus_analysis,
            "personnel": personnel_analysis,
            "ai_worker_status": "online" if ai_available else "offline",
        }
        
        data_path = self.output_dir / f"workgroup_data_{timestamp}.json"
        with open(data_path, "w", encoding="utf-8") as f:
            json.dump(data_export, f, indent=2)
        print(f"📊 Data export saved: {data_path}")
        
        print("\n" + "="*60)
        print("✅ Analysis Complete")
        print("="*60)
        
        return {
            "success": True,
            "report_path": str(report_path),
            "data_path": str(data_path),
            "apparatus_count": apparatus_analysis["total_apparatus"],
            "personnel_count": personnel_analysis["total_personnel"],
            "ai_available": ai_available,
        }

# =============================================================================
# CLI ENTRY POINT
# =============================================================================

def main():
    parser = argparse.ArgumentParser(
        description="DeerFlow 2.0 - MBFD Mid-Mount Ladder Workgroup Orchestration"
    )
    parser.add_argument(
        "--session",
        type=str,
        default="Ladder Truck Procurement Evaluation",
        help="Session name for the evaluation",
    )
    parser.add_argument(
        "--action",
        type=str,
        choices=["analyze", "report", "all"],
        default="all",
        help="Action to perform: analyze, report, or all",
    )
    parser.add_argument(
        "--output",
        type=str,
        default=None,
        help="Output directory for reports",
    )
    
    args = parser.parse_args()
    
    orchestrator = WorkgroupOrchestrator()
    
    if args.output:
        orchestrator.output_dir = Path(args.output)
        orchestrator.output_dir.mkdir(parents=True, exist_ok=True)
    
    if args.action in ["analyze", "all"]:
        result = orchestrator.run_analysis(session_name=args.session)
        print(f"\n✅ Result: {json.dumps(result, indent=2)}")
    
    return 0

if __name__ == "__main__":
    sys.exit(main())