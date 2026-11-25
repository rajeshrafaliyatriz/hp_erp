# Live import: researched signals of 2026-11-24 (G2G and ScholarClone)

**What this is.** 9 real, dated signals with their evidence links, researched on the public web by Claude on 24 Nov 2026. They were NOT produced by your Tavily or DeepSeek, and loading them uses neither. Every quote was re-checked against the raw page text (8 of 9 confirmed that way; the PSG Clifton page timed out on the re-check, but did confirm on the first check). Nothing here has been written to live: you run the commands below yourself.

**Honest limits.** Only 9 signals survived the quality bar, not 30: G2G partner 3, G2G customer 2, ScholarClone school 1, ScholarClone partner 3, and **no verifiable open government tender** (G2G government 0, ScholarClone government 0: GeM, CPPP and state portals cannot be searched or read from the public web). A signal shows a real event that gives a reason to talk; none of them proves the organisation will buy.

## The signals

| Product | Lane | Organisation | Event date | Claim / how read | Why it is a signal | Evidence link |
|---|---|---|---|---|---|---|
| G2G | Customer (b2b) | Vasudha Pharma Chem Ltd | 2026-11-18 | confirmed / page_text | Hyderabad API manufacturer appointed a new CHRO (effective November 2026) with a governance remit, a typical moment to review HR systems, org structur | https://www.sightsinplus.com/news/appointments/vasudha-pharma-appoints-dakshina-murthy-ch-as-chro/ |
| G2G | Customer (b2b) | TMC Transformers India Limited | 2026-11-17 | confirmed / page_text | Manufacturer had a foundation stone laid for a new 78,000 MVA transformer plant in Panchmahal, Gujarat, implying new operating location, hiring and ro | https://www.newkerala.com/news/a/gujarat-cm-lays-foundation-new-transformer-plant-eyes-567.htm |
| G2G | Partner (b2c) | Keystone | 2026-09-25 | confirmed / page_text | A new India practice is being staffed, creating a need for workforce and capability tools. | https://www.keystone.com/news-publications/keystone-to-launch-india-expanding-its-global-platform-for-technology-regulatory-and-ai-advisory-services |
| G2G | Partner (b2c) | Purple People International | 2026-11-20 | hypothesis / page_text | PPI now has to deliver workforce capability programmes across industry, government and education in India. | https://www.passionateinmarketing.com/queenslands-australasian-leadership-academy-partners-with-purple-people-international-to-bring-australian-standard-workforce-training-to-india/ |
| G2G | Partner (b2c) | Tata STRIVE | 2026-10-30 | hypothesis / page_text | A new centre with a defined role-based curriculum needs structured competency and assessment content. | https://themachinemaker.com/news/skf-india-industrial-opens-first-seed-centre-in-pune-to-train-manufacturing-workforce/ |
| ScholarClone | School (b2b) | Lighthouse Learning Group / PSG Clifton International School (formerly Pathways School Gurgaon) | 2026-10-12 | confirmed / page_text | Lighthouse Learning (EuroKids/EuroSchool group) took ownership of an IB school and rebranded it, which typically triggers systems consolidation into t | https://theprint.in/ani-press-releases/lighthouse-learning-assumes-ownership-of-pathways-school-gurgaon-renames-the-institution-psg-clifton-international-school/3069510/ |
| ScholarClone | Partner (b2c) | ARISE (Association for Reinventing School Education) | 2026-10-06 | inference / page_text | ARISE, the association of India's private K-12 schools, appointed a category-exclusive learning partner for its member network, showing it runs a stru | https://www.outlookbusiness.com/spotlight/news-wire/burlington-english-india-partners-with-arise-to-expand-english-learning-across-k-12-schools |
| ScholarClone | Partner (b2c) | Lighthouse Learning (formerly EuroKids International) | 2026-11-14 | confirmed / page_text | School-chain franchisor Lighthouse Learning added Meru International School, Hyderabad, to its partner school network, offering shared central academi | https://www.uniindia.com/business-wire-india/lighthouse-learning-expands-hyderabad-footprint-with-strategic-partnership-with-meru-international-s/244897 |
| ScholarClone | Partner (b2c) | Navneet Education Limited | 2026-10-15 | inference / page_text | Navneet, a curriculum and content provider, announced a push to grow its CBSE business 30% year on year across a base of 40,000+ schools, making it a  | https://www.uniindia.com/business-wire-india/navneet-education-expands-its-cbse-portfolio-accelerates-ai-innovation-in-classroom-education-102222/214397 |

