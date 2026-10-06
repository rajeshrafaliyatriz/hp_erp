"""Build item 1 - talent_interviewpanelController tenant resolution.

Same shape as D-004: a helper mirroring payrollTenantId (token first, session
fallback), substituted per site. Five request-tenant sites, all inside
`if ($type == "API")` branches that validate a token and then discard its owner.
"""
import io

P = r"C:\Users\MILAN\Downloads\hp_erp\app\Http\Controllers\talent\interview_panel\talent_interviewpanelController.php"
src = io.open(P, encoding="utf-8", newline="").read()

HELPER = '''
    /**
     * The caller's organisation, resolved from the TOKEN, never from the request.
     *
     * This controller serves both the token-authenticated API and the
     * session-authenticated Blade screens, so the token is tried first and the
     * session is the fallback - the same shape as payrollTenantId (D-004).
     *
     * G-SEC-11 / the C27 class. Every `if ($type == "API")` branch below used to
     * validate that a token EXISTED and then take sub_institute_id from the
     * request body, which is the G-SEC-09 defect: the token's owner was
     * discarded. Confirmed by execution, not inference - the C23 guard recorded
     * `api/interview-panel/list` returning another tenant's data when
     * impersonating.
     *
     * Interview panel records cover CANDIDATES - people outside the company who
     * never agreed to be in the system - which is why this was fixed first by
     * data class rather than by route count.
     */
    private function panelTenantId(Request $request): ?int
    {
        $fromToken = $this->apiTenantId($request);
        if ($fromToken) {
            return $fromToken;
        }

        $fromSession = $request->session()->get('sub_institute_id');

        return is_numeric($fromSession) ? (int) $fromSession : null;
    }
'''

anchor = "    use ResolvesApiIdentity;"
assert anchor in src
src = src.replace(anchor, anchor + "\n" + HELPER, 1)

before = src.count("$request->get('sub_institute_id')")
src = src.replace("$sub_institute_id = $request->get('sub_institute_id');",
                  "$sub_institute_id = $this->panelTenantId($request);")
after = src.count("$request->get('sub_institute_id')")

io.open(P, "w", encoding="utf-8", newline="").write(src)
print("request-tenant sites before:", before, " after:", after)
print("panelTenantId call sites  :", src.count("$this->panelTenantId($request)"))
