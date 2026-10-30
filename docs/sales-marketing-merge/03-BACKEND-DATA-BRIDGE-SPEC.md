# Backend Data Bridge Specification: Active Pipeline Synchronization

> **Target Systems:** `hp_erp` (Laravel Backend) & DB3 Database Models  
> **Scope:** Resolving the split-brain pipeline between `crm_opportunities` and `gtm_deals`  
> **Date:** 2026-10-29  
> **Status:** Architecture Specification & Engineering Blueprint  

---

## 1. Root Cause Analysis: The Split-Brain Pipeline

During earlier iterations of G2G, the codebase introduced two parallel systems for pipeline tracking:
1. **Legacy CRM System (`crm_opportunities`):** Designed for transactional ERP functions (linked to `crm_organizations`, `crm_quotes`, `crm_salesorders`, and `crm_invoices`).
2. **Modern GTM System (`gtm_deals`):** Designed for AI agents, coaching (`DealAnalyzer`, `DealHealth`), and forecasting (`gtm_forecast_entries`).

### The Flaw in Earlier Bridging
In `App\Http\Controllers\Api\Gtm\DealController.php` (line 318):
```php
if ($deal->stage !== 'won') {
    return response()->json([
        'status' => 0, 
        'message' => 'Only a won deal can be converted to a CRM Opportunity.'
    ], 422);
}
```
Because of this restriction:
- Deals in `discovery`, `qualification`, `proposal`, and `negotiation` stages were **trapped inside GTM**.
- Account Executives negotiating in the `proposal` stage **could not attach Quotes, line items, or price books**, because the record did not exist in `crm_opportunities`.
- Opportunities created directly in the CRM Kanban board had **zero visibility** from the GTM AI Deal Coach, AI Discovery analyzer, and forecasting models.

---

## 2. The Enterprise Solution: Bi-Directional Active Pipeline Synchronization

To unify Sales & Marketing into one cohesive revenue engine, the system will support **real-time bi-directional synchronization** across all stages of the lifecycle.

### 2.1 Stage Mapping Matrix

| Stage Key | GTM Pipeline Stage | CRM Stage Label | Default Probability | Milestone / Business Requirement |
|---|---|---|---|---|
| **STG-01** | `discovery` | Needs Analysis | 20% | Market signal ingested, initial qualification meeting scheduled |
| **STG-02** | `qualification` | Qualification In Progress | 40% | Decision maker identified, pain points documented via BANT/MEDDPICC |
| **STG-03** | `proposal` | Proposal / Price Quote | 60% | Formal Quote generated from Product Catalog / Price Books |
| **STG-04** | `negotiation` | Negotiation / Review | 80% | Commercial terms reviewed, discount approval granted |
| **STG-05** | `won` | Closed Won | 100% | Sales Order generated -> Invoice issued -> Customer Onboarding triggered |
| **STG-06** | `lost` | Closed Lost | 0% | Loss reason recorded (price, competitor, timing) -> Nurture campaign triggered |

---

## 3. Database Schema Alignment & Mapping

Both tables remain in place to prevent breaking existing database foreign keys and dependencies, but are connected via explicit twin identifiers:
- `crm_opportunities.converted_from_gtm_deal_id` (Points to `gtm_deals.id`)
- `gtm_deals.crm_opportunity_id` (Points to `crm_opportunities.id`)

### 3.1 Field-by-Field Mapping Matrix

| Business Concept | `crm_opportunities` Column | `gtm_deals` Column | Sync Direction | Transformation Rule |
|---|---|---|---|---|
| Tenant ID | `sub_institute_id` | `sub_institute_id` | Immutable | Must match caller's identity |
| Deal Name | `name` | `name` | Bi-directional | String 255 |
| Primary Account | `organization_id` | `account_id` | Bi-directional | Resolved via `gtm_accounts.crm_organization_id` |
| Deal Value | `amount` | `amount` | Bi-directional | Decimal(15, 2) |
| Currency | `currency` | `currency` | Bi-directional | Standard ISO code (INR, USD, EUR) |
| Pipeline Stage | `sales_stage` | `stage` | Bi-directional | Mapped via Section 2.1 Stage Mapping Matrix |
| Expected Close Date | `closing_date` | `expected_close_date` | Bi-directional | Date (Y-m-d) |
| Next Action | `next_step` | `next_step` | Bi-directional | Text string |
| Opportunity Number | `opportunity_no` | `deal_code` | Generated | Format: `OPP-XXXXXXXX` |
| Assigned Owner | `assigned_to` | `owner_user_id` | Bi-directional | User ID in `tbluserModel` |
| AI Health Flags | (Derived JSON) | `health_flags` | Read from GTM | Array of warning badges |

