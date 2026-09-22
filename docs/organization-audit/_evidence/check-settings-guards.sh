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
HP="/c/Users/MILAN/Downloads/hp_erp"
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
# `--` before the pattern, so a pattern that BEGINS with a dash is matched rather
# than parsed as an option. `->where('actor_id', ...)` produced
# "grep: unknown option -- >" and the check reported the product as broken. Fixed
# here rather than at the call site, because the next such pattern would hit it too.
have() { strip "$1" | grep -q -- "$2"; }
code() { have "$1" "$2"; }

# ═══════════════════════════════════════════════════════════════════════════════
# 0. EVERY FILE THIS SCRIPT READS ACTUALLY EXISTS
# ═══════════════════════════════════════════════════════════════════════════════
#
# `have()` is `strip "$1" | grep -q -- "$2"`, and `strip` on a missing file prints
# NOTHING with stderr suppressed. So a mistyped path does not error - it fails the
# grep, and the check reports THE PRODUCT as broken.
#
# That is not hypothetical. `SEC` is assigned a DIRECTORY at the top of this file
# and reassigned to a FILE PATH further down, so a later `"$SEC/foo.tsx"` resolves
# to `.../security-section.tsx/foo.tsx`. Two checks reported real, present code as
# missing, and the only reason it was caught is that both failed at once.
#
# Renaming the variable would fix that one instance. This catches the whole class,
# including the next one: every path passed to `have` is extracted and stat-ed.
echo "════════════════════════════════════════════════════════════════"
echo "0. This script is checking files that exist"
echo "════════════════════════════════════════════════════════════════"
blind=0
unresolved=0

# Loop-scoped variables ($P inside a `for f in ...`) cannot be expanded from here,
# and under `set -u` the attempt ABORTS the subshell - which is how the first
# version of this preflight printed "P: unbound variable" and then declared every
# path fine. So expansion is done with `set +u` and an unexpandable path is COUNTED
# rather than skipped: "I could not check this" is a different statement from
# "this is fine", and conflating them is the bug this section exists to prevent.
while IFS= read -r target; do
  case "$target" in
    ''|*'$'*)
      # Still contains a sigil, or expanded to nothing: a loop variable.
      unresolved=$((unresolved+1))
      continue
      ;;
  esac

  [ -f "$target" ] && continue
  bad "GUARD IS BLIND: no such file '$target' - every check on it reports the product as broken"
  blind=$((blind+1))