Left out on purpose: KPMG in India (already in your live data, and its page cannot be read by our reader), DEV Information Technology (fit only inferred), SHRM India (search snippet only), Schoolcanvas (a competing school ERP).

## Files

All in the project, tracked by git once you commit them:

- `database/signal-imports/2026-11-24/g2g-b2b.json` (2)
- `database/signal-imports/2026-11-24/g2g-b2c.json` (3)
- `database/signal-imports/2026-11-24/scholarclone-b2b.json` (1)
- `database/signal-imports/2026-11-24/scholarclone-b2c.json` (3)

## Before you run anything on the live server

1. Deploy the code that includes the small change to `app/Console/Commands/ImportMarketSignals.php` (the optional `--customer-model` option). Without it the commands below stop with "option does not exist" and write nothing.
2. Make sure a queue worker is running on live (it was not, earlier). Importing does not need it, but your screens' other features do.
3. Back up the signal tables first (read-only, writes .sql files): `php artisan signals:backup-tables`

## Commands (live server, from the application folder)

Step 1, dry run (validates every record, writes NOTHING):

```
php artisan signals:import-market database/signal-imports/2026-11-24/g2g-b2b.json --tenant=6 --report-date=today --product=g2g --customer-model=b2b --dry-run
php artisan signals:import-market database/signal-imports/2026-11-24/g2g-b2c.json --tenant=6 --report-date=today --product=g2g --customer-model=b2c --dry-run
php artisan signals:import-market database/signal-imports/2026-11-24/scholarclone-b2b.json --tenant=6 --report-date=today --product=scholarclone --customer-model=b2b --dry-run
php artisan signals:import-market database/signal-imports/2026-11-24/scholarclone-b2c.json --tenant=6 --report-date=today --product=scholarclone --customer-model=b2c --dry-run

```

Expected: `3 received, 3 accepted` for g2g-b2c, `2 received, 2 accepted` for g2g-b2b, `1 received, 1 accepted` for scholarclone-b2b, `3 received, 3 accepted` for scholarclone-b2c.

Step 2, the real import (only if the dry run matches):

```
php artisan signals:import-market database/signal-imports/2026-11-24/g2g-b2b.json --tenant=6 --report-date=today --product=g2g --customer-model=b2b
php artisan signals:import-market database/signal-imports/2026-11-24/g2g-b2c.json --tenant=6 --report-date=today --product=g2g --customer-model=b2c
php artisan signals:import-market database/signal-imports/2026-11-24/scholarclone-b2b.json --tenant=6 --report-date=today --product=scholarclone --customer-model=b2b
php artisan signals:import-market database/signal-imports/2026-11-24/scholarclone-b2c.json --tenant=6 --report-date=today --product=scholarclone --customer-model=b2c

```

`--tenant=6` is the organisation your live signals are in. `--report-date=today` makes the signals appear under today's date in the Research date list.

Step 3, check (SQL, read-only):

```
SELECT id, product_key, customer_model, qualification, claim_level, source_url FROM g2g_company_opportunities WHERE sub_institute_id=6 AND import_source='claude-research-2026-11-24' ORDER BY id;
```

You should see 9 rows. Open Signals, pick today's date, and each lane shows its signals with the evidence link.

## Undo (only if you want it)

The import has no automatic undo. Every signal carries `import_source = 'claude-research-2026-11-24'`. First run the SELECT above to see exactly what would go, then:

```
DELETE FROM g2g_opportunity_events  WHERE opportunity_id IN (SELECT id FROM (SELECT id FROM g2g_company_opportunities WHERE sub_institute_id=6 AND import_source='claude-research-2026-11-24') x);
DELETE FROM g2g_opportunity_matches WHERE signal_type='opportunity' AND signal_id IN (SELECT id FROM (SELECT id FROM g2g_company_opportunities WHERE sub_institute_id=6 AND import_source='claude-research-2026-11-24') x);
DELETE FROM g2g_company_opportunities WHERE sub_institute_id=6 AND import_source='claude-research-2026-11-24';
```

The dated runs the command created (status text starts with "Market import:") and the organisations it added stay behind; they are harmless and can be removed by hand.
