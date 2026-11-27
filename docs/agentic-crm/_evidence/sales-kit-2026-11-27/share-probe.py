"""Attack the PUBLIC share routes of the Sales Kit on dev. Stdlib only. Prints PASS/FAIL per probe; creates rows titled "Kit Check ..." (removed by cleanup-kit2.php)."""
import json, sys, urllib.request, urllib.error, urllib.parse, re, time, uuid

API = "http://127.0.0.1:8000/api"
FE = "http://localhost:3412"
admin = json.load(open(sys.argv[1]))["userData"]["token"]
results = []

def check(name, ok, detail=""):
    results.append(ok)
    print(("PASS  " if ok else "FAIL  ") + name + (("   - " + str(detail)[:230]) if detail else ""))

def call(method, url, body=None, headers=None, raw=None, timeout=60):
    data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
    req = urllib.request.Request(url, data=data, method=method, headers=headers or {})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.status, dict((k.lower(), v) for k, v in r.getheaders()), r.read()
    except urllib.error.HTTPError as e:
        return e.code, dict((k.lower(), v) for k, v in e.headers.items()), e.read()
    except Exception as e:  # connection refused etc.
        return 0, {}, str(e).encode()

AUTH = {"Authorization": "Bearer " + admin, "Accept": "application/json"}
JSONH = dict(AUTH, **{"Content-Type": "application/json"})

def admin_json(method, path, body=None):
    s, h, b = call(method, API + path, body, JSONH)
    try: return s, json.loads(b or b"{}")
    except Exception: return s, {"raw": b[:200]}

# ---------------------------------------------------------------- setup: items to attack
s, kit = admin_json("GET", "/product-master/1/kit")
items = {i["title"]: i for i in kit["data"]["items"]}
print("kit has", len(items), "items")
pdf = items.get("Kit Check brochure"); link = items.get("Kit Check External link"); pptx = items.get("Kit Check Demo deck")
assert pdf and link and pptx, "run the browser walk first (it creates the Kit Check items)"

# 1. no-expiry is the DEFAULT when `days` is left out
s, d = admin_json("POST", f"/product-master/1/kit/{pdf['id']}/share", {})
share = d["data"]["share"]; tok = share["token"]
check("a link made with no `days` does not expire (the owner's default)", s in (200, 201) and share["expiresAt"] is None and share["expired"] is False, json.dumps(share))
check("the token is 48 letters and digits", re.fullmatch(r"[A-Za-z0-9]{48}", tok) is not None, tok)

# the single plain refusal every bad request must produce
s0, h0, b0 = call("GET", f"{API}/public/sales-kit/{'z'*48}")
check("an unknown token is a plain 404", s0 == 404 and json.loads(b0)["status"] == 0, b0)

def same_refusal(name, url, method="GET", **kw):
    s, h, b = call(method, url, **kw)
    ok = s == s0 and b == b0 and h.get("cache-control") == h0.get("cache-control")
    check(name, ok, f"{s} {b[:90]!r}")

# 2. guessing and tampering with a REAL token
flip = lambda t, i: t[:i] + ("A" if t[i] != "A" else "B") + t[i+1:]
same_refusal("one character changed (first)", f"{API}/public/sales-kit/{flip(tok, 0)}")
same_refusal("one character changed (last)", f"{API}/public/sales-kit/{flip(tok, 47)}")
swapped = tok.swapcase()
if swapped != tok:
    same_refusal("the same token with the case swapped (MySQL compares case-insensitively; the code must not)", f"{API}/public/sales-kit/{swapped}")
