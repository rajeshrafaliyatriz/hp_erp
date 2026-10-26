# Step 1: topics from tenant data (2026-10-26)

Tags: [verified] re-queried or re-read today; [inferred] my judgement or estimate; UNVERIFIED = not checked. Tenant 6 = the Scholar Clone account. No personal data is stored here: counts, ids, competency names and role names only.

## 1. How the data was re-queried

- The saved read-only harness from the earlier course build (`Downloads\g2g-step4-course-build\scripts`) was copied to the scratchpad and re-run, so that repository and its query log were not touched. Each session is READ ONLY, accepts only SELECT, SHOW, DESCRIBE or EXPLAIN, has a 30 s statement limit, reads credentials from `.env` at run time and never prints them, and selects no names, e-mail addresses or phone numbers.
- 57 queries on 128.199.17.97/hp_erp (live, MariaDB 10.1.48): 32 + 23 + 2, all answered, none failed.
- Disclosure: the saved harness's second round also contains cross-tenant baseline row counts (aggregate counts per tenant). Step 1 is "tenant 6 only"; those counts were not needed, were not used and are not recorded here.
- The raw outputs stay in the scratchpad, outside the repository, so that production data does not enter git.

## 2. Every saved number re-verified

Compared query by query with the saved 23 Oct results:

- **Identical [verified]: 37 of the 57 queries.** Round 1: 28 of 32 (the column lists of every table, the competencies, the departments, the **11 active people**, the **8 roles held**). Round 2: 7 of 23 (the required levels per role, the KASBA ratings per competency, the **gap roll-up** of below-bar, met and unmeasured people per competency, the request subjects, the role tasks, the task-to-competency map, and one baseline count). Round 3: both queries (the task groups).
- **Different, as expected after the 23 Oct course build [verified]:** the course list (19 old courses are gone; 4 exist), the dependents of those courses, the competency and role maps, enrolment and progress, the per-tenant baseline counts, the request statuses (all 3 requests are now `course_created`).
- Conclusion: the saved Need and Reach inputs are still true. Only the "has a live course" flag changed.

## 3. What tenant 6 looks like today [verified]

- **People:** 11 active: 2 Full Stack Developers, 2 Back End Developers, 2 AI/ML Engineers, 1 Front End Developer, 1 Operations and Support Manager, 1 Sales Executive, 1 Project Manager/Scrum Master, 1 Business Controller/Finance Director.
- **Courses (all published, one chapter and one lesson each):** 201 Back-End Design and Development (competency 442), 202 ML Pipeline Engineering (426), 203 Model Development and Evaluation (427), 204 Software Testing and Quality (444); each has a competency map, an assignment (optional, approved) and a 10-question paper.
- **Learning activity: only 1 of the 11 people has any enrolment or progress row** (one person, who is the owner according to your 23 Oct notes: 4 enrolments, 3 completed; 8 progress rows, 5 completed; 2 certificates). The other 10 have none. Seven further lessons of type `link` exist outside these courses (orphans).
- **Open requests:** 3, all `course_created` (201, 202, 203).
- **Consequence:** demand on paper is real, but nobody except the owner has used the learning flows. The pilot has to start by assigning courses to the people who need them.

## 4. Scores re-computed (0.45 × Need + 0.20 × Reach)

The saved 23 Oct table counted old live courses as "mapped" (factor 0.3 on Need). Today the old courses are gone and four new ones are mapped. So two rankings exist; the ranking for simulation planning is the one with **all competencies treated as unmapped** (a simulation adds practice to a course; it does not become unnecessary because a course exists).

