# S15 design pass, 23 Nov 2026 (night): the list reads as sections, every page is full width

Tags: verified = a command ran and its output was read. Dev database, organisation 6; nothing on live; nothing pushed.

## What the owner asked (with screenshots of the live site)
1. The Product / Sub-product list "looks confusing, clumsy, mixed, no separation": improve it.
2. "Some pages do not have the full width like all our modules": make them full width.
He also asked for the best Claude Code skills and web research for the design. `/deep-research` is reserved for the owner to run himself (the Skill tool refuses it), so no web research was done by me; the choices below come from the screenshots, the measured pages and established table conventions. A prompt for him to paste into `/deep-research` is at the end.

## What the screenshots showed besides looks (verified on the pages)
- Clicking **Commercial Bundle** (and Higher Education) opened "Product not found": the Product page looked the Product up in Signals only, and these two built-in Products exist only in the Portfolio. Fixed: they open a page of their own (their Sub-products).
- The Product page said **"1 sub-product"** in the header and **6** on the tab: the tab counted archived Sub-products too. Fixed: one number (the active ones) on both, the archived ones named apart ("1 sub-product · 5 archived"); the tab still lists them so they can be restored.

## Width, measured in a real browser (width of the page content, px)
| Page | 1440 before | 1440 after | 1920 before | 1920 after | 390 after |
|---|---|---|---|---|---|
| Portfolio list | 1320 | 1320 | 1800 | 1800 | 342 |
| Product page (Enterprise Brain) | **1152** | 1320 | **1152** | 1800 | 342 |
| Product page of Commercial Bundle | "not found", 1152 | 1320 | "not found", 1152 | 1800 | 342 |
| Signals | **1152** | 1319 | **1152** | 1799 | 341 |
| Sub-product page (by the 1920 screenshot, not a number: it has no page-frame wrapper) | full | full | full | full | full |
Logs: `widths-before.log`, `widths-after.log`. Cause: `mx-auto max-w-6xl` on the Product page's four frames and on `SignalsFrame`, and `max-w-5xl` on the standalone Scholar signals view. They are now `w-full`. `lib/portfolio-list/page-width.test.ts` fails if any page frame of these areas centres itself in a fixed column again.

## The list (screenshots `after-list-1440.png`, `after-list-390.png`)
- Each **Product is a section band**: solid muted background, a 4 px accent on its left edge, a heavier rule above it, more padding; its name and labels on the left, its count and its one action on the right.
- Each **Sub-product is a row under it**, indented with an elbow line before the name.
- **No Type column**: every row said "Sub-product"; only a **Service** is labelled (a chip beside its name).
- **One heading style**: the sort buttons used to be bold sentence case while the other headings were small capitals; now all are the same and the sorted-by heading is darker with the arrow.
- **Quiet empty cells**: a Sub-product that is not sold draws nothing in SKU, Price, Category and In stock (its "Not sold" chip says why); a dash is kept only where a SOLD Sub-product has a real gap.
- Signals: 0 is muted, a count above 0 is darker. The search box keeps a readable width and the six filters stay on one line at 1440 px; the Name column has a minimum width so names do not wrap to three lines on a phone.

## Proofs (verified)
- Whole front-end suite 3,740 pass (3,730 before this pass); `tsc` exit 0; eslint `--max-warnings=0` exit 0 on the changed files; `components/ui` and `components/shell` untouched.
- 9 mutations of my own (`design-mutations-runner.sh.txt`), each fails a named test, each file restored byte for byte: section band, elbow, Service chip, quiet cells (this one SURVIVED the first time: the test had no row that carries empty Sell keys; a row was added and it is killed now), heading style, active-only count, master-only Product page, and the width rule on the Product page and on `SignalsFrame`.
- axe at 390 px on the list and on the Commercial Bundle page: only the two shell findings already reported (`aria-hidden-focus` on the mobile sidebar `<aside>`, `button-name` on the user-menu button); nothing from this work.

## Not done / for the owner
- The Product page's own Sub-products tab still has a Type column that says "Sub-product" on every row (the same noise); not changed, one more small pass if wanted.
- The Signals module's own pages other than the frame were not redesigned: only their width changed.
- Not pushed. The owner's word is needed.

## A prompt to paste into `/deep-research` (the owner runs it)
"Design research for a B2B ERP/CRM Product Portfolio list (React, Tailwind, shadcn-style table). What do the best design systems and UX research (Material, Carbon, Polaris, Atlassian, GOV.UK, Nielsen Norman Group, Baymard, Salesforce Lightning, W3C ARIA APG) recommend for: (1) a grouped table where a Product group header holds many Sub-product rows: visual separation, indentation and tree connectors, sticky and collapsible group headers, which columns to drop or hide, how to show an empty cell, header typography, density at 1280 to 1920 px and at 390 px; (2) full-width versus max-width page layouts for admin module pages, consistent across list, detail, empty and not-found states; (3) accessibility of grouped tables (row groups, treegrid versus table, contrast). Return concrete, citable rules with URLs that I can turn into exact Tailwind changes."
