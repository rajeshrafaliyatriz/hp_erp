#!/usr/bin/env bash
# THE ONLY WAY A COMMIT MESSAGE GETS WRITTEN HERE.
#
# Not "the safe option exists" - the DEFAULT PATH. Reaching past this to
# `git commit -m` now takes deliberate action, which is what STRUCTURAL means.
#
# WHY: `git commit -m "...backticks..."` let the shell substitute the message
# away, twice. The remedy - write it to a file, use -F - was established and then
# NOT REACHED FOR, because -m was still the shorter path. A tool that must be
# CHOSEN fails the same way a rule in prose does. Only a tool that must be
# BYPASSED survives.
#
# Usage:  commit.sh <<'EOF'
#         subject line
#
#         body
#         EOF
set -euo pipefail

MSG="$(mktemp)"
trap 'rm -f "$MSG"' EXIT
cat > "$MSG"

if [ ! -s "$MSG" ]; then
  echo "REFUSING: empty commit message." >&2
  exit 1
fi

# The message went through a heredoc, so nothing was substituted - but say what
# was actually written rather than assuming, because an unread message is the
# thing this exists to prevent.
echo "--- message as it will be committed ---"
head -3 "$MSG"
echo "--- $(wc -l < "$MSG") line(s) ---"

git commit -q -F "$MSG"
git log -1 --format='%s'