---

## 4. Implementation Logic in Laravel

### 4.1 Modifying `DealController::convertToCrm`
Remove the artificial `won`-only gate in `app/Http/Controllers/Api/Gtm/DealController.php`:
```php
// BEFORE:
if ($deal->stage !== 'won') {
    return response()->json(['status' => 0, 'message' => 'Only a won deal can be converted to a CRM Opportunity.'], 422);
}

// AFTER:
// Any active deal in discovery, qualification, proposal, negotiation, or won can be synchronized.
```

### 4.2 Automated Twin Provisioning via Observer
Implement `App\Observers\CrmOpportunityObserver`:
```php
namespace App\Observers;

use App\Domain\Crm\Sales\OpportunityEvents;
use App\Domain\Gtm\GtmDeal;
use Illuminate\Support\Facades\DB;

class CrmOpportunityObserver
{
    public function created($opportunity): void
    {
        // If created directly in CRM and not already linked to GTM
        if (! $opportunity->converted_from_gtm_deal_id) {
            $dealId = DB::table('gtm_deals')->insertGetId([
                'sub_institute_id'    => $opportunity->sub_institute_id,
                'name'                => $opportunity->name,
                'amount'              => $opportunity->amount,
                'currency'            => $opportunity->currency ?? 'INR',
                'stage'               => $this->mapCrmStageToGtm($opportunity->sales_stage),
                'expected_close_date' => $opportunity->closing_date,
                'next_step'           => $opportunity->next_step,
                'owner_user_id'       => $opportunity->assigned_to,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            DB::table('crm_opportunities')
                ->where('id', $opportunity->id)
                ->update(['converted_from_gtm_deal_id' => $dealId]);
        }
    }

    public function updated($opportunity): void
    {
        if ($opportunity->converted_from_gtm_deal_id) {
            DB::table('gtm_deals')
                ->where('id', $opportunity->converted_from_gtm_deal_id)
                ->update([
                    'name'                => $opportunity->name,
                    'amount'              => $opportunity->amount,
                    'stage'               => $this->mapCrmStageToGtm($opportunity->sales_stage),
                    'expected_close_date' => $opportunity->closing_date,
                    'next_step'           => $opportunity->next_step,
                    'updated_at'          => now(),
                ]);
        }
    }

    private function mapCrmStageToGtm(?string $stage): string
    {
        return match (strtolower(trim((string) $stage))) {
            'closed won' => 'won',
            'closed lost' => 'lost',
            'proposal/price quote', 'proposal' => 'proposal',
            'negotiation/review', 'negotiation' => 'negotiation',
            'qualification in progress', 'qualification' => 'qualification',
            default => 'discovery',
        };
    }
}
```

---

## 5. Order-to-Cash Workflow Verification Gate

When a deal reaches `proposal` stage:
1. A salesperson opens the Opportunity in `components/domain/crm/opportunity-list-view.tsx`.
2. The user clicks **"Create Quote"**.
3. The system pulls line items from `vtiger_products` and `vtiger_pricebook`.
4. When the customer accepts the quote and the deal is marked **"Closed Won"**:
   - Status updates in both `crm_opportunities` and `gtm_deals`.
   - A Sales Order (`crm_salesorders`) is provisioned with 1 click.
   - An Invoice (`crm_invoices`) is generated with payment tracking.
   - The deal is registered in `gtm_customers` (Customer Success & Retention) to monitor account health and future renewals.

---

## 6. Safety & Rollback Guarantees
- **No Direct Destructive DB Operations:** The bridge relies on existing columns (`converted_from_gtm_deal_id`, `sub_institute_id`).
- **Loop Prevention:** Sync triggers check `isClean()` and use idempotency tokens to ensure an update on CRM does not trigger an infinite ping-pong loop back from GTM.
- **Strict Multi-Tenancy:** Every query and insert explicitly filters by `sub_institute_id` retrieved via `ResolvesApiIdentity`.

