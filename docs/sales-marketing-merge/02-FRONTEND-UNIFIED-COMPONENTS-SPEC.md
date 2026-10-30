# Frontend Unified Component Specification: Sales & Marketing Pipeline

> **Scope:** `C:\Users\MILAN\Downloads\g2gv0`  
> **Target:** Merging duplicate components, establishing canonical routes, and realigning `content-map-crm.ts` with the 10-stage revenue lifecycle.  
> **Date:** 2026-10-29  

---

## 1. Unified Deals & Opportunities Architecture

### 1.1 The Core Problem
- `components/domain/crm/opportunity-list-view.tsx`: Has robust picklist-driven HTML5 drag-and-drop Kanban, import dialog with header mapping, export CSV, bulk actions, and direct integration with Quotes, Sales Orders, and Invoices.
- `components/domain/gtm/deals-view.tsx`: Has currency summary tiles (`open_value_by_currency`, `won`, `lost`), deal health flags (stalled, missing next steps), and AI coaching/methodology evaluation drawer.
- **Goal:** Combine these into **one canonical component** at `components/domain/crm/opportunity-list-view.tsx` so users have both the CPQ/financial order-to-cash workflow AND the AI deal intelligence in one place.

### 1.2 Unified UI Layout Structure
```
+---------------------------------------------------------------------------------------------------+
| Opportunity & Deals Pipeline                                            [Export] [Import] [+ New] |
+---------------------------------------------------------------------------------------------------+
| PIPELINE SUMMARY TILES (from DealsView)                                                           |
| [Open: 24 (INR 1.4 Cr | USD 120k)]  [Won (30d): 8 (INR 45L)]  [Lost (30d): 2]  [Health Flags: 3]  |
+---------------------------------------------------------------------------------------------------+
| CONTROLS: [Kanban / Table Toggle]  [Search...]  [Filter by Owner]  [Include Closed Deals Checkbox] |
+---------------------------------------------------------------------------------------------------+
| KANBAN BOARD (from OpportunityKanbanView)                                                         |
| +-------------------+  +-------------------+  +-------------------+  +--------------------------+ |
| | Qualification (5) |  | Proposal/Quote(3) |  | Negotiation (4)   |  | Closed Won (8)           | |
| +-------------------+  +-------------------+  +-------------------+  +--------------------------+ |
| | Deal Card         |  | Deal Card         |  | Deal Card         |  | Deal Card                | |
| | - Account Name    |  | - Quote #1042     |  | - Discount: 10%   |  | - Converted to Invoice   | |
| | - INR 5,00,000    |  | - INR 12,50,000   |  | - INR 25,00,000   |  | - INR 45,00,000          | |
| | - [! 2 Flags]     |  | - AI Score: 88    |  | - Next: Call CFO  |  | - [View Sales Order]     | |
| +-------------------+  +-------------------+  +-------------------+  +--------------------------+ |
+---------------------------------------------------------------------------------------------------+
| SLIDE-OVER DRAWER (When card clicked):                                                            |
| Tabs: [Overview] [Line Items & Quotes] [AI Coaching & Discovery] [Activity History]              |
+---------------------------------------------------------------------------------------------------+
```

### 1.3 TypeScript Interface Fusion
```typescript
export interface UnifiedDealOpportunity {
  // Core CRM fields (vtiger_potential)
  id: string
  name: string
  organizationId?: string
  organizationName?: string
  contactId?: string
  contactName?: string
  amount: number | null
  currency: string
  salesStage: string
  probability: number
  closingDate: string | null
  assignedTo: string
  assignedToName?: string
  description?: string
  nextStep?: string

  // GTM Deal Intelligence fields (gtm_deals)
  healthFlags?: Array<{
    type: 'stalled' | 'missing_next_step' | 'missing_stakeholder' | 'overdue'
    severity: 'warn' | 'critical'
    message: string
  }>
  methodologyScore?: {
    framework: 'MEDDPICC' | 'BANT'
    score: number // 0 - 100
    metrics: Record<string, boolean>
  }
  coachingNotes?: string[]
  quoteId?: string
  salesOrderId?: string
  invoiceId?: string
}
```

---

## 2. Unified Campaigns Architecture

### 2.1 The Core Problem
- `app/module/crm/marketing/campaigns` (`CampaignListView`): Basic CRM list tracking budget, actual cost, and expected revenue.
- `app/gtm/campaigns` (`CampaignsView`): Modern multi-channel campaign builder with dynamic audience segments, industry packs, and outreach sequences.
- **Goal:** Standardize on `/gtm/campaigns` as the single canonical screen, enhancing its detail view with the CRM budget and financial ROI tracking fields.

