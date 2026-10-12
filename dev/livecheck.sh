#!/usr/bin/env bash
# Read-only check of a live instance from the outside (no login, nothing is
# changed). Used after every deploy (.github/workflows/deploy.yml), also runs
# locally in Git Bash or WSL.
# Usage: bash dev/livecheck.sh https://example.org/clipboard
set -uo pipefail

base="${1:?Usage: bash dev/livecheck.sh <base-url>}"
base="${base%/}"
failed=0
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

check() { # label, condition result (0 = ok), detail
    if [ "$2" -eq 0 ]; then echo "  OK     $1"; else echo "  FEHLER $1${3:+ — $3}"; failed=$((failed + 1)); fi
}

echo "Live-Check gegen $base"
echo
echo "Startseite:"
status=$(curl -s -D "$tmp/h" -o "$tmp/b" -w '%{http_code}' "$base/")
check "Startseite erreichbar (200)" "$([ "$status" = 200 ]; echo $?)" "Status $status"
check "Login-Seite statt offenem Setup" "$(grep -q 'name="pin"' "$tmp/b" && ! grep -q 'Clipboard einrichten' "$tmp/b"; echo $?)" \
    "$(grep -q 'Clipboard einrichten' "$tmp/b" && echo 'Setup ist offen – sofort im Browser einrichten!')"
h() { grep -i "^$1:" "$tmp/h" | head -1 | tr -d '\r'; }
check "Content-Security-Policy mit frame-ancestors 'none'" "$(h content-security-policy | grep -q "frame-ancestors 'none'"; echo $?)"
check "X-Frame-Options: DENY" "$(h x-frame-options | grep -qi 'DENY'; echo $?)"
check "X-Content-Type-Options: nosniff" "$(h x-content-type-options | grep -qi 'nosniff'; echo $?)"
check "Referrer-Policy: no-referrer" "$(h referrer-policy | grep -qi 'no-referrer'; echo $?)"
cookie="$(h set-cookie)"
check "Session-Cookie: HttpOnly, SameSite=Lax" "$(echo "$cookie" | grep -qi 'httponly' && echo "$cookie" | grep -qi 'samesite=lax'; echo $?)" "$cookie"
if [[ "$base" == https://* ]]; then
    check "Session-Cookie: Secure" "$(echo "$cookie" | grep -qi 'secure'; echo $?)" "$cookie"
fi

echo
echo "Geschützte Pfade:"
for p in /config.php /data.json /files.json /login-attempts.json /uploads/ /uploads/.htaccess /.htaccess /.git/HEAD; do
    status=$(curl -s -o /dev/null -w '%{http_code}' "$base$p")
    check "$p → 403" "$([ "$status" = 403 ]; echo $?)" "Status $status"
done
for p in /setup.php /dev/ /README.md /DATA.JSON; do
    status=$(curl -s -o /dev/null -w '%{http_code}' "$base$p")
    check "$p wird nicht ausgeliefert" "$([ "${status:0:1}" != 2 ]; echo $?)" "Status $status"
done
status=$(curl -s -o "$tmp/b" -w '%{http_code}' "$base/uploads.x")
check "mod_speling verrät keine Dateinamen" "$([ "$status" != 300 ] && ! grep -q 'data.json' "$tmp/b"; echo $?)" "Status $status"
status=$(curl -s -o "$tmp/b" -w '%{http_code}' "$base/?json=1")
check "?json=1 liefert ohne Login keine Daten" "$(! grep -q '"texts"' "$tmp/b"; echo $?)"

echo
if [ "$failed" -eq 0 ]; then echo "Alle Prüfungen bestanden."; else echo "$failed Prüfung(en) fehlgeschlagen."; fi
exit $((failed > 0))
