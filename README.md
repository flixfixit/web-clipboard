# Clipboard Webpage

Eine kleine, eigenständige PHP-Clipboard-Webapp für private Textschnipsel und temporäre Dateiübertragungen. Die App besteht aus einem PHP-Skript, speichert Daten als JSON-Dateien und braucht keinen Build-Schritt und keine Datenbank.

## Features

- Passwort-/PIN-geschützter Zugriff per PHP-Session (Passwort wird als Hash gespeichert)
- Einrichtung im Browser: Ordner auf den Webspace kopieren, App aufrufen, Passwort festlegen
- Text-Clipboard mit Kopieren, Löschen und automatischem Refresh
- Datei-Uploads mit Drag & Drop, Fortschrittsanzeige und Chunked Uploads
- Automatisches Löschen hochgeladener Dateien nach konfigurierbarer TTL
- Direkter Zugriff auf JSON-Speicher, Upload-Blobs und Dot-Pfade (`.git`, `.env` …) per Apache-Regeln blockiert

## Voraussetzungen

- PHP 8.0 oder neuer empfohlen
- Schreibrechte für das App-Verzeichnis (für das Setup) bzw. für `data.json`, `files.json` und `uploads/`
- Für produktiven Betrieb: HTTPS und ein Webserver, der die Schutzregeln aus `.htaccess` beachtet, zum Beispiel Apache

## Schnellstart

```bash
cp config.example.php config.php
php -S 127.0.0.1:8080
```

Danach `http://127.0.0.1:8080` öffnen und den PIN aus `config.php` verwenden.

Wichtig: Der PHP-Entwicklungsserver wertet `.htaccess` nicht aus. Nutze ihn nur lokal. Für produktive Deployments muss der direkte Zugriff auf `config.php`, `data.json`, `files.json` und `uploads/` blockiert sein.

## Einrichtung im Browser

1. Den Inhalt des Repositorys in einen Ordner des Webservers kopieren (`config.php` gehört nicht dazu).
2. Die App im Browser aufrufen. Ohne `config.php` erscheint statt des Logins das Setup.
3. Passwort (mindestens 8 Zeichen), Lebensdauer der Dateien, maximale Anzahl Text-Einträge und Löschbestätigung festlegen.

Das Setup legt `config.php` (nur mit dem Hash des Passworts), `data.json`, `files.json`, `uploads/` und fehlende `.htaccess`-Dateien an. Bestehende `.htaccess`-Dateien überschreibt es nicht, weist aber auf Abweichungen hin. Anschließend prüft es per HTTP, ob `data.json`, `config.php` und `uploads/` wirklich gesperrt sind, und warnt, wenn der Webserver die `.htaccess`-Regeln ignoriert.

Schutz des Setups:

- Es ist nur erreichbar, solange keine `config.php` existiert und `CLIPBOARD_PIN` nicht gesetzt ist.
- Es ist nur 30 Minuten nach dem ersten Aufruf offen. Danach `setup-started.json` im App-Verzeichnis löschen, um ein neues Zeitfenster zu öffnen.
- Innerhalb des Zeitfensters gilt: Wer das Setup zuerst abschickt, legt das Passwort fest. Daher die App direkt nach dem Hochladen einrichten.
- `setup.php` ist nicht direkt aufrufbar, nur über `index.php`.

Passwort ändern: `config.php` löschen und die App erneut aufrufen. Ein neues Passwort meldet alle bestehenden Sitzungen ab. Die Daten bleiben erhalten.

## Konfiguration

Die App liest zuerst die Standardwerte in `index.php`, dann optional `config.php`. `config.php` ist absichtlich in `.gitignore`, damit keine Geheimnisse oder lokalen Pfade ins Repository gelangen.

Solange kein eigener PIN gesetzt ist (leer oder noch der Platzhalter aus `config.example.php`), bleibt der Login gesperrt und die Login-Seite zeigt einen Hinweis.

| Option | Bedeutung |
| --- | --- |
| `pin_hash` | Vom Setup geschriebener `password_hash()` des Passworts. Hat Vorrang vor `pin`. Manuell erzeugen: `php -r "echo password_hash('dein-passwort', PASSWORD_DEFAULT);"` |
| `pin` | Login-PIN im Klartext (Alternative zu `pin_hash`). Ohne `config.php` wird `CLIPBOARD_PIN` aus der Umgebung verwendet; die Vorlage `config.example.php` übernimmt die Variable ebenfalls. Ein fest in `config.php` eingetragener PIN hat Vorrang. |
| `data_file` | JSON-Datei für Text-Einträge. |
| `files_file` | JSON-Datei für Datei-Metadaten. |
| `upload_dir` | Ordner für hochgeladene Datei-Blobs und temporäre Chunks. |
| `max_entries` | Maximale Anzahl gespeicherter Text-Einträge. |
| `file_ttl_days` | Alter in Tagen, nach dem Uploads beim nächsten Aufruf gelöscht werden. |
| `confirm_delete` | `true` zeigt Browser-Bestätigungen vor einzelnen Löschvorgängen. |
| `session_name` | Name der PHP-Session. Nützlich, wenn mehrere Instanzen parallel laufen. |

