# Changelog

## Unreleased

- Login bleibt gesperrt, solange kein eigener PIN gesetzt ist (leer oder Platzhalter).
- PIN-Vergleich zeitkonstant über `hash_equals()`.
- `config.example.php` übernimmt `CLIPBOARD_PIN` aus der Umgebung.
- `uploads/.htaccess` deaktiviert PHP-Ausführung auch unter `mod_php7`.
- MIT-Lizenz und `.gitattributes` (LF-Zeilenenden) ergänzt.
- Lokale Dev-Umgebung unter `dev/`: Startskript, Router als `.htaccess`-Ersatz für `php -S`, Dev-Konfiguration mit Daten in `.devdata/` und Smoke-Test.

## 0.1.0 - 2026-10-09

- Standalone-Export aus dem bisherigen `deshalb.net`-Webseitenprojekt erstellt.
- Lokale Konfiguration über `config.php` und `config.example.php` ergänzt.
- GitHub-taugliche Dokumentation für Nutzung, Deployment, Sicherheit und Agent-Weiterentwicklung ergänzt.
- Apache-Schutzregeln für moderne und ältere Apache-Versionen robuster gemacht.
