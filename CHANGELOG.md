# Changelog

## Unreleased

- Login bleibt gesperrt, solange kein eigener PIN gesetzt ist (leer oder Platzhalter).
- PIN-Vergleich zeitkonstant über `hash_equals()`.
- `config.example.php` übernimmt `CLIPBOARD_PIN` aus der Umgebung.
- `uploads/.htaccess` deaktiviert PHP-Ausführung auch unter `mod_php7`.
- MIT-Lizenz und `.gitattributes` (LF-Zeilenenden) ergänzt.
- Lokale Dev-Umgebung unter `dev/`: Startskript, Router als `.htaccess`-Ersatz für `php -S`, Dev-Konfiguration mit Daten in `.devdata/` und Smoke-Test.
- Lokaler Apache in WSL (`dev/apache.ps1`, `dev/apache/`), der das IONOS-Hosting nachbildet: PHP 8.3 als FastCGI über `mod_fcgid`, `.htaccess` aktiv, IONOS-PHP-Grenzen.
- `.htaccess` sperrt Dot-Pfade wie `.git/` und `.env` (außer `.well-known/`); vorher war ein mitdeployter `.git`-Ordner abrufbar.

## 0.1.0 - 2026-10-09

- Standalone-Export aus dem bisherigen `deshalb.net`-Webseitenprojekt erstellt.
- Lokale Konfiguration über `config.php` und `config.example.php` ergänzt.
- GitHub-taugliche Dokumentation für Nutzung, Deployment, Sicherheit und Agent-Weiterentwicklung ergänzt.
- Apache-Schutzregeln für moderne und ältere Apache-Versionen robuster gemacht.
