# Sprint 4b — the offer decision, and keeping the candidate

Two things: a candidate can now answer their own offer with no login, and a candidate is now a person
the organisation keeps rather than a row per application.

## Proven end to end

```
HR mints the link     POST /api/talent-offers/{id}/candidate-link   (profile:admin,hr,recruiter)
                        -> emailed to the candidate (tenant 6), URL returned either way
Candidate opens it    GET  /api/offer-response/{token}   200, NO token, NO login
Candidate declines    POST .../{token}  {"decision":"declined"}
                        -> acceptance decision=declined via=candidate, token burnt
                        -> offer.status  sent -> rejected
                        -> application   Hired -> Rejected
Candidate accepts     (a different offer)
                        -> employee 2577 created, acceptance via=candidate
```

| Check | Result |
|---|---|
| Link opened with no credentials | **200** |
| Unknown 64-char token | **410** |
| Malformed token | **404** (route constraint) |
| Reusing a burnt link | **410**, "This link has already been used…" |
| Email actually sent (tenant 6) | **yes** — real SMTP |
| Accept from the link | employee created, `decided_via = candidate` |

## The token

`Str::random(64)` — CSPRNG. Only the **sha256 hash** is stored, so a leaked database row is not
redeemable. `token_expires_at` is written **and checked at redemption**. Single use is
`token_used_at` — a marker, not a delete, so the record of who answered and when survives. One live
token per offer: re-issuing overwrites the hash, so an older link stops working immediately.

Neither existing precedent was copied as-is, deliberately:

| | `signupOtpController` | `password_reset_tokens` | here |
|---|---|---|---|
| generation | `rand()`, 10,000 values | `Str::random(64)` | `Str::random(64)` |
| storage | plaintext | plaintext | **sha256 hash** |
| expiry | set, checked | set, **never checked** | set **and checked** |
| single use | **never enforced** | by deleting the row | marked, row kept |
| throttle | none | none | 20/min read, 10/min write |

Every failure — unknown, expired, used — returns the same **410**, so the response cannot be used to
probe which tokens exist. The reason is carried for the page to show a person, not for a machine to
branch on.

**Found while reading the precedent:** `App\Models\SignupOtp` **does not exist**, so
`POST /api/send-otp` and `/verify-otp` fatal on their first Eloquent call. Out of scope here, but it
should be on somebody's list.

## Acceptance now has one implementation

HR recording the answer and a candidate answering their own link were about to be two code paths
doing the same thing. Sprint 2's logic moved into `App\Services\Talent\OfferAcceptanceService` and
**both** controllers call it — the same rule that produced `EmployeeFactory`. Idempotency is
unchanged and still verified: an already-accepted offer returns the employee it created, and an
existing employee for that address is adopted rather than duplicated.

`decline()` is new and does the symmetric thing: records the decision, moves the offer to `rejected`
and the application to `Rejected`, so a declined candidate stops showing as live in the pipeline.

## Candidates are now kept as people

`talent_candidates`, on both databases, one row per person per organisation.

- **Keyed on `(sub_institute_id, sha256(lower(email)))`.** The natural key would be
  `(sub_institute_id, email)`, but `email` is `varchar(255)` and that index would be 1028 bytes —
  over live's 767-byte cap, failing on live while passing on dev. The address is stored in full and
  unindexed; a fixed `char(64)` hash carries the index at 264 bytes.
- **`talent_job_applications.candidate_id`** links each application to the person.
- **Backfill:** all **280** existing applications were folded in, and it immediately found
  **57 repeat applicants** who were sitting in the data as unrelated rows.

**Consent is a column, not an assumption.** Retaining a CV for roles someone has not applied for is a
different thing from processing their application, so the public form asks separately and it defaults
to **off**. `consent_to_retain` + `consent_at` record the answer. A candidate who does not consent
still gets a record — the application has to work — but is flagged so they can be excluded from any
future-role search or purged. Every backfilled row is `consent_to_retain = 0`, because nobody who
applied before this existed was ever asked.

Consent is only ever **raised**, never lowered: someone who said yes last time has not withdrawn it by
leaving the box unticked this time. Withdrawal is a deliberate act and belongs on its own path.

## Mail, and the tripwire

`MailGate::allowedForTenant()` + `G2G_NOTIFY_EMAIL_TENANTS=6`. The relationship is deliberately
**OR**, not AND — with the master flag off, an AND would be permanently false and useless.

That means mail can leave for tenant 6 while `MailGate::allowed()` still reports false, which is
exactly the drift that class exists to prevent. **So the tripwire was amended in the same change**
(`Docs/phase3/_evidence/phase3-smoke.php`): it now asserts both switches and FAILs if the allowlist
holds anything other than the organisations written down in it.

```
PASS  G2G_NOTIFY_EMAIL unset/false; allowlist = 6 (expected)
```

A failed send never loses the link — HR gets the URL regardless and is told plainly whether it was
emailed, following `issueInvite()`'s shape.

## Gates

```
tsc 7 (baseline 7)   build exit 0   eslint 101 (baseline)   components/ui empty
routes 1856 (+3: offer-response GET/POST, candidate-link)
migration applied to BOTH databases, schemas verified identical (26 cols, 4 indexes, longest id 25 chars)
```

**One correction:** eslint briefly went to 103. Both new errors were `setState` called synchronously
inside `useEffect` in the **Sprint 4a** careers pages — Sprint 4a's gate ran `tsc` and `build` but not
`eslint`, so I missed them. Fixed here; back to 101.

## Test rows created

- Candidate "Nikhil Shah" + application 970 (public form, consent given).
- Offer 5 declined via link; offer 29 accepted via link → employee **2577**.

## How to demo

1. Recruitment → Offers → row menu → **Send / copy candidate link**. The URL is copied and shown, and
   for tenant 6 it is emailed for real.
2. Open it in an **incognito window** — no session at all. The offer is there; accept or decline.
3. Back in Recruitment the badge has moved, and an acceptance shows the new employee in the Directory.
4. Re-open the same link — it is spent.
