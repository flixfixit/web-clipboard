#!/usr/bin/env bash
# Fails when a change touches more than documentation but adds no line under
# "## Unreleased" in CHANGELOG.md. Used by .github/workflows/changelog.yml,
# also runs locally before opening a PR:
#   bash dev/check-changelog.sh origin/main HEAD
# SKIP=true (label "kein-changelog" in CI) turns the check off.
set -euo pipefail

base="${1:?Usage: bash dev/check-changelog.sh <base> <head>}"
head="${2:-HEAD}"

if [ "${SKIP:-false}" = "true" ]; then
    echo "Label 'kein-changelog' gesetzt – Prüfung übersprungen."
    exit 0
fi

# Files that never need a changelog entry on their own
docs_only='^(README\.md|AGENTS\.md|SECURITY\.md|CHANGELOG\.md|LICENSE|\.github/pull_request_template\.md)$'

changed="$(git diff --name-only "$base...$head")"
relevant="$(printf '%s\n' "$changed" | grep -Ev "$docs_only" | grep -v '^$' || true)"

if [ -z "$relevant" ]; then
    echo "Nur Dokumentation geändert – kein CHANGELOG-Eintrag nötig."
    exit 0
fi

# Added lines inside the "## Unreleased" section of the new CHANGELOG.md
added="$(git diff -U0 "$base...$head" -- CHANGELOG.md | grep -E '^\+- ' || true)"
section="$(git show "$head:CHANGELOG.md" | awk '/^## Unreleased/ { on = 1; next } /^## / { on = 0 } on')"
entry=""
while IFS= read -r line; do
    [ -n "$line" ] && printf '%s\n' "$section" | grep -qxF -- "${line#+}" && entry="$line" && break
done <<< "$added"

if [ -n "$entry" ]; then
    echo "CHANGELOG-Eintrag unter „Unreleased“ gefunden:"
    echo "  ${entry#+}"
    exit 0
fi

echo "::error file=CHANGELOG.md::Kein neuer Eintrag unter „## Unreleased“ in CHANGELOG.md."
echo
echo "Geänderte Dateien, die einen Eintrag erfordern:"
printf '  %s\n' $relevant
echo
echo "Bitte unter „## Unreleased“ eine Zeile „- …“ ergänzen, die beschreibt, was live geht."
echo "Für Tippfehler oder reine Doku-Korrekturen das Label „kein-changelog“ setzen."
exit 1
