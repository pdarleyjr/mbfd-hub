# MBFD Checkout System - Reuse Map

> **Source Repository**: `C:\Users\Peter Darley\Documents\mbfd-checkout-system`  
> **Analysis Date**: January 2026

---

## 1. Apparatus List/Types

### File Locations
| File | Purpose |
|------|---------|
| `src/types.ts` | Core TypeScript interfaces for Apparatus, User, Defect, etc. |
| `src/lib/config.ts` | `APPARATUS_LIST` constant with all 14 apparatus units |

### Key Data Structures

```typescript
// From src/types.ts
export type Apparatus = 
  | 'Engine 1' | 'Engine 2' | 'Engine 3' | 'Engine 4'
  | 'Ladder 1' | 'Ladder 3'
  | 'Rescue 1' | 'Rescue 2' | 'Rescue 3' | 'Rescue 4'
  | 'Rescue 11' | 'Rescue 22' | 'Rescue 44'
  | 'Rope Inventory';

export type Rank = 'Firefighter' | 'DE' | 'Lieutenant' | 'Captain' | 'Chief';
export type Shift = 'A' | 'B' | 'C';
export type ItemStatus = 'present' | 'missing' | 'damaged';
```

### Reuse Assessment
| Category | Files |
|----------|-------|
| **Copy as-is** | `Apparatus`, `Rank`, `Shift`, `ItemStatus` types |
| **Port/Adapt** | `APPARATUS_LIST` - make dynamic from database |
| **Rewrite** | None |

---

## 2. Checklist JSON Files

### File Locations
| File | Apparatus Coverage |
|------|-------------------|
| `public/data/engine_checklist.json` | Engine 1-4 |
| `public/data/ladder1_checklist.json` | Ladder 1 |
| `public/data/ladder3_checklist.json` | Ladder 3 |
| `public/data/rescue_checklist.json` | All Rescue units |
| `public/data/rope_checklist.json` | Rope Inventory |

### Checklist JSON Structure

```typescript
// From src/types.ts
interface ChecklistData {
  title: string;
  compartments: Compartment[];
  dailySchedule?: DailyScheduleTask[];
  officerChecklist?: OfficerChecklistItem[];
}

interface Compartment {
  id: string;           // e.g., "front_cab", "comp_1"
  title: string;        // e.g., "Front Cab", "Compartment 1"
  items: (string | CompartmentItem)[];
}

interface CompartmentItem {
  name: string;
  inputType?: 'checkbox' | 'text' | 'number' | 'percentage' | 'radio';
  expectedQuantity?: number;
  note?: string;
}

interface DailyScheduleTask {
  day: string;          // "Monday", "Tuesday", etc.
  tasks: string[];
}

interface OfficerChecklistItem {
  id: string;
  name: string;
  inputType?: 'checkbox' | 'text' | 'number' | 'percentage' | 'radio';
  required?: boolean;
}
```

### Example Checklist Entry
```json
{
  "id": "comp_2",
  "title": "Compartment 2",
  "items": [
    { "name": "SawZall 20 Volt with Batteries", "inputType": "checkbox", "note": "1 SawZall, 2 Batteries" },
    { "name": "Partner Saw Spare Blades", "inputType": "checkbox", "expectedQuantity": 4 }
  ]
}
```

### Reuse Assessment
| Category | Items |
|----------|-------|
| **Copy as-is** | All 5 checklist JSON files - comprehensive equipment lists |
| **Port/Adapt** | `ChecklistData`, `Compartment`, `CompartmentItem` interfaces |
| **Rewrite** | None - JSON structure is portable |

---

## 3. InspectionWizard Flow

### File Locations
| File | Purpose |
|------|---------|
| `src/components/InspectionWizard.tsx` | Main wizard component (892 lines) |
| `src/components/InspectionCard.tsx` | Individual item inspection card |
| `src/components/ui/Button.tsx` | Reusable button component |
| `src/components/ui/Card.tsx` | Card layout components |
| `src/components/ui/Modal.tsx` | Modal dialog component |

