# Evidence folder

Command output, query results, screenshots and reading notes that back every "verified" claim in the progress log and slice files.

Naming: `<slice>-<what>-<yyyy-mm-dd>.<ext>`, for example `s0-baseline-live-2026-11-05.txt`.

Rules:

- Never store secrets, keys or tokens. Report key names only.
- Never store personal data (names, emails, phone numbers of real people). Counts and hashes only. The S5 answer key stays outside the repository.
- Each file starts with: what produced it, on which host and connection, when, and the exact command or query.
- A claim is `verified` only when its evidence file exists here.

Files:

- `schema-reading-2026-11-02.md`: schema and code facts read from migrations, models, controllers and screens on 2026-11-02 (no database contacted; `agent-read` unless noted).
- `defect-register-2026-11-02.md`: defects D1–D19 with evidence, status and owning slice.
- Full subagent reports of 2026-11-02 are in the session transcript `C:\Users\MILAN\.claude\projects\c--Users-MILAN-Downloads-hp-erp\7e204d2f-9146-4ff7-82c9-07d5c31d4568.jsonl` (search for "Subagent hand-back").