### 2.2 Route Deprecation & Fallback
In `g2gv0/hooks/content-map-crm.ts`, replace the legacy `CampaignListView` registration:
```typescript
// Legacy CRM Campaign route seamlessly loads the unified GTM Campaigns view
const UnifiedCampaignsView = createLazyComponent(() => 
  import('@/components/domain/gtm/campaigns-view').then((m) => ({ default: m.CampaignsView }))
)

export const CRM_CONTENT: ContentRoute[] = [
  // ...
  { accessLink: '/module/crm/marketing/campaigns', component: UnifiedCampaignsView },
  { accessLink: '/gtm/campaigns', component: UnifiedCampaignsView },
]
```

---

## 3. Outreach & Approvals Consolidation

### 3.1 Retirement of Redundant Menu Entry
- In the old menu, `Outreach Approvals` was a separate sub-item under Marketing.
- In `components/domain/gtm/outreach-view.tsx`, the tab system already provides:
  - Tab 1: `All Outreach`
  - Tab 2: `Pending Approval` (Filter by `status === 'pending_approval'`)
  - Tab 3: `Suppressions / Opt-outs`
  - Tab 4: `Settings / Throttling`
- **Resolution:** The separate `Outreach Approvals` menu item is completely retired in `tblmenumaster_g2g`. A user wanting to approve emails clicks directly into `Campaigns & Outreach > Outreach Queue` (`/gtm/outreach?status=pending_approval`).

---

## 4. Decommissioning Ghost Screen `Consent & Preferences`
- **Route to Decommission:** `/module/crm/marketing/consent`
- **Action:**
  1. No file exists in `g2gv0`, so no code deletion is required in frontend.
  2. In `hooks/content-map-crm.ts`, ensure no mock or coming-soon route is registered.
  3. Decommission database entry via migration `2026_10_29_180000_unify_sales_marketing_pipeline_lifecycle.php`.

---

## 5. Canonical Route Map for `content-map-crm.ts`

```typescript
export const CRM_CONTENT: ContentRoute[] = [
  // Stage 1: Command Center
  { accessLink: '/gtm/command-center', component: CommandCenterView },

  // Stage 2: Market Signals & Research
  { accessLink: '/signals', component: SignalsPageContent },
  { accessLink: '/gtm/prospecting', component: ProspectingView },

  // Stage 3: Accounts & Audience
  { accessLink: '/module/crm/marketing/organizations', component: OrganizationListView },
  { accessLink: '/module/crm/marketing/contacts', component: ContactListView },
  { accessLink: '/gtm/playbooks', component: PlaybooksView },

  // Stage 4: Multi-Channel Campaigns & Outreach
  { accessLink: '/gtm/campaigns', component: CampaignsView },
  { accessLink: '/gtm/outreach', component: OutreachView },

  // Stage 5: Leads & Qualification
  { accessLink: '/module/crm/marketing/leads', component: LeadListView },
  { accessLink: '/module/crm/marketing/automation-rules', component: LeadRoutingAdminView },

  // Stage 6: Sales Pipeline & Deals (Unified)
  { accessLink: '/module/crm/sales/opportunities', component: UnifiedOpportunityListView },
  { accessLink: '/gtm/deals', component: UnifiedOpportunityListView }, // Legacy redirect
  { accessLink: '/gtm/forecast', component: ForecastView },

  // Stage 7: Commercials & Billing (Order-to-Cash)
  { accessLink: '/module/crm/sales/quotes', component: QuoteListView },
  { accessLink: '/module/crm/sales/products', component: ProductListView },
  { accessLink: '/module/crm/sales/services', component: ServiceListView },
  { accessLink: '/module/crm/sales/price-books', component: PriceBookListView },
  { accessLink: '/module/crm/sales/sales-orders', component: SalesOrderListView },
  { accessLink: '/module/crm/sales/invoices', component: InvoiceListView },
  { accessLink: '/module/crm/sales/vendors', component: VendorListView },
  { accessLink: '/module/crm/sales/purchase-orders', component: PurchaseOrderListView },

  // Stage 8: Customer Success & Retention
  { accessLink: '/gtm/customers', component: CustomersView },
  { accessLink: '/module/crm/sales/sms-notifier', component: SmsLogListView },

  // Stage 9: AI Agents & Automation Hub
  { accessLink: '/gtm/agents', component: AgentsView },
  { accessLink: '/gtm/workflows', component: WorkflowsView },

  // Stage 10: Reports & Governance
  { accessLink: '/module/crm/reports', component: CrmReportsShell },
  { accessLink: '/gtm/monitoring', component: MonitoringView },
  { accessLink: '/module/crm/master-fields', component: CrmPicklistAdminView },
]
```