### Wizard Flow Logic

```
1. User Login → Load checklist based on apparatus type
2. Officer Checklist Step (if defined)
3. Compartment Steps (iterate through all compartments)
4. Each compartment: Check items → Mark status → Add notes/photos
5. Submit → Create defects + log entry
```

### Key State Management
```typescript
const [currentStep, setCurrentStep] = useState(0);
const [items, setItems] = useState<Map<string, ChecklistItem>>(new Map());
const [existingDefects, setExistingDefects] = useState<Map<string, GitHubIssue>>(new Map());
const [officerItems, setOfficerItems] = useState<OfficerChecklistItem[]>([]);
```

### Checklist File Selection Logic
```typescript
if (userData.apparatus.startsWith('Engine')) {
  checklistFile = 'engine_checklist.json';
} else if (userData.apparatus === 'Ladder 1') {
  checklistFile = 'ladder1_checklist.json';
} else if (userData.apparatus === 'Ladder 3') {
  checklistFile = 'ladder3_checklist.json';
} else if (userData.apparatus === 'Rope Inventory') {
  checklistFile = 'rope_checklist.json';
} else {
  checklistFile = 'rescue_checklist.json';
}
```

### Reuse Assessment
| Category | Items |
|----------|-------|
| **Copy as-is** | Wizard step navigation logic, "Check All" functionality |
| **Port/Adapt** | React components → Filament/Livewire, UI logic patterns |
| **Rewrite** | UI layer (React → Laravel/Filament) |

---

## 4. Defect Deduplication Logic (IssueOps)

### File Locations
| File | Purpose |
|------|---------|
| `src/lib/github.ts` | GitHub API service with defect dedup |
| `src/lib/config.ts` | `DEFECT_TITLE_REGEX` pattern |

### Deduplication Pattern

```typescript
// From src/lib/config.ts
export const DEFECT_TITLE_REGEX = /\[(.+)\]\s+(.+):\s+(.+?)\s+-\s+(Missing|Damaged)/;
// Captures: [1]=Apparatus, [2]=Compartment, [3]=Item, [4]=Status

// From src/lib/github.ts - checkExistingDefects()
async checkExistingDefects(apparatus: string): Promise<Map<string, GitHubIssue>> {
  // 1. Fetch all open issues with labels: "Defect" + apparatus name
  const response = await fetch(
    `${API_BASE_URL}/issues?state=open&labels=${LABELS.DEFECT},${apparatus}&per_page=100`
  );
  
  // 2. Parse each issue title with regex
  const defectMap = new Map<string, GitHubIssue>();
  for (const issue of issues) {
    const match = issue.title.match(DEFECT_TITLE_REGEX);
    if (match) {
      const [, , compartment, item] = match;
      const key = `${compartment}:${item}`;  // Unique key
      defectMap.set(key, issue);
    }
  }
  return defectMap;
}
```

### Defect Submission Logic
```typescript
// From github.ts - submitChecklist()
for (const defect of defects) {
  const defectKey = `${defect.compartment}:${defect.item}`;
  const existingIssue = existingDefects.get(defectKey);

  if (existingIssue) {
    // Add comment to existing issue (avoid duplicate)
    await this.addCommentToDefect(existingIssue.number, ...);
  } else {
    // Create new issue
    await this.createDefectIssue(...);
  }
}
```

### Issue Title Format
```
[Engine 1] Front Cab: TIC - Missing
[Rescue 2] Compartment 3: O2 Bottle - Damaged
```

### GitHub Labels Used
```typescript
export const LABELS = {
  DEFECT: 'Defect',
  LOG: 'Log',
  DAMAGED: 'Damaged',
  RESOLVED: 'Resolved',
} as const;
```

### Reuse Assessment
| Category | Items |
|----------|-------|
| **Copy as-is** | `DEFECT_TITLE_REGEX`, dedup key format `{compartment}:{item}` |
| **Port/Adapt** | Dedup logic pattern → database query (check existing open defects) |
| **Rewrite** | GitHub API → Laravel Eloquent, Issue storage → PostgreSQL |

