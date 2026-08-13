# -*- coding: utf-8 -*-
"""SHARED HELPERS FOR THE PYTHON PATTERN SCRIPTS.

The PHP side has _lib.php for the same reason: a rule in prose does not survive
contact with the point of use. RESOLVE, DO NOT MATCH has now paid five times -

    resolve the model's getTable()      not: scrape DB::table literals
    resolve the column's real table     not: match the field name
    resolve the imported object         not: match the member name
    resolve a service import as a fetch not: match apiClient. literally

- and the fourth instance was written IN A SCRIPT WHOSE HEADER QUOTES THE RULE.
Restating it did not stop it, so it stops being prose and becomes a function.
"""
import re

# A file "makes a request" if it calls a client directly OR delegates to a
# service that does. The bell is the case that forces the second half: it fetches
# via `notificationsService.unreadCount(context)`, so the literal `apiClient.`
# never appears in it, and a literal test calls the one repaired component in the
# codebase a piece of hardcoded data.
_DIRECT = re.compile(r'apiClient\.|webClient\.|\bfetch\s*\(|useQuery|useSWR|useMutation')
_SERVICE_IMPORT = re.compile(
    r"""import\s*(?:\{[^}]*\}|\w+)\s*from\s*['"]@/(?:services|hooks)/[^'"]+['"]""")
_SERVICE_CALL = re.compile(r'\b\w*(?:Service|service)\.\w+\s*\(')


def makes_request(src: str) -> bool:
    """Does this file cause an HTTP request, directly or through a service?"""
    if _DIRECT.search(src):
        return True
    # An import alone is not enough - a file may import a TYPE from a service.
    # It must also CALL something service-shaped.
    if _SERVICE_IMPORT.search(src) and _SERVICE_CALL.search(src):
        return True
    return False


def makes_request_self_test() -> str:
    """Known-positives and known-negatives, through the same function callers use.

    Returns '' when sound, otherwise why it cannot be trusted.
    """
    # KNOWN-POSITIVE: a direct client call.
    if not makes_request("const r = await apiClient.get('/x')"):
        return 'a direct apiClient call was not recognised'
    # KNOWN-POSITIVE: THE BELL - service-mediated, no literal client call.
    bell = ("import { notificationsService } from '@/services/notifications'\n"
            "const res = await notificationsService.unreadCount(context)")
    if not makes_request(bell):
        return 'the bell (service-mediated fetch) was not recognised - the exact false positive this exists to remove'
    # KNOWN-NEGATIVE: a genuinely static file must NOT be called a fetcher.
    static = ("export function Dead() {\n"
              "  return <Select value='All' onChange={() => {}} options={[{label:'All',value:'All'}]} />\n"
              "}")
    if makes_request(static):
        return 'a static component was called a fetcher'
    # KNOWN-NEGATIVE: importing a TYPE from a service is not a request.
    typeonly = "import type { Course } from '@/services/lms'\nconst x: Course = props.course"
    if makes_request(typeonly):
        return 'a type-only service import was treated as a request'
    return ''