| Rank | Topic | Score | Need | Reach | People (below bar) |
|---|---|---|---|---|---|
| 1 | C442 Back-End Design and Development | 44.8 | 83.3 | 36.4 | 4 (2) |
| 2 | TK4 Software maintenance, transition and administration | 43.6 | 92.9 | 9.1 | 1 |
| 3 | CW1 / CW2 / CW3 company-wide (secure software basics, CERT-In reporting, professional communication) | 42.5 | 50.0 | 100 | 11 |
| 6 | C426 ML Pipeline Engineering | 41.1 | 83.3 | 18.2 | 2 (2) |
| 7 | C427 Model Development and Evaluation | 41.1 | 83.3 | 18.2 | 2 (2) |
| 8 | TK2 IT operations and incident management | 37.2 | 78.6 | 9.1 | 1 |
| 9 | C443 Secure Software Engineering | 31.6 | 50.0 | 45.5 | 5 (1) |
| 10 | C441 Software Configuration Management | 31.6 | 50.0 | 45.5 | 5 (1) |
| 11 | TK7 Channel sales and account management | 30.8 | 64.3 | 9.1 | 1 |
| 12 | C435 Pipeline and Record Discipline / TK3 Service levels and IT governance | 24.3 | 50.0 | 9.1 | 1 |
| 14 | C444 Software Testing and Quality | 20.9 | 22.2 | 54.5 | 6 (0) |
| 15 | TK1 People and team management | 19.7 | 35.7 | 18.2 | 2 |
| 16 | C445 Requirements and Technical Specification | 19.1 | 22.2 | 45.5 | 5 (0) |
| 17-19 | TK9 11.4, TK6 5.0, C434 Client Communication 4.3 | | | | 1 each |

The "as written" ranking today (mapped courses count 0.3) puts, among the gap competencies, C441 and C443 first at 46.6, then C435 at 39.3, C442 29.8, C426 and C427 26.1, C445 21.6 and C444 18.4. The saved file for both rankings is `step1-scores-2026-10-26.json`.

## 5. Gates, waves, types

Gates: (a) a decision or cause-and-effect skill; (b) practice with instant feedback should beat reading (the five papers re-checked in Crossref today: all resolve); (c) an independent oracle can grade it, never the AI; (d) demand: 2+ people below the required level, or 4+ whose role needs it, or company-wide. Wave rule: all gates passed, then a cheap exact oracle, then a course to attach to, then low build effort; the score breaks ties.

