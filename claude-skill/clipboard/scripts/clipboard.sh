#!/usr/bin/env bash
# Command line client for the Clipboard REST API (index.php?api=…).
# Needs bash and curl (Git Bash on Windows works).
#
# Configuration, first match wins:
#   CLIPBOARD_URL / CLIPBOARD_TOKEN environment variables
#   ${XDG_CONFIG_HOME:-~/.config}/clipboard/config with lines URL=… and TOKEN=…
#   on Windows also %USERPROFILE%\.config\clipboard\config (Git Bash may set HOME elsewhere)
# URL is the address of the app, e.g. https://example.org/clipboard/
#
# Usage: clipboard.sh <command> [args]
#   ping                         check URL and token
#   send-text [text]             save a text (argument or stdin)
#   send-file <path>...          upload files (large files in 5 MB chunks)
#   list                         texts and files as JSON
#   texts | files                only texts / only files
#   get-text <id>                one text as JSON
#   download <id> [target]       download a file (default: server file name in the current dir)
#   delete-text <id>             delete a text
#   delete-file <id>             delete a file
#   clear --yes                  delete ALL texts and files
set -euo pipefail

CHUNK_SIZE=$((5 * 1024 * 1024))
SINGLE_MAX=$((50 * 1024 * 1024)) # below the 64 MB upload limit of typical shared hosting

die() { echo "clipboard: $*" >&2; exit 1; }

config_file="${XDG_CONFIG_HOME:-$HOME/.config}/clipboard/config"
win_config="${USERPROFILE:+${USERPROFILE//\\//}/.config/clipboard/config}" # C:\Users\x → C:/Users/x
if [ ! -f "$config_file" ] && [ -n "$win_config" ] && [ -f "$win_config" ]; then
    config_file="$win_config"
fi
conf() { [ -f "$config_file" ] && sed -n "s/^$1=//p" "$config_file" | tail -n 1 | tr -d '\r' || true; }
url="${CLIPBOARD_URL:-$(conf URL)}"
token="${CLIPBOARD_TOKEN:-$(conf TOKEN)}"
[ -n "$url" ] || die "keine URL konfiguriert (CLIPBOARD_URL oder URL=… in $config_file)"
[ -n "$token" ] || die "kein Token konfiguriert (CLIPBOARD_TOKEN oder TOKEN=… in $config_file)"
url="${url%/}"
case "$url" in *.php) ;; *) url="$url/index.php" ;; esac

# api <query> [curl args…] — prints the response body, fails on HTTP errors
api() {
    local query="$1"; shift
    curl -sS --fail-with-body -H "X-Clipboard-Token: $token" "$@" "$url?api=$query" \
        || { echo >&2; die "Anfrage fehlgeschlagen: ?api=$query"; }
    echo
}

urlenc() { local LC_ALL=C s="$1" i c out=""; for ((i = 0; i < ${#s}; i++)); do c="${s:i:1}"
    case "$c" in [a-zA-Z0-9.~_-]) out+="$c" ;; *) out+=$(printf '%%%02X' "'$c") ;; esac; done; echo "$out"; }

need_id() { [ -n "${1:-}" ] || die "ID fehlt"; urlenc "$1"; }

send_file() {
    local path="$1" name size
    [ -f "$path" ] || die "Datei nicht gefunden: $path"
    name="$(basename "$path")"
    size=$(wc -c < "$path" | tr -d ' ')
    if [ "$size" -le "$SINGLE_MAX" ]; then
        api files -F "file=@$path;filename=$name"
        return
    fi
    local total=$(((size + CHUNK_SIZE - 1) / CHUNK_SIZE)) id i
    id="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"
    for ((i = 0; i < total; i++)); do
        echo "Chunk $((i + 1))/$total …" >&2
        dd if="$path" bs="$CHUNK_SIZE" skip="$i" count=1 status=none \
            | if [ "$i" -lt $((total - 1)) ]; then
                api files -F "uploadId=$id" -F "chunkIndex=$i" -F "totalChunks=$total" \
                    -F "fileName=$name" -F "chunk=@-;filename=blob" > /dev/null
              else
                api files -F "uploadId=$id" -F "chunkIndex=$i" -F "totalChunks=$total" \
                    -F "fileName=$name" -F "chunk=@-;filename=blob"
              fi
    done
}

cmd="${1:-}"; shift || true
case "$cmd" in
    ping)        api ping ;;
    send-text)
        if [ $# -gt 0 ]; then printf '%s' "$*"; else cat; fi \
            | api texts -H 'Content-Type: text/plain; charset=utf-8' --data-binary @- ;;
    send-file)   [ $# -gt 0 ] || die "Datei fehlt"; for f in "$@"; do send_file "$f"; done ;;
    list)        api texts; api files ;;
    texts)       api texts ;;
    files)       api files ;;
    get-text)    api "texts&id=$(need_id "${1:-}")" ;;
    download)
        id="$(need_id "${1:-}")"
        if [ -n "${2:-}" ]; then
            curl -sS --fail-with-body -H "X-Clipboard-Token: $token" -o "$2" "$url?api=files&id=$id" || die "Download fehlgeschlagen"
            echo "gespeichert: $2"
        else
            curl -sS --fail-with-body -H "X-Clipboard-Token: $token" -OJ -w 'gespeichert: %{filename_effective}\n' \
                "$url?api=files&id=$id" || die "Download fehlgeschlagen"
        fi ;;
    delete-text) api "texts&id=$(need_id "${1:-}")" -X DELETE ;;
    delete-file) api "files&id=$(need_id "${1:-}")" -X DELETE ;;
    clear)       [ "${1:-}" = "--yes" ] || die "Alles löschen nur mit: clear --yes"; api all -X DELETE ;;
    *)           sed -n '2,/^set -euo/p' "$0" | sed '$d; s/^# \{0,1\}//'; [ -z "$cmd" ] || exit 1 ;;
esac
