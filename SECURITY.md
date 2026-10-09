# Security

Diese App ist für private, kleine Deployments gedacht. Sie ist kein Mehrbenutzer-System und ersetzt keine gehärtete Dateiablage.

## Empfehlungen für produktiven Betrieb

- Setze in `config.php` einen langen, nicht erratbaren PIN.
- Betreibe die App nur über HTTPS.
- Committe niemals `config.php`, `data.json`, `files.json` oder Upload-Inhalte.
- Prüfe, dass `.htaccess` aktiv ist und direkte Requests auf `data.json`, `files.json`, `config.php`, Dateien unter `uploads/` und Dot-Pfade wie `.git/` blockiert. Schnelltest: `curl -s -o /dev/null -w "%{http_code}" https://deine-domain/pfad/.git/HEAD` muss 403 oder 404 liefern.
- Bei FastCGI-Hosting (z. B. IONOS, „Server API: cgi-fcgi“) wirken `php_flag`-Direktiven in `.htaccess` nicht. Der Schutz von `uploads/` beruht dort auf `Require all denied` sowie `RemoveHandler`/`AddType`.
- Lege `data_file`, `files_file` und `upload_dir` nach Möglichkeit außerhalb des öffentlichen Webroots ab.
- Begrenze Upload-Größen zusätzlich über Webserver- oder PHP-Konfiguration, wenn die Instanz öffentlich erreichbar ist.
- Nutze eine eigene Instanz pro Vertrauenskreis. Die App hat keine Rollen oder getrennte Benutzerkonten.

## Bekannte Grenzen

- Der PIN ist ein gemeinsames Geheimnis. Wer ihn kennt, sieht alle Einträge und Dateien.
- Es gibt aktuell kein Rate Limiting für Login-Versuche.
- Die JSON-Speicherung ist einfach gehalten und nicht für starke Parallelität gedacht.
- Der PHP-Entwicklungsserver wertet `.htaccess` nicht aus. Lokal emuliert `dev/router.php` die Regeln; für produktive Server ist das kein Ersatz. `dev/` selbst ist per `dev/.htaccess` gesperrt, und `dev/config.dev.php` enthält nur einen Test-PIN, der nie in einer produktiven `config.php` landen darf.

## Meldung von Problemen

Falls dieses Projekt in einem öffentlichen Repository liegt, melde Sicherheitsprobleme bitte zunächst privat an die Maintainer statt direkt als öffentliches Issue.
