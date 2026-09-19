#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════════
# THE SETTINGS WIRING THAT IS INVISIBLE TO BOTH tsc AND eslint
# ═══════════════════════════════════════════════════════════════════════════════
#
# Every defect this file checks for has already happened in this codebase, twice
# in the same shape: a component imported, typed, lint-clean, compiled — and
# never actually connected to anything. `ThemeProvider` and then
# `PreferencesProvider` were each imported and never rendered, and both shipped,
# because no part of the toolchain can tell a wired hook from a present one.
#
# A guard is exactly that class of code. It fails SILENTLY, and only in the
# moment somebody loses work. So it is checked mechanically rather than trusted.
#
# ── THE BUGS THIS FILE ITSELF SHIPPED, KEPT ON THE RECORD ─────────────────────
#
# The first version reported 10 failures that did not exist, and one that was the
# exact opposite of the truth. All five causes were in the checking, not the code:
#
#   grep -c with || echo 0    emits two lines on no match (grep exits 1 AND
#                             prints 0), so every numeric test after it was a
#                             syntax error that read as a failure.
#   a loose grep pattern      matched the word "required" in prose — a hint
#                             reading "letters and numbers are always required"
#                             counted as a required field.
#   piping into while read    runs the loop in a SUBSHELL, so every `wrong=`
#                             increment inside it was discarded. The script
#                             printed WRONG lines and then reported 0 WRONG and
#                             exited 0. A check that cannot fail is not a check.
#   CRLF out of CPython       made the count arrive as "0<CR>", failing every -eq.
#   grepping the source       matched the explanatory COMMENT describing the very
#                             bug being checked for, so documenting a fix broke
#                             its own check.
#
# Run: bash Docs/organization-audit/_evidence/check-settings-guards.sh
set -u

FE="/c/Users/MILAN/Downloads/g2gv0"
SEC="$FE/components/settings/sections"
SHELL_FILE="$FE/components/settings/settings-shell.tsx"
HOOK="$FE/hooks/use-unsaved-guard.ts"

correct=0
wrong=0

ok()   { echo "  CORRECT  $1"; correct=$((correct+1)); }
bad()  { echo "  WRONG    $1"; wrong=$((wrong+1)); }
# EVERY CHECK IS COMMENT-BLIND. This is not belt-and-braces; it is the bug that
# got through twice.
#
# Section 5 first reported the opposite of the truth by matching the comment that
# DESCRIBED the bug it was checking for. That was patched in isolation — and then
# a mutation test (deleting `event.returnValue` from the hook) failed to trip the
# check, because the hook's own doc comment contains the words "returnValue" and
# "preventDefault()" while explaining why both are needed.
#
# So a fix documented in prose could satisfy a check while the code did nothing.
# Stripping comments from EVERY grep removes the whole class rather than the two
# instances that happened to be noticed.
#
# `sed` drops `//` line comments; `grep -v` drops the continuation lines of block
# comments, which in this codebase are always ` * ...`.
# BLOCK COMMENTS TOO, WHICH THE SED VERSION MISSED.
#
# The old stripper was `sed 's://.*::' | grep -v '^ *\*'` - `//` lines and JSDoc
# continuations. This codebase's third comment shape survived it:
#
#     {/*
#       `touch-none` is required, not cosmetic: without it the browser claims
#     */}
#
# Those lines begin with plain text, so a mutation test caught the hole - deleting
# `touch-none` from the real className left the check passing, satisfied by the
# paragraph explaining why it mattered. Spanning lines is what sed is bad at, so
# it is a small Python script instead.
strip() { python "$(dirname "$0")/strip-comments.py" "$1" 2>/dev/null; }
have() { strip "$1" | grep -q "$2"; }
code() { have "$1" "$2"; }

echo "════════════════════════════════════════════════════════════════"
echo "1. Every section with an explicit Save warns before losing a draft"
echo "════════════════════════════════════════════════════════════════"
for f in profile delivery organization-defaults security-policy; do
  P="$SEC/$f-section.tsx"

  if have "$P" "SaveButton"; then
    have "$P" "useUnsavedGuard(dirty)" \
      && ok "$f calls useUnsavedGuard(dirty)" \
      || bad "$f has a Save button and NO beforeunload guard"

    have "$P" "onDirtyChange?.(" \
      && ok "$f reports dirty to the shell" \
      || bad "$f never tells the shell — switching section would discard it"

    have "$P" "return () => onDirtyChange?.(" \
      && ok "$f clears the flag on unmount" \
      || bad "$f leaves the flag set on unmount — a stale prompt forever"
  else
    bad "$f no longer has a SaveButton — this check needs updating"
  fi
