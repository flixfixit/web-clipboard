# Security

Diese App ist für private, kleine Deployments gedacht. Sie ist kein Mehrbenutzer-System und ersetzt keine gehärtete Dateiablage.

## Empfehlungen für produktiven Betrieb

- Richte die App direkt nach dem Hochladen über das Setup ein und wähle ein langes, nicht erratbares Passwort. Bis das Setup abgeschlossen ist, kann jeder, der die URL kennt, es innerhalb des 30-Minuten-Fensters selbst abschließen.
- Das Setup speichert nur einen `password_hash()`. Ein Klartext-`pin` in `config.php` funktioniert weiter, ist aber nicht empfohlen.
- Sitzungen sind an das aktuelle Passwort gebunden. Nach einem Passwortwechsel sind alle bestehenden Sitzungen ungültig.
- Betreibe die App nur über HTTPS.
- Committe niemals `config.php`, `data.json`, `files.json` oder Upload-Inhalte.
- Prüfe, dass `.htaccess` aktiv ist und direkte Requests auf `data.json`, `files.json`, `config.php`, Dateien unter `uploads/` und Dot-Pfade wie `.git/` blockiert. Schnelltest: `curl -s -o /dev/null -w "%{http_code}" https://deine-domain/pfad/.git/HEAD` muss 403 oder 404 liefern.
- Bei FastCGI-Hosting (z. B. IONOS, „Server API: cgi-fcgi“) ist `php_flag` in `.htaccess` ein ungültiger Befehl und führt zu HTTP 500. `uploads/.htaccess` verzichtet deshalb darauf; der Schutz beruht auf `Require all denied` sowie `RemoveHandler`/`AddType`. Liegt auf dem Server noch eine ältere `uploads/.htaccess` mit `php_flag`, durch die aktuelle ersetzen.
- Die Root-`.htaccess` schaltet `mod_speling` ab (`CheckSpelling Off`). Sonst beantwortet Apache unbekannte Pfade mit einer Liste ähnlicher Dateinamen.
- Lege `data_file`, `files_file` und `upload_dir` nach Möglichkeit außerhalb des öffentlichen Webroots ab.
- Begrenze Upload-Größen zusätzlich über Webserver- oder PHP-Konfiguration, wenn die Instanz öffentlich erreichbar ist.
- Nutze eine eigene Instanz pro Vertrauenskreis. Die App hat keine Rollen oder getrennte Benutzerkonten.

## Eingebaute Schutzmaßnahmen

- **CSRF-Schutz:** Alle verändernden Aktionen (Text speichern, Upload, Löschen, Alle löschen, Abmelden) brauchen ein Token, das an die Sitzung gebunden ist. Fehlt es, antwortet die App mit 403.
- **Session-Cookie:** `HttpOnly`, `SameSite=Lax`, bei HTTPS `Secure`, Pfad auf das App-Verzeichnis beschränkt; `session.use_strict_mode` ist aktiv. Das Setup vergibt pro Instanz einen eigenen `session_name`.
- **Header:** Content-Security-Policy (nur eigene Quellen, kein Einbetten in fremde Seiten), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`.
- **Login-Bremse:** Nach `login_max_attempts` (Standard 5) Fehlversuchen pro IP ist der Login für `login_lockout_minutes` (Standard 15) gesperrt. Die Zähler liegen gehasht in `login-attempts.json` neben `data.json`.

## Automatisches Deployment

- Zugangsdaten, Zielpfad, URL und Host-Key liegen als Secrets im GitHub-Environment `production`. Logs öffentlicher Repositorys sind öffentlich und zeigen Variablen im Klartext, Secrets nur als `***`. Das Environment nur für `main` freigeben; Pull Requests aus Forks erhalten keine Secrets.
- Für das Deployment einen eigenen SFTP-Benutzer verwenden, der möglichst nur auf den App-Ordner zugreifen kann. Bei Verdacht auf Missbrauch diesen Benutzer im Hosting-Panel sperren und das Passwort im Environment erneuern.
- `SFTP_KNOWN_HOSTS` pinnt den Host-Key des Servers. Ändert IONOS den Schlüssel, schlägt das Deployment fehl, bis der neue Eintrag hinterlegt ist.
- Jeder Push auf `main` geht live. Wer Schreibrechte am Repository hat, kann damit Code auf den Server bringen.

## Bekannte Grenzen

- Der PIN ist ein gemeinsames Geheimnis. Wer ihn kennt, sieht alle Einträge und Dateien.
- Die Login-Bremse zählt Fehlversuche pro Client-IP (`REMOTE_ADDR`). Hinter einem Proxy oder CDN (z. B. Cloudflare), der die echte Client-IP nicht an PHP weitergibt, teilen sich alle Besucher einen Zähler. Dann kann ein Angreifer durch Fehlversuche auch berechtigte Nutzer vorübergehend aussperren.
- Die JSON-Speicherung ist einfach gehalten und nicht für starke Parallelität gedacht.
- Der PHP-Entwicklungsserver wertet `.htaccess` nicht aus. Lokal emuliert `dev/router.php` die Regeln; für produktive Server ist das kein Ersatz. `dev/` selbst ist per `dev/.htaccess` gesperrt, und `dev/config.dev.php` enthält nur einen Test-PIN, der nie in einer produktiven `config.php` landen darf.

## Meldung von Problemen

Falls dieses Projekt in einem öffentlichen Repository liegt, melde Sicherheitsprobleme bitte zunächst privat an die Maintainer statt direkt als öffentliches Issue.
