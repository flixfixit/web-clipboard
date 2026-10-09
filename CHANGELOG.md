# Changelog

## Unreleased

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

- Standalone-Export aus dem bisherigen `deshalb.net`-Webseitenprojekt erstellt.
- Lokale Konfiguration über `config.php` und `config.example.php` ergänzt.
- GitHub-taugliche Dokumentation für Nutzung, Deployment, Sicherheit und Agent-Weiterentwicklung ergänzt.
- Apache-Schutzregeln für moderne und ältere Apache-Versionen robuster gemacht.
