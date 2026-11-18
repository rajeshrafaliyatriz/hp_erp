# S2 add-on — AI prices in rupees (contract, 3 Nov 2026)

Owner, 3 Nov: "default money is rupees; the organisation will supply (prices) and the developer company; choose the option yourself, do not show laziness." Today every AI model's price is empty on dev and live, so a run's cost shows a dash.

## Facts that shaped it (verified 3 Nov)

- The platform already has a **rate card**: `ai_models.input_cost_per_1k` / `output_cost_per_1k`, a **platform default row** (`sub_institute_id` NULL: the developer company's) and an **organisation row that wins** (`AiUsageMeter::cost()`, `ModelCatalog`). Every rate is NULL on both hosts. The rate has **no currency**, and the usage table's cost column is named `estimated_cost_usd`.
- The model manager screen (`g2gv0 app/ai/_components/ModelManager.tsx`) has **no rate inputs**; platform rows are read-only there; the API (`AiConfigurationController`) already validates the two rates.
- `AiUsageMeter::cost()` takes the first row in precedence order **even when its rates are NULL**, so an organisation row with blank rates would hide a filled platform default.
- Signals uses `deepseek-flash` on live (114 calls, 956,607 input and 154,346 output tokens). DeepSeek's official pricing page (api-docs.deepseek.com/quick_start/pricing, September 2026 cut): `deepseek-flash` $0.30 per million input tokens (cache miss) and $1.20 per million output at **peak**; half of that off-peak. Exchange rate used for the default: about ₹96 per US dollar (a July 2026 figure; the live rate could not be found, so it is a stated assumption, not a fact about today).

## Decision (the assistant's, owner delegated it)

**A currency-aware rate card with two suppliers, priced at the moment of each call.**

1. Rates stay per 1,000 tokens in `ai_models`, and each row gets a **currency** (default **INR**). The **developer company** fills the platform default rows (a command, because platform rows are read-only in the UI); an **organisation** fills its own row in the model manager (new inputs, entered **per million tokens**, which is how vendors publish prices, converted to per-1k). The organisation's own row wins, but **only when it has both rates**: a blank organisation row never hides the platform default.
2. Every AI call is priced **when it is made** (`AiUsageMeter`), in the rate's own currency, into two new columns on `ai_usage_events`: `estimated_cost` and `cost_currency`. `estimated_cost_usd` is still written, **only when the currency is USD** (nothing else reads a rupee price as dollars). A call made before a price existed stays unpriced: its cost is unknown, never a guess.
3. A run's cost is the **sum of its calls' `estimated_cost`** (`RunCost`), in one currency. Any call that used tokens and has no price, or calls in two currencies, make the run's cost **unknown (NULL)**; a run that used no AI is 0. The Runs tab shows ₹ (en-IN grouping) or $ by the run's currency; unknown stays a dash.
4. **Default price (developer company), dev only until the owner's go for live:** `deepseek-flash` at the peak rate, ₹28.80 per million input and ₹115.20 per million output (0.30 × 96 and 1.20 × 96). Peak, not off-peak, so the estimate is never lower than the bill; cache-hit pricing is ignored for the same reason. An estimate, editable, with its sources in the evidence file.

## Database (all additive, guarded, one file each; dev now, live only on the owner's go)

| File | Change |
|---|---|
| `2026_12_08_100000_add_currency_to_ai_models` | `ai_models.currency` CHAR(3) NOT NULL DEFAULT 'INR' |
| `2026_12_08_100100_add_cost_columns_to_ai_usage_events` | `estimated_cost` DECIMAL(14,6) NULL, `cost_currency` CHAR(3) NULL |
| `2026_12_08_100200_add_ai_cost_to_g2g_research_runs` | `ai_cost` DECIMAL(14,4) NULL, `ai_cost_currency` CHAR(3) NULL. `ai_cost_usd` (migration `…090200`) stays and is simply no longer written (dropping is not additive) |

All three tables exist on live MariaDB 10.1 (no JSON, no `->change()`); code must work **before** the migrations have run on a host (guard every new column with `ColumnExists::has`, cached per process in the meter because it sits on every AI call).

## Code contract

- `AiUsageMeter`: price = first row **with both rates** in precedence (organisation, then platform); the row's `currency` (when the column exists, else USD as before); write `estimated_cost`, `cost_currency` when those columns exist; write `estimated_cost_usd` only when the currency is USD. Refused calls cost 0 as before. A meter failure still never costs the caller its answer.
- `RunCost::fill`: as in point 3; writes `ai_cost`, `ai_cost_currency` (the currency, or the platform default when the run used no AI and cost is 0); no longer writes `ai_cost_usd`.
- `OpportunityController::presentRun` adds `ai_cost` (number or null) and `ai_cost_currency` (string or null); `ai_cost_usd` is no longer sent.
- `AiConfigurationController` and `ModelCatalog`: accept and return `currency` (one of `config('ai.currencies')`, default INR) beside the two rates.
- Command `ai:set-model-rate --provider= --model= --input= --output= [--per=1000000] [--currency=INR] [--tenant=] [--apply] [--database=] [--overwrite]`: dry run by default; sets the platform row (no `--tenant`) or one organisation's row; **updates an existing row only** (never creates one); converts per-million (or `--per=1000`) to per-1k; refuses to replace a non-empty rate without `--overwrite`; prints before and after.
- Front end: Runs panel shows money by currency (`formatMoney(amount, currency)`, en-IN grouping for INR), sums per currency, "no cost recorded" counts as before; the model manager gets Currency, Input price and Output price (per million tokens) for the organisation's own rows, shows platform rows' rates read-only, and says plainly which price applies.
