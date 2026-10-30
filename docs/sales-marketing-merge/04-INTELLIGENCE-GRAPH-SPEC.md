# Intelligence & Knowledge Graph Specification: Social Listening & Revenue Graph

> **Target Repositories:** `hp_erp` (Backend) & `g2gv0` (Frontend)  
> **Scope:** Architecture for Social Listening (Stage 2) & Revenue Knowledge Graph (Stage 9)  
> **Date:** 2026-10-29  
> **Status:** Engineering Blueprint & Implementation Specification  

---

## 1. Social Listening Architecture (Stage 2: Market Signals & Research)

### 1.1 Architectural Principle
Social Listening in G2G is not merely a social media manager or sentiment dashboard. It is an **Inbound Intent Capture & Signal Mining Engine** designed to detect buying intent, corporate expansions, executive changes, and competitor dissatisfaction before prospects enter a traditional sales funnel.

### 1.2 Integration with Existing Signals Engine
`hp_erp` already possesses a sophisticated signals pipeline in `app/Domain/Signals`:
- Search Providers: `BraveSearchProvider`, `TavilySearchProvider`, `SafeUrlFetcher`
- Intelligence Analyzers: `BuyingIntent.php`, `EvidenceGrader.php`, `BuyerResolver.php`
- Quality Gates: `QualificationGate.php`, `SignalStrength.php`

Social Listening extends this by adding `SocialSourceReader` into `app/Domain/Signals/Sources/`:

```
+-------------------------------------------------------------------------------------------------+
| 1. MONITORING LAYER (Configured in Settings / Playbooks)                                        |
| Keywords: Competitor Names, Product Category Pain Points, RFP / Vendor Inquiries               |
+-------------------------------------------------------------------------------------------------+
                                               |
                                               v
+-------------------------------------------------------------------------------------------------+
| 2. INGESTION & SCRAPING ENGINE (SafeUrlFetcher & SearchProviderFactory)                         |
| Supported: Public Web Mentions, LinkedIn Company Updates, Industry Forums, Review Sites         |
+-------------------------------------------------------------------------------------------------+
                                               |
                                               v
+-------------------------------------------------------------------------------------------------+
| 3. CLASSIFICATION & INTENT GRADING (BuyingIntent.php & EvidenceGrader.php)                      |
| - Intent Classification: 'vendor_search' | 'pain_point' | 'budget_allocated' | 'competitor_dissat'  |
| - Score: 0 to 100 based on keyword urgency, author seniority, and account ICP fit               |
+-------------------------------------------------------------------------------------------------+
                                               |
                                               v
+-------------------------------------------------------------------------------------------------+
| 4. CRM & ACCOUNT RESOLUTION (BuyerResolver.php & OfficialSiteDiscovery.php)                     |
| - Matches company to existing crm_organizations (or creates unverified account)                |
| - Identifies relevant decision makers                                                           |
+-------------------------------------------------------------------------------------------------+
                                               |
                                               v
+-------------------------------------------------------------------------------------------------+
| 5. ACTIONABLE HANDOFF (/signals/social-listening in g2gv0)                                      |
| - Action: [Promote to Lead] (Creates MQL in Stage 5)                                            |
| - Action: [Draft Personalized Outreach] (Sends context to Stage 4 Outreach Queue)               |
+-------------------------------------------------------------------------------------------------+
```

### 1.3 Data Model for Social Signals
A table `crm_social_signals` stores detected events:
- `id`: BigInt Primary Key
- `sub_institute_id`: Multi-tenant isolation key
- `channel`: 'linkedin' | 'x' | 'reddit' | 'news' | 'forum' | 'review'
- `author_name`: String
- `author_handle`: String
- `author_title`: String (e.g., 'VP of Engineering', 'Principal')
- `content_snippet`: Text
- `source_url`: String (512)
- `company_name`: String
- `organization_id`: Nullable foreign key to `crm_organizations`
- `intent_type`: 'vendor_search' | 'expansion' | 'dissatisfaction' | 'general'
- `intent_score`: Integer (0-100)
- `status`: 'new' | 'reviewed' | 'promoted_to_lead' | 'dismissed'

---

## 2. Revenue Knowledge Graph Architecture (Stage 9: AI Agents & Automation)

### 2.1 Why G2G Needs a Revenue Knowledge Graph
A relational database answers: *"What is the closing date of Opportunity #1042?"*  
The Revenue Knowledge Graph answers: *"Why should we approach Account X right now, which decision-makers have engaged with our campaigns, which partner has a warm connection to their board, and what past deal pattern guarantees the highest close rate?"*

### 2.2 Core Node & Edge Schema

```
  [Market Signal / Social Post]
                |
          (TRIGGERED)
                v
       [Target Organization] <======(EMPLOYED_AT)====== [Contact / Buyer]
                |                                              |
          (QUALIFIED_TO)                                  (ENGAGED_WITH)
                v                                              v
      [Opportunity / Deal]                           [Marketing Campaign]
                |
          (CONVERTED_TO)
                v
      [Sales Order / Invoice]
                |
          (RENEWAL_STAGE)
                v
   [Customer Success & Health]
```

#### Node Types (8 Canonical Entities):
1. **OrganizationNode:** Canonical company profile (`crm_organizations`).
2. **ContactNode:** Key decision maker (`crm_contacts`).
3. **SignalNode:** Real-time buying intent or social signal (`crm_social_signals`).
4. **CampaignNode:** Marketing initiative across Email, WhatsApp, Social (`gtm_campaigns`).
5. **LeadNode:** Inbound or enriched prospect (`crm_leads`).
6. **DealNode:** Active commercial negotiation (`crm_opportunities`).
7. **ProductNode:** Catalog offering or service line (`crm_products`).
8. **RetentionNode:** Active client health score and renewal schedule (`gtm_customers`).

#### Edge Types (Relationships):
- `EMPLOYED_AT` (Contact -> Organization)
- `ENGAGED_WITH` (Contact -> Campaign)
- `TOUCHED_BY` (Organization -> Signal)
- `GENERATED` (Campaign -> Lead)
- `CONVERTED_TO` (Lead -> Deal)
- `QUOTED_FOR` (Deal -> Product)
- `BILLED_AS` (Deal -> Invoice)
- `PROTECTED_BY` (Organization -> RetentionNode)

---

## 3. Knowledge Graph Explorer UI in `g2gv0`

Located at `/module/crm/knowledge-graph`:

### 3.1 Views Provided:
1. **Account 360 Graph:**
   - Visual network graph (rendered via SVG / Canvas / Force-directed layout).
   - Centered on an Organization, branching out to:
     - Subsidaries and parent groups
     - Active decision makers with engagement scores
     - Open Opportunities and active Quotes
     - Touchpoint history across Email, WhatsApp, and Events
2. **Relationship Path Finder:**
   - Visualizes how G2G can reach a prospect through partners, advisors, or existing customer referrals.
3. **AI Context Inspector:**
   - Explains the exact evidence chain behind an AI Agent's recommendation:
   - *"Recommended next action: Schedule Demo. Reason: Contact A attended Webinar B, Signal C detected ERP budget approval, Partner D provided warm introduction."*

---

## 4. Rollout Strategy & Verification
1. **Non-Destructive Storage:** The Knowledge Graph initially operates as a lightweight relational graph layer querying indexed foreign keys and JSON relationship tables in DB3 without requiring a separate dedicated Neo4j instance.
2. **Read-Only Inspection:** AI agents read from the graph layer to formulate outreach copy, ensuring high factual accuracy with zero hallucinations.