done

echo
echo "════════════════════════════════════════════════════════════════"
echo "2. The guard is reachable (the import-but-never-render bug)"
echo "════════════════════════════════════════════════════════════════"
have "$HOOK" "addEventListener('beforeunload'" \
  && ok "the hook registers a real beforeunload listener" \
  || bad "the hook does not register beforeunload"

have "$HOOK" "removeEventListener('beforeunload'" \
  && ok "and removes it on cleanup" \
  || bad "the listener is never removed — it would fire after the form is gone"

# preventDefault alone is ignored by Chrome, returnValue alone by others. Both
# are needed, and it is the single easiest thing here to get wrong.
if have "$HOOK" "preventDefault()" && have "$HOOK" "returnValue"; then
  ok "sets both preventDefault and returnValue"
else
  bad "only one of preventDefault/returnValue — ignored in some browsers"
fi

# THE EXACT SITE, not the bare name anywhere in the file.
#
# `dirtySection.current` appears four times - the writer, the reader, and two
# clears - so grepping the bare name passed a mutation that broke only the READ,
# because the other three occurrences still matched. What matters is that
# `open()` reads it before switching section, so that is what is checked.
have "$SHELL_FILE" "const pending = dirtySection.current" \
  && ok "open() reads the dirty flag before switching section" \
  || bad "open() does not read the flag - it would still discard drafts"

have "$SHELL_FILE" "window.confirm(" \
  && ok "open() asks before changing section" \
  || bad "open() changes section without asking"

