#!/usr/bin/env bash
#
# EVERY PROVIDER IMPORTED INTO THE ROOT LAYOUT IS ACTUALLY RENDERED.
#
# ═══════════════════════════════════════════════════════════════════════════
# WHY THIS EXISTS AS A SEPARATE CHECK
# ═══════════════════════════════════════════════════════════════════════════
#
# This exact bug shipped TWICE, one turn apart, in the same file:
#
#   1. ThemeProvider  - imported into app/layout.tsx, never rendered. Every
#      useTheme() consumer got the default context, whose setTheme was `() => {}`.
#      The theme picker highlighted itself, saved to the server, and repainted
#      nothing.
#   2. PreferencesProvider - the same, the very next turn, after an edit that
#      reported success and silently did not match. It hid even better, because
#      useAppPreferences() returns defaults on purpose.
#
# Neither was caught by `tsc`, by ESLint, or by `next build`. All three are
# happy: the import is used by nothing, which is not an error, and a default
# context value is a valid value. The failure is only visible by comparing what
# is imported against what is rendered - which is what this does.
#
# An unused-import lint rule would NOT have caught either one: in both cases the
# symbol was referenced in a JSX comment or nearby prose, and in the first the
# import genuinely was unused but the rule is not enabled as an error here.
#
#   bash Docs/organization-audit/_evidence/check-providers-mounted.sh
#
# Exits non-zero if any provider is imported and not rendered.

set -uo pipefail

LAYOUT="${1:-C:/Users/MILAN/Downloads/g2gv0/app/layout.tsx}"

if [ ! -f "$LAYOUT" ]; then
  echo "WRONG - layout not found at $LAYOUT"
  exit 2
fi

echo "checking $LAYOUT"
echo ""

# Every imported symbol whose name ends in Provider.
providers=$(grep -oE "import \{[^}]*\} from" "$LAYOUT" \
  | grep -oE "[A-Z][A-Za-z0-9]*Provider" \
  | sort -u)

if [ -z "$providers" ]; then
  echo "no providers imported - nothing to check"
  exit 0
fi

failed=0

for p in $providers; do
  # Rendered means an opening JSX tag, not a mention in a comment. `<Name>` or
  # `<Name ` - both are real element opens; a comment reference is bare prose.
  if grep -qE "<${p}[ >]" "$LAYOUT"; then
    printf "  %-24s imported and RENDERED    CORRECT\n" "$p"
  else
    printf "  %-24s imported and NEVER RENDERED    WRONG - every consumer silently gets the default context\n" "$p"
    failed=1
  fi
done

echo ""

if [ "$failed" -eq 1 ]; then
  echo "WRONG - at least one provider is imported but not mounted."
  echo "        This is invisible to tsc, ESLint and next build. Render it."
  exit 1
fi

echo "CORRECT - every imported provider is mounted."