## Deployment

1. Repository auf den Server kopieren oder per GitHub Actions/deinem Hosting-Workflow deployen.
2. App aufrufen und das Setup abschließen (siehe „Einrichtung im Browser“). Alternativ `config.example.php` zu `config.php` kopieren und `pin_hash` oder `pin` setzen.
3. Falls das Setup nicht schreiben darf, Schreibrechte setzen:

```bash
touch data.json files.json
mkdir -p uploads
chmod 664 data.json files.json
chmod 775 uploads
```

4. Prüfen, dass `.htaccess` aktiv ist (das Setup zeigt das Ergebnis seiner Prüfung an). Bei Apache muss `AllowOverride` für das Verzeichnis passend gesetzt sein.
5. HTTPS aktivieren.

Für Nginx muss der Schutz manuell in die Server-Konfiguration übertragen werden, weil `.htaccess` dort nicht gilt.

## Entwicklung

Es gibt keinen Build-Prozess. Für die lokale Entwicklung liegt alles unter `dev/`:

| Datei | Zweck |
| --- | --- |
| `dev/serve.ps1` | Startet `php -S 127.0.0.1:8080` mit Router und höheren Upload-Grenzen (5-MB-Chunks brauchen mehr als die PHP-Vorgabe von 2 MB). Legt `.devdata/` und – falls nicht vorhanden – eine `config.php` an, die `dev/config.dev.php` lädt. |
| `dev/router.php` | Ersetzt lokal die `.htaccess`-Regeln: `config.php`, `*.json`, `uploads/`, `dev/` und Dotfiles liefern 403. |
| `dev/config.dev.php` | Dev-Konfiguration: Test-PIN `dev-pin-4711` (oder `CLIPBOARD_PIN`), Daten in `.devdata/`. |
| `dev/smoketest.php` | Automatischer Smoke-Test gegen den laufenden Server (Schutzregeln, Login, Text, Chunked Upload, Download, Löschen, Logout). |
| `dev/apache.ps1`, `dev/apache/` | Lokaler Apache in WSL, der das IONOS-Webhosting nachbildet (siehe unten). |

PHP unter Windows installieren und Server starten:

```powershell
winget install PHP.PHP.8.3
pwsh -File dev/serve.ps1
```

In einem zweiten Terminal prüfen:

```bash
php -l index.php
php dev/smoketest.php
```

Testdaten zurücksetzen: Server stoppen und `.devdata/` löschen. Der Smoke-Test entfernt nur die Einträge, die er selbst anlegt.

### Apache wie bei IONOS (WSL)

`php -S` ignoriert `.htaccess`. Um die echten Schutzregeln zu prüfen, gibt es einen lokalen Apache in WSL (Ubuntu 24.04), der das IONOS-Webhosting nachbildet: Apache 2.4, PHP 8.3 als FastCGI (`php-cgi` über `mod_fcgid`, „Server API: cgi-fcgi“), `AllowOverride All` und die IONOS-PHP-Grenzen aus `dev/apache/php.d/99-ionos.ini`. Er nutzt dieselbe `config.php` und `.devdata/` wie der Dev-Server und läuft auf Port 8081.

```powershell
pwsh -File dev/apache.ps1 setup    # einmalig: Pakete installieren, vHost einrichten
pwsh -File dev/apache.ps1 start    # weitere Aktionen: stop, restart, status, log
php dev/smoketest.php http://localhost:8081
```

Hinweise:

- Das Projekt liegt für Apache unter `/mnt/c/...`. Dieses Dateisystem unterscheidet keine Groß-/Kleinschreibung, IONOS schon. Abweichungen bei Schreibvarianten wie `/CONFIG.PHP` sind daher lokal möglich.
- `mod_php` bleibt bewusst deaktiviert: Wie bei IONOS führt `php_flag` in `.htaccess` zu HTTP 500.
- `mod_speling` ist wie bei IONOS aktiv; die Root-`.htaccess` schaltet es ab, der Smoke-Test prüft das.
- Welche `.htaccess`-Direktiven IONOS erlaubt, wurde am 09.10.2026 getestet: `Options`, `RemoveHandler`, `AddType`, `<If>`, `RedirectMatch`, `CheckSpelling` und `Require` ja, `php_flag` nein.

Teste zusätzlich manuell im Browser:

- Login mit richtigem und falschem PIN
- Text speichern, kopieren, löschen, alle löschen
- Datei hochladen, herunterladen, löschen
- großer Upload, damit der Chunked-Upload-Pfad ausgeführt wird
- JSON-Refresh über `?json=1` im angemeldeten Zustand

## Repository-Hinweise

Nicht committen:

- `config.php`
- `data.json`
- `files.json`
- Inhalte in `uploads/`

## Lizenz

MIT, siehe [LICENSE](LICENSE).