same_refusal("47 characters", f"{API}/public/sales-kit/{tok[:47]}")
same_refusal("49 characters", f"{API}/public/sales-kit/{tok}A")
same_refusal("the token with a trailing dot", f"{API}/public/sales-kit/{tok}.")
same_refusal("a SQL injection in the token", f"{API}/public/sales-kit/" + urllib.parse.quote("' OR '1'='1' -- "))
same_refusal("a union select in the token", f"{API}/public/sales-kit/" + urllib.parse.quote("x' UNION SELECT 1,2,3 -- "))
same_refusal("a LIKE wildcard token", f"{API}/public/sales-kit/" + "%25" * 16)
same_refusal("path traversal in the token", f"{API}/public/sales-kit/..%2F..%2F..%2Fetc%2Fpasswd")
same_refusal("a null byte in the token", f"{API}/public/sales-kit/{tok[:40]}%00{tok[41:]}")
same_refusal("a 10,000 character token", f"{API}/public/sales-kit/{'a'*10000}")
same_refusal("a unicode token", f"{API}/public/sales-kit/" + urllib.parse.quote("é" * 48))
same_refusal("file route, unknown token", f"{API}/public/sales-kit/{'y'*48}/file?inline=1")
same_refusal("file route of a LINK item is the same refusal", f"{API}/public/sales-kit/{admin_json('POST', f'/product-master/1/kit/{link['id']}/share', {})[1]['data']['share']['token']}/file")

# 3. what a valid token reveals
s, h, b = call("GET", f"{API}/public/sales-kit/{tok}")
data = json.loads(b)["data"]
expected = {"title", "description", "kind", "source", "productName", "fileName", "mimeType", "size", "url", "deck", "expiresAt"}
check("the public JSON holds exactly the allowed keys (no tenant, user or document ids)", s == 200 and set(data) == expected, sorted(data))
check("no id-like or storage-like value anywhere in the public JSON", not re.search(r"storage|private/document|sub_institute|owner|created_by|document_id|\"id\"", b.decode()), b.decode()[:160])
check("the public JSON is not cacheable or indexable", "no-store" in h.get("cache-control", "") and "noindex" in h.get("x-robots-tag", "") and h.get("referrer-policy") == "no-referrer", json.dumps({k: h.get(k) for k in ("cache-control", "x-robots-tag", "referrer-policy")}))
check("no wildcard CORS on the public routes (another site cannot read them from a browser)", h.get("access-control-allow-origin") not in ("*",), h.get("access-control-allow-origin"))

# 4. the file
s, h, b = call("GET", f"{API}/public/sales-kit/{tok}/file?inline=1")
check("a PDF inline: bytes, application/pdf, inline, nosniff, no-store", s == 200 and b[:4] == b"%PDF" and h.get("content-type") == "application/pdf" and "inline" in h.get("content-disposition", "") and h.get("x-content-type-options") == "nosniff" and "no-store" in h.get("cache-control", ""), json.dumps({k: h.get(k) for k in ("content-type", "content-disposition")}))
check("the file response can be framed by the page (no X-Frame-Options) and sets no cookie", "x-frame-options" not in h and "set-cookie" not in h)
s, d = admin_json("POST", f"/product-master/1/kit/{pptx['id']}/share", {"days": 0})
ptok = d["data"]["share"]["token"]
s, h, b = call("GET", f"{API}/public/sales-kit/{ptok}/file?inline=1")
check("a PowerPoint is ALWAYS a download, even with ?inline=1", s == 200 and "attachment" in h.get("content-disposition", ""), h.get("content-disposition"))

# 5. wrong methods change nothing
for m in ("POST", "PUT", "PATCH", "DELETE"):
    s, h, b = call(m, f"{API}/public/sales-kit/{tok}", body={})
    check(f"{m} on the public route is refused (405/404/419), never 2xx", s in (404, 405, 419), s)
s, kit2 = admin_json("GET", "/product-master/1/kit")
still = {i["title"]: i for i in kit2["data"]["items"]}["Kit Check brochure"]["share"]
check("the link survived those attacks unchanged", still is not None and still["token"] == tok)