threaded=$(grep -c "onDirtyChange={onDirtyChange}" "$SHELL_FILE" 2>/dev/null)
threaded=${threaded//[^0-9]/}
if [ "${threaded:-0}" -eq 4 ]; then
  ok "all four sections receive onDirtyChange (4 of 4)"
else
  bad "only ${threaded:-0} of 4 sections receive onDirtyChange"
fi

echo
echo "════════════════════════════════════════════════════════════════"
echo "3. A failed lazy() chunk breaks one pane, not the route"
echo "════════════════════════════════════════════════════════════════"
have "$SEC/section-primitives.tsx" "getDerivedStateFromError" \
  && ok "SectionBoundary is a real error boundary" \
  || bad "no getDerivedStateFromError — it catches nothing"

have "$SHELL_FILE" "<SectionBoundary" \
  && ok "the shell RENDERS it (not merely imports it)" \
  || bad "SectionBoundary is not rendered — the ThemeProvider bug again"

# Nested inside Suspense it would never see a rejected dynamic import.
if grep -A6 "<SectionBoundary" "$SHELL_FILE" | grep -q "<Suspense"; then
  ok "the boundary wraps Suspense, so it sees a failed chunk"
else
  bad "Suspense is outside the boundary — a rejected lazy() would escape"
fi

have "$SHELL_FILE" "key={current?.id" \
  && ok "keyed on the section, so one error does not stick to the others" \
  || bad "not keyed — a failed section keeps erroring after navigating away"

echo
echo "════════════════════════════════════════════════════════════════"
echo "4. Every required field is actually validated"
echo "════════════════════════════════════════════════════════════════"
# Counts come from count-required-fields.py, which reads the actual <Field> tags.
# Process substitution, NOT a pipe, so the `bad` increments survive.
# A GENERATOR THAT PRODUCES NOTHING MUST FAIL, NOT PASS.
#
# This section was silently vacuous once already: the python file had a syntax
# error, so it emitted no lines, so the loop body never ran — and the script
# reported 0 WRONG and exited 0 having checked nothing. Captured first and
# counted, so an empty result is itself a failure.
counts=$(python "$(dirname "$0")/count-required-fields.py" 2>&1)
status=$?

if [ "$status" -ne 0 ] || [ -z "$counts" ]; then
  bad "count-required-fields.py produced nothing, so NOTHING here was checked"
  echo "           $counts"
else
  checked=0

  while read -r f n guarded; do
    n=${n//[^0-9]/}
    guarded=${guarded//[^0-9]/}
    [ "${n:-0}" -eq 0 ] && continue
    P="$SEC/$f-section.tsx"
    checked=$((checked+1))

    # PER FIELD, not per section.
    #
    # This asked only whether a section contained ANY `error=` anywhere, and a
    # mutation test showed that was blind: deleting one of delivery's four left
    # three and the check still passed. Now every required field must either
    # carry its own `error=`, or the section must gate its submit button — which
    # is how Security and Security policy do it, with their own inline messages.
    if [ "${guarded:-0}" -eq "$n" ]; then
      ok "$f validates all $n of its required field(s) per field"
    elif have "$P" 'canSubmit' || have "$P" 'belowFloor'; then
      ok "$f gates its submit button ($guarded of $n fields also marked)"
    else
      bad "$f has $n required field(s) but only $guarded validated, and no submit gate"
    fi
  done < <(echo "$counts")

  # Four sections draw required fields today. Fewer means either the generator
  # regressed or a section lost its fields without this check being updated.
  [ "$checked" -ge 4 ]     && ok "$checked sections with required fields were examined"     || bad "only $checked sections examined — expected at least 4"
fi

echo
echo "════════════════════════════════════════════════════════════════"
echo "5. One invite does not freeze the other two hundred"
echo "════════════════════════════════════════════════════════════════"
P="$SEC/people-access-section.tsx"
# On the `disabled` prop specifically. `busy.has(person.id)` also appears in the
# spinner and in the double-click guard, so the bare name survived a mutation
# that replaced the whole disabled expression with `false`.
have "$P" "disabled={busy.has(person.id)" \
  && ok "the disabled prop is gated on this row" \
  || bad "the invite button is not gated per row (list-wide, or not at all)"

code "$P" "busyId" \
  && bad "the list-wide disable is still there in code" \
  || ok "no list-wide disable remains in code (comments aside)"

have "$P" "results\[person.id\]" \
  && ok "each person keeps their own invite link" \
  || bad "one shared result — a second invite erases the first link"

have "$P" "await load(true)" \
  && ok "the post-invite refresh is quiet, so the link stays on screen" \
  || bad "the refresh blanks the section and the fresh link flashes away"

echo
echo "════════════════════════════════════════════════════════════════"
echo "6. Ending one session does not freeze the other twenty-four"
echo "════════════════════════════════════════════════════════════════"
# THE SAME BUG CLASS, THIRD SIGHTING.
#
# `disabled={busyId !== null}` in the invite list, then `disabled={sessionBusy
# !== null}` on every Sign out button. A single-valued flag used for a per-row
# decision, twice, in two files. Checked here so a third does not ship.
#
# The bulk "Sign out everywhere else" button and the confirm dialog DO take the
# list-wide flag, correctly - every session is about to end either way - so this
# looks for the per-row form rather than the absence of the list-wide one.
P="$SEC/security-section.tsx"
have "$P" "sessionBusy === session.id || sessionBusy === 'all'"   && ok "each Sign out button is gated on its own row"   || bad "the Sign out buttons are disabled list-wide again"

have "$P" "<DataTable"   && ok "the session list is a table, so the dates align"   || bad "the session list is back to hand-rolled prose rows"

have "$P" "<ProgressBar"   && ok "password strength uses the shared bar (with role=progressbar)"   || bad "the hand-rolled strength bar is back - no accessible role"

# The avatar bug: branching on whether a URL EXISTS rather than whether it LOADS.
for f in "$SEC/profile-section.tsx" "$FE/components/shell/gtg-user-menu.tsx"; do
  n=$(basename "$f" .tsx)
  have "$f" "<AvatarImage"     && ok "$n falls back to initials when the photo fails to load"     || bad "$n renders a bare <img>, so a dead photo URL shows an empty circle"
done

echo
echo "════════════════════════════════════════════════════════════════"
echo "7. /account/me is fetched once, and one error state describes it"
echo "════════════════════════════════════════════════════════════════"
# TWO FETCHES MEANT TWO ANSWERS THAT COULD DISAGREE.
#
# `PreferencesProvider` fetched the full response app-wide and discarded most of
# it; `useAccount` then fetched the same endpoint again on every /settings load.
# The duplicate request mattered less than the two SEPARATE error states: the
# provider succeeding while the screen failed left the theme, sidebar and header
# avatar correct while Settings said it could load nothing.
PROV="$FE/components/providers/preferences-provider.tsx"
ACC="$FE/hooks/use-account.ts"

# THE CALL, NOT THE IMPORT, AND NOT A COUNT.
#
# Two things were wrong here. It printed a call-site count, and the count was
# wrong - `grep -c` counts LINES and one of the two call sites chains across two
# of them (`accountService` on one line, `.me(context)` on the next), so it said
# one where there are two.
#
# Worse, it grepped the bare name `accountService`, which also appears on the
# IMPORT line. A mutation test that removed BOTH real call sites left the import
# behind and the check still passed - it was asserting that the module is
# imported, which is exactly the class of bug this whole file exists to catch.
#
# `.me(context)` matches the call in either form and appears nowhere else.
if strip "$PROV" | grep -q "\.me(context)"; then
  ok "the provider is the one that actually calls /account/me"
else
  bad "the provider no longer fetches /account/me at all"
fi

code "$ACC" "accountService$(printf '.')me"   && bad "useAccount fetches /account/me again - the duplicate is back"   || ok "useAccount does not fetch it a second time"

have "$ACC" "useAppPreferences()"   && ok "useAccount reads the shared copy"   || bad "useAccount holds its own copy again"

# A save must land in the shared copy, or the screen shows pre-save values back.
have "$PROV" "setAccount((current)"   && ok "apply() writes the save into the shared response"   || bad "apply() leaves the shared response stale after a save"

# The expired-session message is the settings screen's own conclusion and must
# not migrate into the provider, where a signed-out visitor would see it.
# THE USE, not the declaration.
#
# A mutation test replaced only the first of the two occurrences - which is the
# `const` - and this check still passed while the message was no longer produced.
# The line that matters is the one that actually returns it.
have "$ACC" "sessionGone ? SESSION_EXPIRED"   && ok "an expired session is still reported on /settings"   || bad "an expired session is now silent - defaults look like real settings"

code "$PROV" "session has expired"   && bad "the provider now shows a session error to signed-out visitors"   || ok "the provider stays quiet for signed-out visitors"

# The failed-fetch rail. Sections are NOT guessed, so the consequence is said.
have "$SHELL_FILE" "could not confirm your role"   && ok "a failed fetch says why sections are missing"   || bad "a failed fetch silently drops half the rail again"

have "$SHELL_FILE" "account.reload()"   && ok "and offers a retry that actually retries"   || bad "the error banner has no action"

echo
echo "════════════════════════════════════════════════════════════════"
echo "8. The Save button does not claim a save that never happened"
echo "════════════════════════════════════════════════════════════════"
# IT READ "Saved" ON EVERY FORM THAT HAD NEVER BEEN SAVED.
#
# The label was `saving ? "Saving..." : dirty ? label : "Saved"`, and "not dirty"
# is the state all four of these forms OPEN in. So opening Profile and touching
# nothing showed a button reading "Saved" under the fields - the exact
# confirmation somebody looks for after typing, handed out unconditionally.
PRIM="$SEC/section-primitives.tsx"

have "$PRIM" "export function SaveButton"   && ok "SaveButton lives with the other primitives"   || bad "SaveButton is not in section-primitives.tsx"

code "$SHELL_FILE" "export function SaveButton"   && bad "SaveButton is back in the shell, which never renders it"   || ok "the shell no longer defines it"

have "$PRIM" "saved && !dirty && !saving"   && ok "'Saved' needs a real save, not merely an unedited form"   || bad "'Saved' can show on a form that was never saved"

# And every caller must pass the real state, or the prop defaults to false and
# the button silently never confirms a save at all.
wired=$(grep -l "saved={saved}" "$SEC"/profile-section.tsx "$SEC"/delivery-section.tsx         "$SEC"/organization-defaults-section.tsx "$SEC"/security-policy-section.tsx 2>/dev/null | wc -l)
wired=${wired//[^0-9]/}
if [ "${wired:-0}" -eq 4 ]; then
  ok "all four forms pass their real saved state (4 of 4)"
else
  bad "only ${wired:-0} of 4 forms pass saved={saved}"
fi

echo
echo "════════════════════════════════════════════════════════════════"
echo "9. A picked image is framed before it is uploaded"
echo "════════════════════════════════════════════════════════════════"
# BOTH AVATARS ARE ROUND AND USE object-cover, so the browser chose which third
# of a 4:3 photo to discard, and it always chose the same thirds. The cropper
# hands that choice to the person - but only if the pick actually routes through
# it. A picker that stages the raw file again would look identical until the
# photo came back cut.
CROP="$FE/components/settings/image-cropper.tsx"
MATH="$FE/lib/image-crop.ts"
ORG="$FE/components/domain/organization/organization-information-edit-panel.tsx"

have "$MATH" "export function placement"   && ok "the crop maths is a pure function, so it can be tested"   || bad "placement() is gone - the maths is no longer testable in isolation"

# The whole guarantee: preview and export come from ONE function at two sizes.
if grep -c "draw(canvas" "$CROP" >/dev/null 2>&1; then
  n=$(strip "$CROP" | grep -c "draw(canvas")
  n=${n//[^0-9]/}
  [ "${n:-0}" -ge 2 ]     && ok "preview and export both go through draw() ($n call sites)"     || bad "only ${n:-0} draw() call site - the export has its own path and can drift"
fi

have "$CROP" "imageOrientation: 'from-image'"   && ok "EXIF orientation is applied, so a phone photo is not sideways"   || bad "no imageOrientation - portrait phone photos will decode rotated"

have "$CROP" "touch-none"   && ok "the frame claims the touch gesture, so dragging works on a phone"   || bad "without touch-none the browser scrolls the page instead of panning"

# THE PROP, not the handler's declaration.
#
# `have "$CROP" "onKeyDown"` matched `function onKeyDown(...)` as well as the JSX
# attribute, so deleting the attribute - which disconnects the keyboard entirely -
# left this check passing against a dead handler.
# THE ZOOM FLOOR. Reported as "the image is too big to fit in the position photo".
#
# `zoom` multiplies the COVER scale, so a floor of 1 means the image opens already
# cropped with no way to pull back - which is exactly what shipped. The floor has
# to stay below 1, and a fit control has to exist, because a slider that CAN reach
# 0.3x is useless when nobody knows 0.3x is this particular file's fitting number.
have "$MATH" "export function containZoom"   && ok "containZoom exists, so 'fit the whole image' is computable"   || bad "no containZoom - the whole-image zoom cannot be found"

if strip "$CROP" | grep -qE "MIN_ZOOM = 0\."; then
  ok "the zoom floor is below 1, so a big image can be made to fit"
else
  bad "the zoom floor is back at or above 1 - a wide image cannot be fitted"
fi

# BOTH SITES, because there are two and they do different jobs: one opens a logo
# at its fitting zoom, the other is the button that returns to it. A mutation test
# replaced the first and left the second, and a check wanting only one passed.
fitsites=$(strip "$CROP" | grep -c "setZoom(fitZoom)")
fitsites=${fitsites//[^0-9]/}
if [ "${fitsites:-0}" -ge 2 ]; then
  ok "a control jumps to the fitting zoom, and a logo opens there ($fitsites sites)"
else
  bad "only ${fitsites:-0} fit site - either the button or the open-at-fit is gone"
fi

# Zoomed out, the area around the image is empty. Exported as JPEG that area is
# BLACK, which would make the fix worse than the bug it replaced.
have "$CROP" "outputType(file.type, framefilled)"   && ok "an uncovered frame exports with alpha, not a black surround"   || bad "the export ignores whether the frame is filled - a zoomed-out logo goes black"

have "$CROP" "onKeyDown={onKeyDown}"   && ok "the frame takes arrow keys, so it works without a mouse"   || bad "the key handler is not attached - a keyboard user cannot position the image"

# Both pickers must hand off rather than stage the raw file.
have "$SEC/profile-section.tsx" "setPending(file)"   && ok "the profile picker sends the file to the cropper"   || bad "the profile picker stages the raw file again - it will arrive cropped by the browser"

have "$SEC/profile-section.tsx" "<ImageCropper"   && ok "and the profile section RENDERS the cropper"   || bad "ImageCropper is imported but not rendered - the ThemeProvider bug again"

have "$ORG" "<ImageCropper"   && ok "the organisation logo renders it too"   || bad "the organisation logo still uploads unframed and unpreviewed"

have "$ORG" "logoPreview ? ("   && ok "the organisation logo previews the picked file, not the monogram"   || bad "the monogram is shown as the answer to 'which logo did I pick'"

echo
echo "════════════════════════════════════════════════════════════════"
echo "  $correct CORRECT, $wrong WRONG"
echo "════════════════════════════════════════════════════════════════"
[ "$wrong" -eq 0 ] || exit 1