---

## 5. Cloudflare Worker / AI Integration

### File Locations
| File | Purpose |
|------|---------|
| `worker/mbfd-github-proxy/src/index.ts` | Main worker entry point |
| `worker/mbfd-github-proxy/src/handlers/ai-insights.ts` | Workers AI integration |
| `worker/mbfd-github-proxy/src/handlers/apparatus-status.ts` | Apparatus status from Google Sheets |
| `src/components/AIFleetInsights.tsx` | Frontend AI insights component |
| `src/lib/config.ts` | `WORKER_URL` constant |

### Worker URL
```typescript
export const WORKER_URL = 'https://mbfd-github-proxy.pdarleyjr.workers.dev';
export const API_BASE_URL = `${WORKER_URL}/api`;
```

### AI Insights Handler

```typescript
// From ai-insights.ts
interface AIInsightRequest {
  tasks?: Array<{
    id: string;
    apparatus: string;
    itemName: string;
    deficiencyType: string;
  }>;
  inventory?: Array<{
    id: string;
    equipmentName: string;
    quantity: number;
    minQty?: number;
  }>;
}

// Uses Workers AI binding
const response = await env.AI.run('@cf/meta/llama-2-7b-chat-int8', {
  messages: [
    { role: 'system', content: 'You are a helpful inventory analyst...' },
    { role: 'user', content: prompt },
  ],
});
```

### AI Response Structure
```typescript
{
  summary: string;
  recurringIssues: string[];
  reorderSuggestions: Array<{item: string, reason: string, urgency: string}>;
  anomalies: string[];
}
```

### Reuse Assessment
| Category | Items |
|----------|-------|
| **Copy as-is** | AI prompt template, response structure format |
| **Port/Adapt** | Workers AI → OpenAI/Claude API in Laravel, D1 → PostgreSQL |
| **Rewrite** | Worker proxy → Laravel API routes, AI binding → HTTP API calls |

---

## 6. Key Interfaces Summary

### Must Copy (Portable TypeScript Types)
```typescript
// Core domain types - convert to PHP classes/enums
Apparatus, Rank, Shift, ItemStatus
Compartment, CompartmentItem, ChecklistData
Defect, InspectionSubmission, GitHubIssue
OfficerChecklistItem, DailyScheduleTask
ApparatusStatus, VehicleChangeRequest
```

### Must Port (Adapt to Laravel)
```typescript
// Business logic patterns
checkExistingDefects() → Eloquent query on defects table
submitChecklist() → DefectService::submit()
DEFECT_TITLE_REGEX → Database query with WHERE clause
```

### Must Rewrite (Technology Change)
- React components → Filament/Livewire
- Cloudflare Worker → Laravel API controllers
- D1 database → PostgreSQL
- Workers AI → OpenAI/Claude via Laravel HTTP client

---

## 7. Migration Checklist

### Phase 1: Data Models
- [ ] Create `Apparatus` model with status enum
- [ ] Create `Compartment` model with items JSON
- [ ] Create `ChecklistTemplate` model (import JSON files)
- [ ] Create `Defect` model with dedup logic
- [ ] Create `Inspection` model (log entries)

### Phase 2: Core Logic
- [ ] Port `DEFECT_TITLE_REGEX` matching to PHP
- [ ] Implement defect deduplication query
- [ ] Create inspection submission service
- [ ] Add defect resolution workflow

### Phase 3: UI Components
- [ ] Build inspection wizard (Filament/Livewire)
- [ ] Create compartment inspection cards
- [ ] Add officer checklist step
- [ ] Implement "Check All" functionality

### Phase 4: AI Integration
- [ ] Set up OpenAI/Claude API integration
- [ ] Port AI prompt templates
- [ ] Create fleet insights endpoint
- [ ] Build insights dashboard component