# 6. the front-end public page: no leak, no stored script
s, h, b = call("GET", f"{FE}/share/kit/{tok}", timeout=180)
html = b.decode("utf-8", "ignore")
check("the public page answers 200 for a live token", s == 200, s)
leak = re.findall(r"sub_institute|document_id|storage_path|private/document_library|created_by|userData|gtg-session|Bearer ", html)
check("the public page's HTML holds no tenant id, document id, storage path, user data or bearer token", not leak, leak)
other = [t for t in (ptok, share["token"]) if t != tok and t in html]
check("the public page never contains another link's token", not other)
s1, h1, b1 = call("GET", f"{FE}/share/kit/{'q'*48}", timeout=120)
check("the public page of an unknown token is a 404 with nothing about why", s1 == 404 and b"Kit Check" not in b1)

# 7. a hostile title is shown as text, never run
evil = "Kit Check <img src=x onerror=alert(1)><script>alert(2)</script>"
s, d = admin_json("POST", "/product-master/1/kit/links", {"title": evil, "url": "https://example.com/", "kind": "document"})
eid = d["data"]["id"]; s, d = admin_json("POST", f"/product-master/1/kit/{eid}/share", {})
etok = d["data"]["share"]["token"]
s, h, b = call("GET", f"{FE}/share/kit/{etok}", timeout=180)
html = b.decode("utf-8", "ignore")
check("a title with <script> and onerror is escaped on the public page", "&lt;script&gt;" in html and "<script>alert(2)" not in html and "<img src=x onerror" not in html)
for bad_url in ("javascript:alert(1)", "data:text/html,<script>alert(1)</script>", "//evil.example/x", "http://example.com/", "/signals/portfolio/anything"):
    s, d = admin_json("POST", "/product-master/1/kit/links", {"title": "Kit Check bad url", "url": bad_url, "kind": "document"})
    if s in (200, 201):
        sid = d["data"]["id"]; s2, d2 = admin_json("POST", f"/product-master/1/kit/{sid}/share", {})
        check(f"link address {bad_url!r} cannot be shared publicly", s2 == 422, s2)
    else:
        check(f"link address {bad_url!r} is refused at creation", s == 422, s)

# 8. malicious uploads are refused (multipart by hand)
def upload(name, content, ctype):
    b = "----probe" + uuid.uuid4().hex
    body = (f"--{b}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{name}\"\r\nContent-Type: {ctype}\r\n\r\n").encode() + content + f"\r\n--{b}--\r\n".encode()
    s, h, r = call("POST", f"{API}/product-master/1/kit/files", raw=body, headers=dict(AUTH, **{"Content-Type": f"multipart/form-data; boundary={b}"}))
    return s, r[:140]
for name, content, ctype, label in [
    ("Kit Check page.pdf", b"<html><script>alert(1)</script></html>", "application/pdf", "HTML named .pdf"),
    ("Kit Check pic.png", b"<svg xmlns='http://www.w3.org/2000/svg' onload='alert(1)'></svg>", "image/png", "SVG named .png"),
    ("Kit Check shell.php", b"<?php system($_GET['c']); ?>", "application/x-php", "a PHP file"),
    ("Kit Check run.exe", b"MZ\x90\x00" + b"\x00" * 60, "application/octet-stream", "an executable"),
    ("Kit Check deck.pptx", b"MZ\x90\x00" + b"\x00" * 60, "application/vnd.openxmlformats-officedocument.presentationml.presentation", "an executable named .pptx"),
    ("Kit Check x.html", b"<script>alert(1)</script>", "text/html", "an .html file"),
]:
    s, r = upload(name, content, ctype)
    check(f"upload refused: {label}", s == 422, f"{s} {r}")

# 9. the throttle (last: it will block this IP for a minute)
codes = []
for i in range(70):
    s, h, b = call("GET", f"{API}/public/sales-kit/{'t'*47}{i % 10}")
    codes.append(s)
check("guessing is rate-limited: a burst of 70 requests meets a 429", 429 in codes, f"first 429 at request {codes.index(429) + 1}" if 429 in codes else "never")

print(f"\n{sum(results)} of {len(results)} probes passed")
sys.exit(0 if all(results) else 1)
