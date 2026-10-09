#!/usr/bin/env bash
# One-time setup of the local Apache in WSL (Ubuntu 24.04). Run as root:
#   wsl -d Ubuntu -u root -- bash dev/apache/setup.sh
# Re-running is safe; it refreshes the vhost from dev/apache/clipboard-dev.conf.
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

export DEBIAN_FRONTEND=noninteractive
if ! dpkg -s apache2 libapache2-mod-fcgid php8.3-cgi >/dev/null 2>&1; then
    apt-get update -qq
    apt-get install -y -qq apache2 libapache2-mod-fcgid php8.3-cgi
fi

# FastCGI like IONOS, never mod_php
a2dismod -q php8.3 mpm_prefork >/dev/null 2>&1 || true
# speling: IONOS has mod_speling active, so .htaccess must cope with it
a2enmod -q mpm_event fcgid alias headers rewrite speling >/dev/null

sed "s|@PROJECT_DIR@|${PROJECT_DIR}|g" "${PROJECT_DIR}/dev/apache/clipboard-dev.conf" \
    > /etc/apache2/sites-available/clipboard-dev.conf
a2dissite -q 000-default >/dev/null 2>&1 || true
a2ensite -q clipboard-dev >/dev/null

apache2ctl configtest
systemctl enable apache2 >/dev/null 2>&1 || true
systemctl restart apache2 2>/dev/null || service apache2 restart
echo "Apache läuft: http://localhost:8081  (Projekt: ${PROJECT_DIR})"
