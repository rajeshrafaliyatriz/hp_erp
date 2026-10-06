# X-01 (item 4b) — POPULATE THE RIGHTS MATRIX · **REVIEW GATE, NOT APPLIED**

**Stopped before applying, as instructed.** Nothing has been written to
`tblgroupwise_rights_g2g`.

---

## 1. Backup — done first, before anything else

| | |
|---|---|
| `_changes/X-01-backup-tblgroupwise_rights_g2g-2026-08-10.sql` | **4,879 rows**, full INSERT dump, 1,703,247 bytes |
| `_changes/X-01-restore.sql` | `DELETE` + source-the-backup, in a transaction |
| `_changes/X-01-backup-rights.php` | re-runnable generator |

Same shape as the G-NAV-01 template.

---

## 2. ⛔ ADMIN LOCKOUT — asserted, and the answer is SAFE

| Check | Result |
|---|---|
| `Role & Permissions` (menu **23**) exists | ✅ yes |
| Admin profiles with `can_view=1` on it today | **11 of 11** |
| What §3.1 specifies for Admin on that row | **`V C E D`** |
| **Lockout possible?** | **NO.** The seed *grants* Admin view/create/edit/delete on it |

**One thing the check surfaced:** *Group-wise rights* and *Individual rights* have
**no rows in `tblmenumaster_g2g` at all**. §3.1 marks both *(SHIP)* — they are
screens to be **built**, not existing ones. So there is nothing to grant or revoke
for them yet, and no lockout path through them either.

---

## 3. ⛔ THE BLOCKER — the seed specifies 8 roles; the system has 3

**This is why I stopped, and it is not a judgement I should make alone.**

| §3.1–3.7 columns (8) | Profiles that exist, with users |
|---|---|
| Employee · **Reporting Mgr** · **Dept Head** · **HR Exec** · **HR Mgr** · Admin · **Executive** · **Auditor** | **Employee** (238 users) · **HR** (72) · **Admin** (76) |

**Five of the eight roles do not exist.** `Reporting Manager` and `Department Head`
were approved by **Q-B1 as a prerequisite**, and the reporting line that makes them
meaningful is **build item 5** — the next item, not this one.

### Why this blocks rather than merely complicates

Applying §3.1–3.7 today means **collapsing eight columns into three profiles**.
Two map cleanly:

| Seed column | Profile | Mapping |
|---|---|---|
| Employee | `Employee` | ✅ unambiguous |
| Admin | `Admin` | ✅ unambiguous |
| **HR Exec** *or* **HR Mgr** | `HR` | ❌ **ambiguous — they differ on every module** |

`HR Exec` is scoped to a department; `HR Mgr` is organisation-wide with delete
rights. **Choosing between them for the single `HR` profile is re-deriving a
permission**, and the instruction was explicit that the permissions are decided and
must not be re-derived.

**I am not guessing which one HR means.**

### Second blocker — no screen→menu mapping exists

§3.1–3.7 names screens (*"Employee Directory"*, *"Framework & Role Mapping"*).
The rights table keys on `menu_id`. **The mapping between them does not exist as an
artefact**, and building it is a task in its own right — 157 distinct menus in the
rights table against 188 in the master, and the §3.x screen names do not match
menu names one-to-one.

---

## 4. The diff I can give you today — current state, corrected

**⚠️ A correction to how G-SEC-07 has been quoted.** *"Employee sees 1,657 menus vs
Admin 1,500"* is an **aggregate across 11 tenants**, not what one user sees.

| Role | Rows | Profiles | `can_view=1` | **Per profile — what ONE user sees** |
|---|---:|---:|---:|---:|
| **Employee** | 1,657 | 11 | 1,657 | **151** |
| **HR** | 1,650 | 11 | 1,650 | **150** |
| **Admin** | 1,500 | 11 | 1,500 | **136** |
| ZZ Audit Role v2 | 72 | 1 | 72 | 72 |

**The inversion is real and survives the correction: an Admin sees 136 screens and
an Employee sees 151.** But the honest per-user figures are 151/150/136, not
1,657/1,650/1,500 — and those are the numbers to quote.

**`can_view=1` on every single row**, for all three roles. The matrix carries no
information at all, exactly as G-SEC-07 records.

**31 menus (188 − 157) have no rights rows whatsoever.**

---

## 5. What I recommend

**Do item 5 (`reporting_manager_id` + `head_user_id`) before 4b**, and swap their
order. Reasons:

1. It **creates the role model** §3.1–3.7 is written against, so the seed can be applied as decided rather than collapsed by guesswork.
2. It is **schema-only and invisible**, so it carries none of 4b's risk.
3. 4b then becomes a **faithful application of a decided matrix**, which is what it was meant to be.

**Two decisions needed from you before 4b can proceed:**

| # | Decision |
|---:|---|
| 1 | **Does the existing `HR` profile mean HR Exec or HR Mgr?** — or should both be created, with existing HR users assigned to one? |
| 2 | **Is the screen→menu mapping a deliverable I should build**, and if so, does it get reviewed before the seed uses it? |

---

## 6. R9 — when this becomes visible

**The moment it is applied.** `MenuMiddleware` reads `can_view` at request time
from `tblgroupwise_rights_g2g`; the sidebar is built per request. **There is no
cache to warm and no gradual rollout** — the next page load after the write shows
the new menu.

That is the single strongest argument for the per-role diff being reviewed *before*
the write, not after.

---

**STATUS: NOT APPLIED. Awaiting the two decisions above.**
