Track S4b (facts front end, g2gv0 branch milan1), 4 Nov 2026. Evidence for the caller to copy into hp_erp docs/agentic-crm/_evidence/s4b-facts-frontend/.
(Kept outside hp_erp because the coordinator said S4b may touch hp_erp only for its lines in the slice file's work log.)

Files in this folder
- test-run-facts-final.txt      node --import tsx --test "lib/facts/*.test.ts" "lib/facts/*.test.tsx"  (summary lines)
- test-run-neighbours.txt       lib/org-accounts, lib/agent, lib/product-master (must stay green)
- tsc-final.txt                 node node_modules/typescript/bin/tsc --noEmit -p .  (error lines, per file)
- eslint-final.txt              node node_modules/eslint/bin/eslint.js <my files and the three touched ones> --max-warnings=0
- mutation-run-1.txt            the mutation sweep (runner: scripts/mutation/s4b-facts-frontend/mutate_fe.mjs, spec mutations_fe.mjs)

How a mutation is judged (COMMON-RULES section 4)
- the runner first proves every test file is green on its own (a command error would look like a 100% kill otherwise);
- a KILL is counted only when node:test itself reports failing tests; a mutation that does not compile is INVALID, not a kill;
- every mutated file is restored and compared byte for byte (sha256 and size) after each mutation; the run aborts with FATAL if not.