done < <(
  grep -oE '^(have|code) "[^"]+"' "$0" \
    | sed -E 's/^(have|code) "//; s/"$//' \
    | (
        set +u
        # EVERY PATH VARIABLE, INCLUDING THOSE ASSIGNED FURTHER DOWN THE FILE.
        #
        # The first version only knew the variables assigned ABOVE this point, so a
        # `have "$DASH" ...` whose `DASH=` sits 400 lines below expanded to nothing
        # and was written off as "not checkable". That is exactly what then happened:
        # profile-dashboard.tsx was deleted, seven guards began reporting the PRODUCT
        # as broken, and this preflight had nothing to say about it.
        #
        # Every simple NAME="..." assignment in the file is evaluated first, so a
        # variable's POSITION no longer decides whether its path can be checked. Only
        # literal assignments are taken - the pattern excludes backticks and $( - so this
        # cannot execute anything the script would not have run itself.
        eval "$(grep -oE '^[A-Z_][A-Z0-9_]*="[^"`]*"' "$0" | grep -v '$(')" 2>/dev/null

        while IFS= read -r raw; do eval "printf '%s\n' \"$raw\"" 2>/dev/null || printf '\n'; done
      ) \
    | sort -u
)

if [ "$blind" -eq 0 ]; then
  ok "every statically resolvable path this script greps is a real file"
fi

# Named out loud. A preflight that quietly covered 60% while reading as complete
# would be worse than none, because it would license trusting the paths.
[ "$unresolved" -eq 0 ] \
  || echo "           ($unresolved path(s) built from loop variables - not checkable from here)"

echo
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

# THE AVATAR BUG: branching on whether a URL EXISTS rather than whether it LOADS.
#
# Checked where each avatar is actually DRAWN. Both profile screens draw theirs
# through `profile-photo-picker.tsx` now, so asserting `<AvatarImage` in
# `profile-section.tsx` would fail on a refactor that changed nothing about the
# behaviour - which is what it did, and is why it points at the picker instead.
for f in "$FE/components/settings/profile-photo-picker.tsx" "$FE/components/shell/gtg-user-menu.tsx"; do
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
# In the PICKER, which owns the hand-off for both screens now. `setPending(file)`
# in profile-section was the old inline control; asserting it there survived the
# extraction as a false failure.
have "$FE/components/settings/profile-photo-picker.tsx" "setPending(file)"   && ok "the picker sends the chosen file to the cropper, never straight to upload"   || bad "the picker stages the raw file again - it will arrive cropped by the browser"

have "$FE/components/settings/profile-photo-picker.tsx" "<ImageCropper"   && ok "and the picker RENDERS the cropper (not merely imports it)"   || bad "ImageCropper is imported but not rendered - the ThemeProvider bug again"

have "$ORG" "<ImageCropper"   && ok "the organisation logo renders it too"   || bad "the organisation logo still uploads unframed and unpreviewed"

# EVERY AVATAR READS THE SAME image_url.
#
# /profile - the screen somebody goes to when they want their profile - rendered
# AvatarFallback and nothing else. No AvatarImage, no reference to image_url in
# the whole file. So a photo set in Settings never appeared there, reported as the
# profile image not being theirs.
#
# All three avatar sites must read image_url off the ONE app-wide /account/me
# response, or two of them can disagree about whose photo it is.
# ── THE SECOND PROFILE PAGE IS GONE, AND SO ARE THE CHECKS ABOUT IT ────────
#
# These guards used to test `/profile` (profile-dashboard.tsx): that it handed the
# stored photo to the picker, and that it read the shared `/account/me` response
# rather than fetching its own.
#
# That file no longer exists. `/profile` now redirects to `/settings?s=profile`,
# because two URLs for one profile - one read-only on the HRMS endpoints, one
# editable on /account/me - is what let them disagree about the same person.
#
# The checks are NOT simply deleted: the concerns they protected are still real and
# now live on the surviving screen, so they are re-pointed rather than dropped. What
# is gone is only the assertion that a deleted file behaves a certain way.
#
# `DASH` is deliberately not reassigned. A variable still pointing at a removed file
# is how a guard goes blind - and the section-0 preflight cannot catch this one,
# because a path assigned here cannot be expanded from the top of the script. That
# limitation is reported there as "1 path not checkable", and this is what it was.

for avatar in "$SEC/profile-section.tsx" "$FE/components/shell/gtg-user-menu.tsx"; do
  n=$(basename "$avatar" .tsx)
  if strip "$avatar" | grep -q "image_url"; then
    ok "$n sources its avatar from image_url"
  else
    bad "$n renders an avatar from something other than image_url"
  fi
done

have "$ORG" "logoPreview ? ("   && ok "the organisation logo previews the picked file, not the monogram"   || bad "the monogram is shown as the answer to 'which logo did I pick'"

echo
echo "════════════════════════════════════════════════════════════════"
echo "10. One photo control, two screens, and the organisation is gated"
echo "════════════════════════════════════════════════════════════════"
# ONE COPY OF THE PICKER.
#
# `/profile` had a camera button that navigated to Settings - two screens for one
# job, on the screen somebody opens when they want their profile. Copying the
# control across instead would have meant two copies of the `accept` list, the
# size and type refusals, the cropper hand-off and the object-URL lifecycle. The
# `accept` list has to match the server's five formats or the upload fails after
# the work, so a second copy that missed a change to it is a silent defect.
PICKER="$FE/components/settings/profile-photo-picker.tsx"

have "$PICKER" "ACCEPTED = \['image/jpeg', 'image/png', 'image/gif', 'image/webp'\]"   && ok "the picker carries the server's own accepted-format list"   || bad "the accept list has drifted from what AccountController accepts"

# One consumer now, not two: the second screen that used this picker is the one
# that was removed. The shared picker is KEPT rather than inlined, because the
# organisation logo flow uses the same cropper underneath and the `accept` list
# still has to match the server's five formats in exactly one place.
for parent in "$SEC/profile-section.tsx"; do
  n=$(basename "$parent" .tsx)
  have "$parent" "<ProfilePhotoPicker"     && ok "$n renders the shared picker"     || bad "$n does not use the shared picker - a second copy will drift"
done

# And no second cropper: the picker owns it now.
if strip "$SEC/profile-section.tsx" | grep -q "<ImageCropper"; then
  bad "profile-section renders its own ImageCropper again - two croppers, one screen"
else
  ok "profile-section leaves the cropper to the picker"
fi

# THE TWO SAVE MODES, which is the one thing the parents must NOT share.
have "$SEC/profile-section.tsx" "setPhoto({ file: picked.file"   && ok "Settings STAGES the photo, so a form saves as one unit"   || bad "Settings no longer stages - a photo would commit half a form"

# THE OTHER SAVE MODE IS GONE WITH THE SCREEN THAT NEEDED IT.
#
# `/profile` had no Save button, so it uploaded a photo the moment it was framed and
# then refreshed the shared response so the header avatar followed. Both are checks
# about a file that no longer exists: that screen redirects to this one now, and
# Settings stages the photo with the rest of the form - which is why the staging
# check above is the one that survives.
#
# What does NOT go away is the failure case below. It is the same concern on the
# surviving path, so it is re-pointed rather than dropped.

# A 200 with an image_error means the row was written and the object store was not -
# the details saved, the photo did not. Reading that as success tells somebody their
# picture is set when it is not, and they find out the next time they look.
have "$SEC/profile-section.tsx" "if (response.image_error) setError(response.image_error)"   && ok "a partial save surfaces the image error instead of reporting success"   || bad "a rejected upload would report the save as clean"

echo
echo "  -- and the organisation is administrator-only --"
GATE="$HP/routes/settings.php"

have "$GATE" "hrit.role:admin"   && ok "organization_data carries a role gate"   || bad "organization_data is back to any-valid-token, which is writable by an employee"

# `profile:` would 401 a session caller where 403 is the honest answer.
if strip "$GATE" | grep -q "middleware('profile:"; then
  bad "the gate uses profile: on a web route - a session caller gets 401, not 403"
else
  ok "it uses hrit.role, which resolves a session caller too"
fi

echo
echo "════════════════════════════════════════════════════════════════"
echo "11. One profile: the work identity is read, never written"
echo "════════════════════════════════════════════════════════════════"
# THE READ/WRITE SPLIT.
#
# `EDITABLE` was the write allow-list AND the read payload, which is why this
# product had TWO profile screens: `/profile` had to fetch the HRMS endpoints
# separately just to show a job title. The projection must exist, and it must not
# leak into what a person can change.
AC="$HP/app/Http/Controllers/Api/Account/AccountController.php"
PROF="$FE/components/settings/sections/profile-section.tsx"
VIS="$HP/app/Services/Account/ProfileVisibility.php"
DIR="$HP/app/Http/Controllers/HRMS/EmployeeDirectoryController.php"

# WITH THE OPENING PAREN. Without it "function workIdentity" substring-matches
# "function workIdentityX", so a rename that breaks every caller still passed.
have "$AC" "function workIdentity("   && ok "the account payload projects the work identity"   || bad "no workIdentity() - /profile is back to a second fetch for a job title"

# s_jobrole and s_user_jobrole both have id and jobrole, and the ids OVERLAP, so
# the wrong join silently returns another person's job title.
if strip "$AC" | grep -q "table('s_user_jobrole')"; then
  ok "job title resolves against s_user_jobrole (293/293), not s_jobrole (101)"
else
  bad "the job-title join is not s_user_jobrole - it will show the wrong title"
fi

# The projection must never become writable.
if strip "$AC" | grep -qE "EDITABLE = \[" && ! strip "$AC" | grep -qE "'jobtitle_id'|'department_id'"    || ! strip "$AC" | sed -n '/EDITABLE = \[/,/\];/p' | grep -qE "jobtitle_id|department_id|employee_no"; then
  ok "EDITABLE still excludes the work identity, so it cannot be self-edited"
else
  bad "the work identity is in EDITABLE - a person could change their own job title"
fi

have "$PROF" "work?.job_title"   && ok "Settings shows the job title it used to only promise"   || bad "the work block names fields it does not display, as before"

echo
echo "  -- and who can see what --"
# A control that only hides fields in React is privacy-shaped decoration.
# Same substring trap: "function redact" matches "redactX".
have "$VIS" "function redact("   && ok "ProfileVisibility can redact a result set"   || bad "no redaction - visibility would be cosmetic"

have "$DIR" "visibility->redact("   && ok "the Employee Directory APPLIES it (not merely imports it)"   || bad "the directory returns every mobile number again"

# One query for a 2000-row page, not 2000.
have "$VIS" "whereIn('user_id', \$userIds)"   && ok "visibility for a whole page resolves in one query"   || bad "per-row preference lookups - 2000 queries on one directory page"

# HR maintains the record; redacting them blanks fields on their next save.
have "$VIS" "'administrator', 'hr_manager', 'hr_executive'"   && ok "HR and administrators are exempt, so the edit form round-trips"   || bad "HR is redacted - their next save would blank the field"

# An unrecognised value must never mean "show it".
have "$VIS" "in_array(\$value, UserPreferences::VISIBILITY, true)"   && ok "an unrecognised visibility falls back rather than opening the field"   || bad "a bad stored value could be read as permission"

have "$PROF" "VISIBILITY_FIELDS.map"   && ok "and the person has controls for all three fields"   || bad "the visibility settings are unreachable from the UI"

echo
echo "════════════════════════════════════════════════════════════════"
echo "12. Sessions record activity, and abandoned ones end"
echo "════════════════════════════════════════════════════════════════"
TOUCH="$HP/app/Http/Middleware/TouchTokenActivity.php"
BOOT="$HP/bootstrap/app.php"
# RENAMED from SEC, which is the sections DIRECTORY at the top of this file.
# Shadowing it here silently broke every later "$SEC/<file>.tsx": such a path
# resolves to .../security-section.tsx/<file>.tsx, never matches, and the check
# then reports present, correct code as missing. Two checks did exactly that.
SECFILE="$FE/components/settings/sections/security-section.tsx"

# `$` anchored: "class TouchTokenActivity" substring-matches
# "class TouchTokenActivityX", so a rename that breaks every reference passed.
# The third time this exact trap has appeared in this file.
have "$TOUCH" "class TouchTokenActivity$"   && ok "the activity middleware exists"   || bad "nothing writes last_used_at - every session reads 'Never used'"

# Appended to BOTH groups: 167 routes are role-gated with neither api.token nor
# auth, so adding the touch to those two middlewares would miss all of them.
# Matched with `.*` across the brackets rather than escaping them. The first
# version wrote the pattern as `appendToGroup('api', \[\App\Http...` and
# failed on BOTH groups while the middleware was demonstrably working - the
# evidence had already proved `last_used_at` gets written. A bracket starts a
# character class in grep and the backslashes were being eaten by the shell, so
# it was a pattern bug reported as a product defect.
for grp in api web; do
  if strip "$BOOT" | grep -q "appendToGroup('$grp'.*TouchTokenActivity"; then
    ok "it is appended to the '$grp' group"
  else
    bad "not in the '$grp' group - those routes record nothing"
  fi
done

# THE BUG THE EVIDENCE CAUGHT, and the reason this assertion exists.
#
# This middleware runs in the GROUP, so before RequireApiToken. Without an expiry
# check of its own it slid an EXPIRED token's expires_at thirty days forward, and
# the gate then saw a valid expiry and let it through - so no token could ever
# expire. Strictly worse than the original bug: it looked like expiry existed.
have "$TOUCH" "expires_at->isPast()"   && ok "it refuses to touch an already-expired token, so expiry still works"   || bad "an expired token gets renewed on the request that should refuse it"

# Without the throttle this becomes the most-written table in the database.
# THE COMPARISON, not the constant's name. Grepping `THROTTLE_SECONDS` matched
# the declaration AND the usage, so renaming either one left the check passing.
# What matters is that the elapsed time is actually compared before writing.
have "$TOUCH" "diffInSeconds(\$now) < self::THROTTLE_SECONDS"   && ok "writes are throttled, so a busy screen is not a write per request"   || bad "every authenticated request writes to personal_access_tokens"

# Bookkeeping must never be able to lock somebody out.
have "$TOUCH" "report(\$caught)"   && ok "a failed write is reported and swallowed, not turned into a 500"   || bad "a locked table could 500 every authenticated request"

# An expiry at creation closes the window before the first request.
# BOTH call sites. `authController` mints a token in two places (password
# sign-in and the second path below it); grepping for one occurrence passed while
# the other was reverted to an immortal token.
sites=$(strip "$HP/app/Http/Controllers/auth/authController.php" | grep -c "IDLE_DAYS")
sites=${sites//[^0-9]/}
if [ "${sites:-0}" -ge 2 ]; then
  ok "both sign-in paths create the token WITH an expiry ($sites sites)"
else
  bad "only ${sites:-0} of 2 sign-in paths set an expiry - the other is immortal"
fi

# The UI must not claim a history it does not have.
have "$SECFILE" "Not recorded yet"   && ok "the list says 'not recorded yet' rather than 'Never used'"   || bad "the session list still reports the old defect as a fact about the person"

have "$SECFILE" "id: 'expires_at' as const"   && ok "and shows when each session expires"   || bad "no expiry column - a session that never ends looks like any other"

echo
echo "  -- and no two table columns share an id --"
# `DataTable` renders `key={String(column.id)}`, and `Column.id` is typed
# `keyof T` - so an action column has to BORROW a real field name. Twice now two
# columns have ended up borrowing the same one: duplicate React keys in one list,
# which React resolves by keeping one and discarding the other.
#
# The second time was found only because a mutation test looked blind. Checked
# directly from now on, comment-stripped so a comment quoting an id cannot mask a
# real collision.
for f in "$FE/components/settings/sections/security-section.tsx"          "$FE/components/settings/sections/roles-access-section.tsx"          "$FE/components/settings/sections/audit-section.tsx"; do
  n=$(basename "$f" -section.tsx)
  total=$(strip "$f" | grep -cE "id: '[a-z_]+' as const")
  uniq=$(strip "$f" | grep -oE "id: '[a-z_]+' as const" | sort -u | wc -l)
  total=${total//[^0-9]/}; uniq=$(echo "$uniq" | tr -d ' ')

  if [ "${total:-0}" -eq 0 ]; then
    continue
  elif [ "${total:-0}" -eq "${uniq:-0}" ]; then
    ok "$n: all $total column ids are distinct"
  else
    bad "$n: $total columns but only $uniq distinct ids - duplicate React keys"
  fi
done

echo
echo "════════════════════════════════════════════════════════════════"
echo "13. A person can see their own security history"
echo "════════════════════════════════════════════════════════════════"
AUTH="$HP/app/Http/Controllers/auth/authController.php"
SECT="$FE/components/settings/sections/security-section.tsx"

have "$AC" "function activity("   && ok "the account exposes its own activity"   || bad "no activity endpoint - a person cannot see what happened to them"

# g2g_audit_log is a PROJECTION built by the scheduled events:project command, so
# reading it means somebody changes their password and does not see it until the
# scheduler runs. g2g_event is what EventRecorder writes.
have "$AC" "DB::table('g2g_event')"   && ok "it reads the event source, so an action appears immediately"   || bad "it reads the projection - your own change lags behind the scheduler"

# The subject is the token owner. An id parameter here would be the whole bug.
have "$AC" "->where('actor_id', \$userId)"   && ok "scoped to the token owner, with no id to tamper with"   || bad "the activity feed is not scoped by actor - it could show another person"

# A caller must not be able to ask for an unbounded response.
have "$AC" "min(200, max(10,"   && ok "the limit is clamped rather than trusted"   || bad "a caller can request an unbounded history"

# The three account events that previously left no trace at all.
# The BARE name, not `self::$ev`. The `self::` prefix appears only at USE sites,
# never on the `private const` line, so requiring two matches of it demanded two
# uses of each constant - which no event has. Counting the bare name gets the
# declaration plus its uses, which is what "declared and recorded" means.
for ev in EVENT_PASSWORD_CHANGED EVENT_PHOTO_CHANGED EVENT_SESSIONS_ENDED; do
  n=$(strip "$AC" | grep -c -- "$ev")
  n=${n//[^0-9]/}
  # Declaration plus at least one use.
  [ "${n:-0}" -ge 2 ]     && ok "$ev is declared and recorded"     || bad "$ev is declared but never recorded (or vice versa)"
done

# Recording runs AFTER the change is committed, so it must not be able to fail it.
have "$AC" "private function recordSecurityEvent("   && ok "recording goes through one helper that swallows and reports"   || bad "each call site handles its own failure - one will throw and fail the action"

echo
echo "  -- and a new device is announced --"
have "$AUTH" "private function alertOnNewDevice("   && ok "the new-device alert exists"   || bad "a stolen password produces no alert at all"

# BOTH sign-in paths. authController mints a token in two places; wiring one and
# not the other means half of all sign-ins are silent.
paths=$(strip "$AUTH" | grep -c "alertOnNewDevice(")
paths=${paths//[^0-9]/}
# One declaration plus two call sites.
[ "${paths:-0}" -ge 3 ]   && ok "both sign-in paths call it ($paths references)"   || bad "only ${paths:-0} references - one sign-in path is silent"

# An alert on every sign-in is an alert people filter.
have "$AUTH" "\$tokens->count() <= 1 || \$seen > 1"   && ok "a familiar device and a first-ever login stay silent"   || bad "every sign-in would alert, which trains people to ignore these"

have "$SECT" "accountService.activity("   && ok "Sign-in & security shows the history"   || bad "the endpoint exists but nothing displays it"

echo
echo "════════════════════════════════════════════════════════════════"
echo "14. Two-step verification is enforced, not merely offered"
echo "════════════════════════════════════════════════════════════════"
TF="$HP/app/Services/Account/TwoFactor.php"
TFC="$HP/app/Http/Controllers/Api/Account/TwoFactorController.php"

# The trade for hand-rolling instead of pragmarx/google2fa is that the RFC
# publishes vectors. If those stop being checked, the trade is no longer defensible.
have "$HP/docs/organization-audit/_evidence/prove-two-factor.php" "94287082"   && ok "the RFC 6238 vectors are still asserted"   || bad "the hand-rolled TOTP is no longer checked against the published vectors"

# A short-circuiting compare leaks how many leading digits were right.
have "$TF" "hash_equals("   && ok "codes are compared in constant time"   || bad "a timing comparison leaks the code digit by digit"

# Enabling on the secret alone locks people out of their own accounts.
have "$TF" "whereNotNull('confirmed_at')"   && ok "only a CONFIRMED enrolment protects an account"   || bad "a pending secret would lock somebody out of their own account"

# A reusable recovery code is a permanent bypass.
# No brackets in the pattern: `unset($hashes[$index])` made grep read
# `[$index]` as a CHARACTER CLASS, so it searched for one of $,i,n,d,e,x and
# never matched - reporting reusable recovery codes while the evidence was
# simultaneously proving they are single-use.
have "$TF" "unset("   && ok "a recovery code is removed when spent, so it cannot be replayed"   || bad "recovery codes are reusable - a written-down list becomes a skeleton key"

have "$TF" "Hash::make(\$value)"   && ok "and they are stored hashed"   || bad "recovery codes are readable in the database"

# Weakening needs the password; strengthening does not.
pw=$(strip "$TFC" | grep -c "passwordMatches(")
pw=${pw//[^0-9]/}
[ "${pw:-0}" -ge 3 ]   && ok "turning it off and re-issuing codes both require the password ($pw references)"   || bad "only ${pw:-0} references - one of the weakening paths is unguarded"

# Six digits is reachable by brute force without a limit.
have "$TFC" "RateLimiter::tooManyAttempts"   && ok "code attempts are rate limited"   || bad "a million codes can be tried at HTTP speed"

echo
echo "  -- and the sign-in actually stops --"
# A second factor that one of two sign-in paths ignores is not a second factor.
paths=$(strip "$AUTH" | grep -c "twoFactorChallenge(")
paths=${paths//[^0-9]/}
# One declaration plus both token-minting paths.
[ "${paths:-0}" -ge 3 ]   && ok "both sign-in paths are challenged ($paths references)"   || bad "only ${paths:-0} references - a sign-in path mints a token without the second factor"

# The ordering IS the feature: mint first and the password alone already worked.
#
# NOTE ON WHAT THIS ONE IS WORTH. It passed while the feature was completely
# bypassable, because a token was never the only credential the controller hands
# out. It is kept because it is true and cheap; the assertion that actually closes
# the hole is the next one.
if strip "$AUTH" | grep -B 30 "createToken(" | grep -q "twoFactorChallenge"; then
  ok "the challenge runs BEFORE a token is minted"
else
  bad "a token is created before the code is checked - the password alone suffices"
fi

# ── AND BEFORE THE SESSION, WHICH IS THE OTHER CREDENTIAL ───────────────────
#
# `authMiddleware::hasSession()` is, in full:
#
#     return session()->has('user_id') && session()->get('user_id');
#
# So `session()->put('user_id', ...)` IS a completed login for every Blade route
# and for `RequireHritRole`'s session fallback. It used to run 230 lines before the
# challenge: the 401 went back with a logged-in cookie, and the code could simply
# be ignored.
#
# Line numbers rather than a `grep -B` window, because the distance between the two
# was what made it invisible - a window wide enough to see it would be wide enough
# to match almost anything.
cline=$(strip "$AUTH" | grep -n "twoFactorChallenge(\$request, \$user)" | head -1 | cut -d: -f1)
sline=$(strip "$AUTH" | grep -n "put('user_id'" | head -1 | cut -d: -f1)
cline=${cline//[^0-9]/}
sline=${sline//[^0-9]/}

if [ -z "$cline" ] || [ -z "$sline" ]; then
  # Neither found means the pattern rotted, not that the code is safe. Named as a
  # failure rather than silently passing, which is how a guard becomes decoration.
  bad "cannot locate the challenge or the session write (challenge='$cline' session='$sline') - this guard has gone blind"
elif [ "$cline" -lt "$sline" ]; then
  ok "and BEFORE user_id reaches the session (line $cline before $sline), so no Blade route accepts a challenged caller"
else
  bad "user_id is written to the session at line $sline, BEFORE the challenge at $cline - the challenge can be ignored entirely"
fi

have "$AUTH" "two_factor_required"   && ok "and the response tells the client to ask for a code"   || bad "the sign-in fails with nothing the frontend can act on"

# ── THE CLIENT HALF: A CHALLENGE MUST NOT ARRIVE AS A FAILURE ───────────────
echo
echo "  -- and the sign-in screen can act on it --"

have "$FE/services/core/api-client.ts" "two_factor_required"   && ok "the transport carries two_factor_required off the body"   || bad "buildApiError drops the flag, so the screen only sees a 401 with a message"

have "$FE/components/auth/gtg-auth.tsx" "class TwoFactorRequiredError"   && ok "and a challenge is thrown as its own type, not as a generic Error"   || bad "the challenge is flattened into an Error - the screen cannot tell it from a rejection"

have "$FE/components/auth/login-page.tsx" "err instanceof TwoFactorRequiredError"   && ok "and the sign-in screen branches on that type"   || bad "the login screen does not handle the challenge - the code field is never shown"

# Both credentials, or the authenticator becomes the only one that matters.
have "$FE/components/auth/login-page.tsx" "setChallenge(err.message)"   && ok "showing the server's own sentence, so a wrong code says so"   || bad "the challenge message is discarded"

have "$FE/components/auth/login-page.tsx" "recovery_code\|recoveryCode"   && ok "and a recovery code can be given instead, for somebody without their phone"   || bad "no recovery path at sign-in - a lost phone is a lost account"

echo
echo "════════════════════════════════════════════════════════════════"
echo "15. The organisation can REQUIRE it, and cannot lock itself out"
echo "════════════════════════════════════════════════════════════════"
GATE="$HP/app/Http/Middleware/RequireTwoFactorEnrolment.php"
TS="$HP/app/Services/Organization/TenantSettings.php"
OSC="$HP/app/Http/Controllers/Api/Organization/OrganizationSettingsController.php"

# ── OFF BY DEFAULT IS WHAT MAKES THIS SAFE TO SHIP ──────────────────────────
#
# This middleware runs on every API and web request for eleven live tenants. A
# default of anything but `off` changes all of their behaviour the moment the code
# is pulled, with no migration and no warning.
have "$TS" "'security.require_two_factor' => 'off'" \
  && ok "the policy ships as off, so no live tenant changes on deploy" \
  || bad "the default is not off - pulling this would change eleven tenants at once"

# A closed list: this value authorises, so free text would leave the gate deciding
# what "Administrators" means.
have "$OSC" "Rule::in(TenantSettings::REQUIRE_TWO_FACTOR)" \
  && ok "and only the three known values can be saved" \
  || bad "any string can be stored as the policy - the gate would have to guess"

# ── ENFORCED ON THE SERVER, WHICH IS THE WHOLE POINT ────────────────────────
#
# `can_view` was honoured only by MenuMiddleware, which returns early on type=API
# and never aborts. That is what a policy looks like when only the UI respects it.
if have "$HP/bootstrap/app.php" "RequireTwoFactorEnrolment"; then
  groups=$(strip "$HP/bootstrap/app.php" | grep -c "RequireTwoFactorEnrolment")
  groups=${groups//[^0-9]/}
  # Both groups: api AND web. Enforcing only on /api leaves every Blade screen
  # reachable, which is half-enforcement, which is none.
  [ "${groups:-0}" -ge 2 ] \
    && ok "the gate is registered on both middleware groups (api and web)" \
    || bad "only ${groups:-0} group - the other half of the product is ungated"
else
  bad "the gate is never registered - the policy is stored and read by nothing"
fi

have "$GATE" "], 403)" \
  && ok "and it refuses with 403, not 401" \
  || bad "no 403 - a 401 sends people to the sign-in screen, which cannot fix this"

have "$GATE" "two_factor_setup_required" \
  && ok "carrying a machine-readable reason the client can route on" \
  || bad "the refusal says nothing a client can act on"

# ── AND THE WAY OUT MUST STAY OPEN ──────────────────────────────────────────
#
# Enrolment requires being signed in. If the gate refuses the enrolment endpoints
# too, the policy is not a policy, it is a tenant-wide lockout with no recovery
# that is not a database edit.
for allowed in "'account/me'" "'account/2fa/start'" "'account/2fa/confirm'"; do
  have "$GATE" "$allowed" \
    && ok "$allowed stays reachable, so nobody is locked out" \
    || bad "$allowed is NOT on the allow-list - enrolling becomes impossible"
done

# And `disable` must NOT be, or somebody walks straight back out of the policy.
# A prefix of 'account/2fa' would have covered it by accident, which is why the
# allow-list is matched per full path.
if strip "$GATE" | grep -q "'account/2fa/disable'"; then
  bad "account/2fa/disable is on the allow-list - the policy can be shrugged off"
else
  ok "and account/2fa/disable is NOT, so the policy cannot be walked out of"
fi

# The refusal at the controller as well, so it is not merely unreachable by luck.
have "$HP/app/Http/Controllers/Api/Account/TwoFactorController.php" "policyRequires(" \
  && ok "turning it off is refused while the organisation requires it" \
  || bad "disable does not consult the policy"

# ── THE NUMBER IS SHOWN BEFORE THE SWITCH IS FLIPPED ────────────────────────
#
# "Require it for everyone" reads like a checkbox and behaves like a migration.
have "$OSC" "two_factor_coverage" \
  && ok "the settings payload carries how many people would have to enrol" \
  || bad "no coverage count - an administrator flips this blind"

have "$FE/components/settings/sections/security-policy-section.tsx" "pendingEnrolments" \
  && ok "and the screen warns when the draft would newly oblige people" \
  || bad "the screen does not say how many people this affects"

# The person who is obliged has to be told why the product stopped working.
have "$FE/components/settings/sections/two-factor-block.tsx" "status.required && !status.enabled" \
  && ok "and somebody obliged but not enrolled is told why, not left guessing" \
  || bad "the 403s are unexplained - a person will conclude the product is broken"

echo
echo "════════════════════════════════════════════════════════════════"
echo "16. One person's data cannot outlive their session in the browser"
echo "════════════════════════════════════════════════════════════════"
PROV="$FE/components/providers/preferences-provider.tsx"
SESSLIB="$FE/lib/laravel-session.ts"
STORE="$FE/lib/browser-storage.ts"
AUTHTSX="$FE/components/auth/gtg-auth.tsx"
MENU="$FE/components/shell/gtg-user-menu.tsx"
PROFSEC="$FE/components/settings/sections/profile-section.tsx"

# ── THE BUG THIS SECTION EXISTS FOR ─────────────────────────────────────────
#
# Reported on live: edit your profile, sign out, sign in as a colleague, and the
# colleague's profile screen showed YOUR details. The server was innocent - the
# right row was written and /account/me answers correctly per token. The provider
# fetched once per PAGE LOAD (dependency array `[setTheme]`, which never changes)
# and sign-out/sign-in are both `router.push`, which never reloads.
#
# The dependency array is therefore the whole fix, and it is one token long. A
# future refactor that "simplifies" it back is the regression this guards.
if strip "$PROV" | grep -q "\[identity, setTheme\]"; then
  ok "the account fetch depends on WHICH USER is signed in, not just on mount"
else
  bad "the /account/me fetch no longer keys on identity - one person's data will outlive their session again"
fi

have "$PROV" "const [identity, setIdentity]" \
  && ok "and the provider tracks that identity" \
  || bad "no identity state - the provider cannot know the user changed"

# Dropping the old data must happen DURING render, not in an effect: an effect
# runs after the children have painted, so there is one frame showing the new
# user's screen with the previous user's name.
have "$PROV" "if (identity !== loadedFor)" \
  && ok "and clears the previous user's account during render, before anything paints" \
  || bad "the previous user's payload is not cleared on a user change"

# The provider sits above AuthProvider, so it cannot watch the auth context. It
# learns from the session store instead - which only works if the store announces.
have "$SESSLIB" "function announceSessionChange" \
  && ok "the session store announces sign-in and sign-out" \
  || bad "nothing announces a session change - the provider will never hear about it"

for fn in "saveLaravelSession" "clearLaravelSession"; do
  # The announce must be INSIDE both writers. Counting the calls is not enough:
  # one of the two silently not announcing is exactly half a fix.
  if strip "$SESSLIB" | grep -A 6 "function $fn" | grep -q "announceSessionChange()"; then
    ok "$fn announces the change"
  else
    bad "$fn does not announce - a $([ "$fn" = clearLaravelSession ] && echo 'sign-out' || echo 'sign-in') leaves the cache stale"
  fi
done

# `storage` fires only in OTHER tabs, the custom event only in THIS one. Both, or
# half the cases are missed.
if have "$SESSLIB" "SESSION_CHANGED_EVENT, listener" && have "$SESSLIB" "'storage', listener"; then
  ok "and both this tab and other tabs are subscribed"
else
  bad "only one of the two event sources - signing out in one tab will not reach the others"
fi

echo
echo "  -- and sign-out leaves nothing behind --"
# Sign-out cleared 4 keys while the product writes 14. The nine it missed were
# each inherited by the next person to sign in on that browser.
have "$AUTHTSX" "clearBrowserStateOnSignOut()" \
  && ok "sign-out clears the whole registry, not a hand-written subset" \
  || bad "sign-out is back to clearing keys by hand - the list will drift again"

for key in "gtg-last-visited" "gtg-theme" "gtg-device-id" "agentic:agent-draft:" "pendingTasksCount"; do
  have "$STORE" "$key" \
    && ok "  $key is in the registry" \
    || bad "  $key is NOT cleared on sign-out - the next person inherits it"
done

# One list, read by both consumers, or they drift as they already had.
have "$FE/components/settings/sections/saved-views-section.tsx" "LISTABLE_KEYS" \
  && ok "and Saved views reads the same list sign-out uses" \
  || bad "Saved views has its own hard-coded list again"

# A hard navigation guarantees nothing in memory survives, even state nobody
# thought to reset.
have "$MENU" "window.location.assign('/login')" \
  && ok "signing out is a full page load, so no provider state can survive it" \
  || bad "sign-out is a client-side route change - in-memory state outlives the session"

echo
echo "  -- and the profile screen holds no private copy --"
# Uncontrolled inputs read their default ONCE at mount, so these three kept the
# previous user's text even after the provider was fixed.
if strip "$PROFSEC" | grep -q "defaultValue={preferences"; then
  bad "the identity fields are uncontrolled again - they will show the previous user's name"
else
  ok "display name, pronouns and about are controlled inputs"
fi

have "$PROFSEC" "if (preferences && preferences !== identityFrom)" \
  && ok "and re-sync when the signed-in person changes" \
  || bad "nothing re-syncs the identity fields"

# The photo path used to append EVERY editable field, so a stale form would write
# one person's address onto another's row.
if strip "$PROFSEC" | grep -A 3 "for (const field of EDITABLE)" | grep -q "continue"; then
  ok "the photo upload sends only changed fields, as the text path does"
else
  bad "the photo upload sends every field - a stale form writes another person's details"
fi

echo
echo "════════════════════════════════════════════════════════════════"
echo "17. An organisation has one identity, and it is its own"
echo "════════════════════════════════════════════════════════════════"
ORGCTL="$HP/app/Http/Controllers/Api/Organization/OrganizationProfileController.php"
ORGSVC="$FE/services/organization/index.ts"
ORGVIEW="$FE/components/domain/organization/organization-information.tsx"
ORGEDIT="$FE/components/domain/organization/organization-information-edit-panel.tsx"
INVITE="$HP/app/Services/Auth/InviteService.php"

# The logo was read from `org_details`, which exists for 4 of 12 live organisations.
# `school_setup` is the only table with a row per tenant, because it IS the tenant.
have "$ORGCTL" "'identity' =>" \
  && ok "the profile endpoint returns an identity block" \
  || bad "no identity block - the screen is back to reading org_details for the logo"

have "$ORGCTL" "table('school_setup')" \
  && ok "and sources it from school_setup, which has a row for every tenant" \
  || bad "identity is not read from school_setup - 8 of 12 organisations would have none"

have "$ORGCTL" "function logoUrl" \
  && ok "and builds the logo URL server-side, so no screen knows the bucket layout" \
  || bad "no logoUrl helper - the frontend would have to construct storage paths"

# The screen called a WEB route needing the ERP session cookie, which the Next.js
# app does not have. The token-authenticated API existed and nothing called it.
have "$ORGSVC" "'/organization/profile'" \
  && ok "the frontend calls the token-authenticated API" \
  || bad "the org screen is back on the web route it cannot authenticate against"

if strip "$ORGSVC" | grep -q "webClient.get<OrganizationProfileResponse>"; then
  bad "getOrganizationProfile still uses webClient - it depends on a cookie this app has no way to set"
else
  ok "and no longer depends on the ERP browser session"
fi

# The card was titled "Company Logo" and drew a monogram. No <img> existed.
have "$ORGVIEW" "src={logoUrl}" \
  && ok "the view actually renders the stored logo" \
  || bad "the logo is still never displayed - uploads go nowhere visible"

have "$ORGVIEW" "onError={() => setLogoBroken(true)}" \
  && ok "and falls back to the monogram if the file will not load" \
  || bad "a missing object would leave a broken-image icon on a customer's page"

have "$ORGEDIT" "storedLogoUrl ?" \
  && ok "and the editor shows the logo already saved, not just a freshly picked one" \
  || bad "the editor shows a monogram beside 'Upload New Logo', which reads as 'there is none'"

echo
echo "  -- and one mailbox cannot claim another account --"
# password_reset_tokens.email is the PRIMARY KEY and decides WHOSE password is set.
# Issuing for a delivery address would have reset a different organisation's admin.
have "$INVITE" "?string \$deliverTo = null" \
  && ok "the delivery address is separate from the address the token is keyed on" \
  || bad "keying and delivery are one argument again - sending to another mailbox would take over its account"

if strip "$INVITE" | grep -q 'mail($deliverTo, $link'; then
  ok "and the mail goes to the delivery address"
else
  bad "the mail is not sent to the delivery address - the split does nothing"
fi

# The default must be unchanged, or every existing invite and reset breaks.
if strip "$INVITE" | grep -q 'trim((string) \$deliverTo) ?: \$email'; then
  ok "defaulting to the account's own address, so existing callers are unaffected"
else
  bad "the default is not the account address - three-argument callers changed behaviour"
fi

echo
echo "════════════════════════════════════════════════════════════════"
echo "18. Signing out ends the session on the server, not just the browser"
echo "════════════════════════════════════════════════════════════════"
ACCT="$HP/app/Http/Controllers/Api/Account/AccountController.php"
AUTHPHP="$HP/app/Http/Controllers/auth/authController.php"
WEBROUTES="$HP/routes/web.php"
APIROUTES="$HP/routes/api.php"
AUTHTSX2="$FE/components/auth/gtg-auth.tsx"

# There was NO logout endpoint of any kind. Two Blade views linked to /logout and
# both 404ed, while the token stayed valid for its full 30-day idle window.
have "$ACCT" "public function logout" \
  && ok "the account controller has a logout method" \
  || bad "no API logout - clearing the browser leaves the token valid for 30 days"

have "$APIROUTES" "'/account/logout'" \
  && ok "and it is routed" \
  || bad "the method exists and no route reaches it"

have "$AUTHPHP" "public function logout" \
  && ok "the web controller has one too, for the Blade surface" \
  || bad "no web logout - header.blade.php's Sign out link still 404s"

have "$WEBROUTES" "'/logout'" \
  && ok "and /logout is declared, so the two existing links finally work" \
  || bad "/logout is undeclared - header.blade.php:242 and footer.blade.php:27 are dead"

# Scoped to the calling token. Signing out of a laptop must not end the phone's
# session - that is `endSessions`, a separate deliberate action.
# Anchored on the exact predicate rather than on a window after the method name.
# The first version of this check used `grep -A 8`, and the line it wanted sits 19
# lines into the method - so it reported correct, mutation-tested code as broken.
# That is the same class of error the preflight in section 0 exists for: the guard
# was wrong, not the product.
#
# `where('id', $currentTokenId)` is unique to logout. The two other uses of that
# variable in this file are `where('id', '!=', $currentTokenId)` - the
# "everywhere ELSE" action - so this cannot match them by accident.
if strip "$ACCT" | grep -q "where('id', \$currentTokenId)"; then
  ok "revocation is scoped to THIS session, not every device"
else
  bad "logout does not scope to the current token - it would sign the person out everywhere"
fi

# The Laravel session must be invalidated, not partly rewritten: authMiddleware is
# satisfied by session('user_id') alone, and session()->put() MERGES.
have "$ACCT" "session()->invalidate()" \
  && ok "and the web session is invalidated, not just partly cleared" \
  || bad "the session survives - authMiddleware accepts session('user_id') on its own"

have "$AUTHPHP" "regenerateToken()" \
  && ok "with the CSRF token reissued, so the next form post does not 419" \
  || bad "no regenerateToken - the login form the person lands on would 419"

# Recorded, or the security history has a hole exactly where somebody would look.
have "$ACCT" "EVENT_SIGNED_OUT" \
  && ok "and the sign-out is recorded" \
  || bad "signing out leaves no trace in the activity history"

echo
echo "  -- and the client actually calls it --"
have "$FE/services/account/index.ts" "'/account/logout'" \
  && ok "the account service exposes logout" \
  || bad "no client method - the endpoint would never be called"

have "$AUTHTSX2" "accountService.logout(context)" \
  && ok "and sign-out calls it before clearing local state" \
  || bad "the frontend never tells the server - the token outlives the sign-out"

# Fired, not awaited. Somebody clicking Sign out must end up signed out even with
# no network; blocking on the request would trap them in the session.
if strip "$AUTHTSX2" | grep -q "void accountService.logout(context)"; then
  ok "fired without blocking, so a network failure cannot prevent signing out"
else
  bad "the sign-out awaits the server - a failed request would leave somebody signed in"
fi

echo
echo "════════════════════════════════════════════════════════════════"
echo "19. There is ONE profile URL, not two"
echo "════════════════════════════════════════════════════════════════"
PROFPAGE="$FE/app/profile/page.tsx"
PROFSEC2="$FE/components/settings/sections/profile-section.tsx"
MENU2="$FE/components/shell/gtg-user-menu.tsx"

# Two URLs for one thing: /profile was read-only on the HRMS endpoints,
# /settings?s=profile was the only editor and read /account/me. Two sources, no
# reason to agree - and when a person reported seeing a colleague's details, the
# first question was "which of the two pages?".
have "$PROFPAGE" "router.replace('/settings?s=profile')" \
  && ok "/profile redirects to the one profile screen" \
  || bad "/profile is a page again - there are two profiles and they can disagree"

# The ROUTE must survive. 299 people have bookmarks and history; deleting it turns
# every one of those into a 404.
[ -f "$PROFPAGE" ] \
  && ok "and the route still exists, so old bookmarks land somewhere useful" \
  || bad "the /profile route was deleted - every existing bookmark now 404s"

# `replace`, or Back bounces between the two URLs forever.
if strip "$PROFPAGE" | grep -q "router.push('/settings?s=profile')"; then
  bad "the redirect uses push - Back would bounce between the two URLs"
else
  ok "using replace, so Back does not bounce"
fi

# The menu should go straight there rather than through the redirect.
have "$MENU2" "href: '/settings?s=profile'" \
  && ok "and My Profile links straight to it, not via the redirect" \
  || bad "the menu still points at /profile, so every visit takes two navigations"

# Bank details existed ONLY on the deleted page. A redirect without moving them
# would have silently removed them from the product.
have "$PROFSEC2" "<BankCard profile={bankProfile} />" \
  && ok "bank details moved across rather than disappearing with the page" \
  || bad "bank details are gone - they existed only on the page that now redirects"

# And the dead implementation must not linger: "imported but never rendered" has
# shipped twice in this repository.
if [ -f "$FE/components/profile/profile-dashboard.tsx" ]; then
  bad "the second profile implementation is still in the tree - the next person maintains two"
else
  ok "and the second implementation is removed, not left orphaned in the tree"
fi

echo
echo "════════════════════════════════════════════════════════════════"
echo "  $correct CORRECT, $wrong WRONG"
echo "════════════════════════════════════════════════════════════════"
[ "$wrong" -eq 0 ] || exit 1