| Topic | a | c: oracle | d | Course to attach | Wave | Simulation type |
|---|---|---|---|---|---|---|
| **C442 Back-End Design** | yes | queueing formulas (M/M/c, Little's law) + real Postgres plans | yes (2 below, 4 need it) | 201 | **1** | Parameter explorer + SQL sandbox; the 3D "Traffic Control Room" against a 2D control |
| **C427 Model Evaluation** | yes | scikit-learn reference outputs, closed-form metrics | yes (2 below) | 203 | **1** | Parameter explorer (3D loss surface + 2D boundary) |
| **C426 ML Pipeline Engineering** | yes | planted-fault key checked by running a reference pipeline | yes (2 below) | 202 | **1** | Scenario + pipeline-DAG explorer (2D) |
| C441 Software Configuration Management | yes | the real `git` binary | yes (5 need it) | none (planned course G8) | 2 | Git sandbox with real merge and conflict drills (learnGitBranching has no reflog or stash; isomorphic-git has no rebase) |
| C444 Software Testing and Quality | yes | run the tests; a mutation tool | yes (6 need it) | 204 | 2 | Code sandbox in the browser |
| C443 Secure Software Engineering (+ CW1 basics) | yes | OWASP rules; exploit and regression tests | yes (5; company-wide for CW1) | none (planned G7) | 3 | Safe in-browser vulnerable mini-app (server targets carry abuse risk) |
| CW3 Professional communication | yes | trained human raters only | yes (company-wide) | none | 3 | AI role-play with a rubric (eval harness first) |
| C445 Requirements and Technical Specification | partly | rubric + trained raters | yes (5 need it) | none | 3 | Guided practice / stakeholder-interview role-play |
| CW2 CERT-In incident reporting | yes | **legal text unverified** (duty research never run) | yes (company-wide) | none | 3, **blocked** until a legal check | Branching scenario |
| Fail gate (d) today (1 person each): TK4 maintenance and administration, TK2 incident management, TK7 channel sales, C435 pipeline and record discipline, TK3 service levels | | | no | | borderline | TK2 is the natural 3D "War Room" showpiece if you choose it; TK4 has the highest Need (92.9) but only 1 person |
| TK1 people management (2 people, not measured "below") | | | no | | hold | |

Differences from the approved plan's table (section 5), which used the 23 Oct "as written" scores: Software Configuration Management moves from Wave 1 to Wave 2 because it has no course to attach to (it passes every gate otherwise); its score is 31.6, not 19.1; Software Testing is 20.9, not 30.9; C435 is 24.3, not 29.3; C445 and TK4 are added to the table. Wave 1 is now C442, C427 and C426.

## 6. Cost, expert hours, maintainers (infrastructure from your Step 2 quotes; effort is [inferred] and for you to confirm)

| Type | Build size (relative) | Expert hours per simulation | Infrastructure per learner-hour | Who maintains it (proposed) |
|---|---|---|---|---|
| 2D parameter explorer | M | 6 to 10 | about $0: static files on Spaces ($5 a month includes 250 GiB and 1 TiB of egress; a 3 to 8 MB session costs about $0.00003 to $0.00008 beyond that at $0.01 per GiB) | runtime owner (code), the named expert (yearly re-check, 2 to 4 h) |
| 3D scene from downloaded models | L | 8 to 14 (includes mesh and asset review, credits) | about $0, same as above; downloaded GLBs are large before optimising (2 to 15 MB each) | runtime owner; asset register owner |
| Branching scenario | S to M | 6 to 10 | about $0 | runtime owner; the named expert |
| Browser code, SQL or Git sandbox | M to L | 6 to 10 | about $0; with a micro-VM add $0.05 to $0.17 (Vercel Sandbox quotes; a DigitalOcean 2 vCPU/4 GiB droplet is $0.036 an hour) | runtime owner |
| Guided practice in a sandbox copy of G2G | L | 4 to 8 (product owner) | a sandbox tenant and a reset script; negligible | product owner; runtime owner |
| AI role-play | L plus an eval harness (M) | 20+ (raters) | cents per session (the saved DeepSeek measurement was $0.00026 for one 443-in, 252-out call; current price to re-check) | runtime owner; named raters |

- The existing video-plus-quiz courses (201 to 204) are already built, so their marginal cost per learner-hour is about $0.
- At tenant-6 scale (2 to 6 learners per topic, about 1 hour each) any custom simulation costs far more per learner-hour than video plus quiz. It can only be justified by (i) a measured learning gain from the pilot, (ii) reuse across tenants through the platform library, or (iii) skills a video cannot practise. This is a decision for you; the numbers do not make the case by themselves.
- Labour in your model is Claude Code plus your review; its seat prices are in the Step 2 pack (Team standard $20 to $25 a seat a month, premium $100 to $125, Max from $100) and "seat price is not a guaranteed number of engineering hours". Build sizes above are therefore relative, not hours.

## 7. Pilot size and what it can show

- Wave 1 serves 6 people: 4 for C442 (2 Full Stack, 2 Back End) and 2 AI/ML engineers for C426 and C427.
- With 6 people a paired before/after design detects only very large effects (about 1.4 standard deviations at 80% power, two-sided; for all 11 it is about 0.94). So the Wave 1 pilot is mainly qualitative (think-aloud, task success, time) plus a descriptive before/after; a quantitative claim needs more people or more tenants.
- Adoption comes first: assign courses 201 to 203 to those six people, so that there is a baseline of use.

## 8. Not verified

- CERT-In, POSH and DPDP duties (the legal check was never run; the Step 2 pack holds the DPDP Act and Rules text, which was not read for this brief).
- Effort and expert-hour ranges (my estimates); infrastructure quotes are from your 22 Oct pack, not re-fetched today.
- Tenant-specific "who opens what" beyond enrolment and progress counts; no page-level or device data was available.

## 9. Decisions for you

1. Accept or change the wave list: Wave 1 = Back-End Design, Model Evaluation, ML Pipeline; Wave 2 = Git, Testing; Wave 3 = Secure coding, communication, requirements, CERT-In (blocked); borderline = Incident Management and the 1-person topics.
2. Whether the Wave 1 pilot goes ahead with 6 people, and who assigns the courses.
3. Whether to commission the legal check on CERT-In, POSH and DPDP.
4. Whether the economics (section 6) are acceptable, or the first simulation should wait until a second tenant can share it.
