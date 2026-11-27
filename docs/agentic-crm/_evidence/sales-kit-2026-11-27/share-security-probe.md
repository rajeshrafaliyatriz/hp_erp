# Sales Kit public share link: security probe (27 Nov 2026, dev)

Owner: "I want non-expired share links with 0 security breach." This is the evidence of what was attacked and what held. `share-probe.py` (same folder, stdlib only) was run against the running dev backend and front end after the browser walk. **47 probes; 46 passed by the strict test and the 47th is the framework's own 404** (below). Nothing here proves a link that never expires is risk-free: see "What remains true".

| Attack | Result |
|---|---|
| A link made with no `days` | does not expire (`expiresAt` null); token is 48 letters and digits |
| Unknown token; one character changed (first, last); 47 and 49 characters; trailing dot; Unicode; 10,000 characters; null byte | the SAME plain 404 `{"status":0,"message":"This link is not available."}`, same status, body and cache header every time |
| The real token with its case swapped (MySQL compares case-insensitively) | same 404 (the code re-compares with `hash_equals`) |
| SQL injection (`' OR '1'='1' --`, `UNION SELECT`), a LIKE wildcard token | same 404 |
| Path traversal `..%2F..%2F..%2Fetc%2Fpasswd` | 404 from the framework's route table (the encoded slashes match no route, so it never reaches the share code); the body is BYTE-IDENTICAL to the page for any unknown URL (`/api/this-route-does-not-exist`) and holds nothing about files or the stack |
| File route of a link item; file route of an unknown token | same 404 |
| What a valid token reveals | exactly 11 keys (title, description, kind, source, productName, fileName, mimeType, size, url, deck, expiresAt); no tenant, user or document id, no storage path |
| Headers on the JSON and on the file | `no-store, private`, `noindex, nofollow`, `Referrer-Policy: no-referrer`, `nosniff`; no wildcard CORS; no cookie |
| PDF with `?inline=1` | inline, `application/pdf`, frameable by the page (no `X-Frame-Options` on the file route) |
| PowerPoint with `?inline=1` | still an attachment |
| POST / PUT / PATCH / DELETE on the public route | 405; the link survived unchanged |
| The public page | 200 for a live token; its HTML holds no tenant id, document id, storage path, user data or bearer token and no other link's token; an unknown token is a bare 404 |
| A title with `<script>` and `onerror` | escaped on the public page |
| Link addresses `javascript:`, `data:`, `//host`, `http://`, an in-app path | refused at creation, or refused for sharing (422) |
| Uploads: HTML named .pdf, SVG named .png, a PHP file, an executable, an executable named .pptx, an .html file | all refused (422) |
| Guessing | the 61st request in a minute gets 429 |

## What remains true (not claimed away)
- **A link that never expires works for anyone who has it until a manager stops it or makes a new one.** The controls are: stop, make a new link (the old one stops at once), removing the item ends its link, and every refusal looks the same. Sales staff who may view the kit can copy a live link, by design (they send it to customers); if only managers should see it, that is one rule to change.
- The token is in the address, so it appears in server and Vercel access logs and in browser history. `Referrer-Policy: no-referrer` stops it leaking to other sites.
- NOT verified here: the production response headers from a production build (the dev server overrides `Cache-Control`; the configured values are pinned by a test and read from `next.config.mjs`), the live host's proxy forwarding the real client IP (the 60 per minute limit is per IP), `APP_DEBUG` off on the live host, and the live host's own response headers on the file route (an nginx default `X-Frame-Options: SAMEORIGIN` would blank the framed PDF).
