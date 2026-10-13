# Changelog

## Unreleased

- REST-API unter `index.php?api=…`: Texte und Dateien senden, lesen, herunterladen und löschen sowie alles löschen, wie in der Oberfläche. Authentifizierung per API-Token im Header `X-Clipboard-Token` oder `Authorization: Bearer`; große Dateien per Chunk-Upload. Falsche Tokens zählen zur Login-Sperre.
- Verwaltung der API-Tokens in der Oberfläche („API-Zugänge“): Token mit Namen erzeugen (wird einmal angezeigt), letzte Nutzung sehen, widerrufen. Gespeichert wird nur der Hash in `api-tokens.json`.
- `.htaccess` reicht den `Authorization`-Header an PHP als FastCGI weiter (IONOS); die Kopie in `setup.php` ist angepasst. Bestehende Installationen bekommen die neue `.htaccess` mit dem nächsten Deployment.
- Claude-Skill `claude-skill/clipboard/` mit bash-Client: Texte und Dateien aus Claude Code ans Clipboard schicken und von dort holen. Nicht Teil des Release-ZIPs.
- Smoke-Test prüft Token-Verwaltung und REST-API, der Live-Check zusätzlich, dass die API ohne Token nichts liefert und `api-tokens.json` gesperrt ist.

## 1.1.0 - 2026-10-12

- Automatisches Deployment: Jeder Push auf `main` wird nach den Prüfungen per SFTP ausgerollt (`.github/workflows/deploy.yml`, GitHub-Environment `production`). Laufzeitdaten auf dem Server bleiben unangetastet, der Host-Key des Servers ist gepinnt.
- `dev/livecheck.sh` prüft eine Live-Instanz von außen (Header, Cookie, gesperrte Pfade, kein offenes Setup) und läuft nach jedem Deployment.
- Alle Deploy-Einstellungen (Ziel-URL, Pfad, Host-Key) liegen als Secrets im Environment; in den öffentlichen Actions-Logs erscheinen sie nur als `***`, ein öffentlicher Deployment-Link entfällt.
- GitHub Actions nutzen `actions/checkout@v5` (Node.js 24).
- Pull Requests brauchen einen CHANGELOG-Eintrag unter „Unreleased“ (`.github/workflows/changelog.yml`, Ausnahme per Label `kein-changelog`); dazu eine PR-Vorlage mit Checkliste.

## 1.0.0 - 2026-10-12

- Release-ZIP per `git archive`: nur Laufzeitdateien (`export-ignore` für Doku, `dev/` und Git-Dateien), Version wird in `index.php` eingetragen und in der App angezeigt.
- `dev/build-release.ps1` baut das ZIP lokal, `dev/release.ps1` bereitet ein Release vor (CHANGELOG, Commit, Tag).
- GitHub Actions: CI bei Pull Requests und auf `main` (PHP-Syntax, Release-Inhalt, Smoke-Test gegen `php -S` und Apache wie bei IONOS); beim Tag `v*` wird ein GitHub-Release mit ZIP angelegt.
- CSRF-Schutz für alle verändernden Aktionen (Speichern, Upload, Löschen, Alle löschen, Abmelden).
- Session-Cookie mit `HttpOnly`, `SameSite=Lax`, `Secure` (bei HTTPS) und Pfad auf das App-Verzeichnis; `session.use_strict_mode` aktiv. Das Setup vergibt einen eindeutigen `session_name` pro Instanz.
- Sicherheits-Header: Content-Security-Policy, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`.
- Login-Bremse: Sperre nach 5 Fehlversuchen pro IP für 15 Minuten (`login_max_attempts`, `login_lockout_minutes`).
- Einrichtung im Browser (`setup.php`): Ohne `config.php` fragt die App beim ersten Aufruf Passwort, Datei-Lebensdauer, maximale Text-Einträge und Löschbestätigung ab, legt `config.php`, Datendateien, `uploads/` und fehlende `.htaccess`-Dateien an und prüft per HTTP, ob die Schutzregeln greifen. Erreichbar nur 30 Minuten nach dem ersten Aufruf.
- Passwort wird als `pin_hash` (`password_hash()`) gespeichert; `pin` im Klartext funktioniert weiter.
- Sitzungen sind an das aktuelle Passwort gebunden; nach einem Wechsel sind alle Sitzungen ungültig. Session-ID wird beim Login erneuert. Bestehende Sitzungen müssen sich nach dem Update einmal neu anmelden.
- Login bleibt gesperrt, solange kein eigener PIN gesetzt ist (leer oder Platzhalter).
- PIN-Vergleich zeitkonstant über `hash_equals()`.
- `config.example.php` übernimmt `CLIPBOARD_PIN` aus der Umgebung.
- `uploads/.htaccess` deaktiviert PHP-Ausführung auch unter `mod_php7`.
- MIT-Lizenz und `.gitattributes` (LF-Zeilenenden) ergänzt.
- Lokale Dev-Umgebung unter `dev/`: Startskript, Router als `.htaccess`-Ersatz für `php -S`, Dev-Konfiguration mit Daten in `.devdata/` und Smoke-Test.
- Lokaler Apache in WSL (`dev/apache.ps1`, `dev/apache/`), der das IONOS-Hosting nachbildet: PHP 8.3 als FastCGI über `mod_fcgid`, `.htaccess` aktiv, IONOS-PHP-Grenzen.
- `uploads/.htaccess` ohne `php_flag`: Bei PHP als FastCGI (IONOS) ist das ein ungültiger Befehl und führte zu HTTP 500 statt 403.
- `.htaccess` schaltet `mod_speling` ab, damit unbekannte Pfade keine ähnlichen Dateinamen auflisten; der lokale Apache aktiviert `mod_speling` wie IONOS.
- `.htaccess` sperrt Dot-Pfade wie `.git/` und `.env` (außer `.well-known/`); vorher war ein mitdeployter `.git`-Ordner abrufbar.

## 0.1.0 - 2026-10-09

- Standalone-Export aus einem früheren Webseitenprojekt erstellt.
- Lokale Konfiguration über `config.php` und `config.example.php` ergänzt.
- GitHub-taugliche Dokumentation für Nutzung, Deployment, Sicherheit und Agent-Weiterentwicklung ergänzt.
- Apache-Schutzregeln für moderne und ältere Apache-Versionen robuster gemacht.
