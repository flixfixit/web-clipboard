# Changelog

## Unreleased

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
