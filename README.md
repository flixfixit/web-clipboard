# Clipboard Webpage

Eine kleine, eigenständige PHP-Clipboard-Webapp für private Textschnipsel und temporäre Dateiübertragungen. Die App besteht aus einem PHP-Skript, speichert Daten als JSON-Dateien und braucht keinen Build-Schritt und keine Datenbank.

## Features

- PIN-geschützter Zugriff per PHP-Session
- Text-Clipboard mit Kopieren, Löschen und automatischem Refresh
- Datei-Uploads mit Drag & Drop, Fortschrittsanzeige und Chunked Uploads
- Automatisches Löschen hochgeladener Dateien nach konfigurierbarer TTL
- Direkter Zugriff auf JSON-Speicher, Upload-Blobs und Dot-Pfade (`.git`, `.env` …) per Apache-Regeln blockiert

## Voraussetzungen

- PHP 8.0 oder neuer empfohlen
- Schreibrechte für `data.json`, `files.json` und `uploads/`
- Für produktiven Betrieb: HTTPS und ein Webserver, der die Schutzregeln aus `.htaccess` beachtet, zum Beispiel Apache

## Schnellstart

```bash
cp config.example.php config.php
php -S 127.0.0.1:8080
```

Danach `http://127.0.0.1:8080` öffnen und den PIN aus `config.php` verwenden.

Wichtig: Der PHP-Entwicklungsserver wertet `.htaccess` nicht aus. Nutze ihn nur lokal. Für produktive Deployments muss der direkte Zugriff auf `config.php`, `data.json`, `files.json` und `uploads/` blockiert sein.

## Konfiguration

Die App liest zuerst die Standardwerte in `index.php`, dann optional `config.php`. `config.php` ist absichtlich in `.gitignore`, damit keine Geheimnisse oder lokalen Pfade ins Repository gelangen.

Solange kein eigener PIN gesetzt ist (leer oder noch der Platzhalter aus `config.example.php`), bleibt der Login gesperrt und die Login-Seite zeigt einen Hinweis.

| Option | Bedeutung |
| --- | --- |
| `pin` | Login-PIN. Ohne `config.php` wird `CLIPBOARD_PIN` aus der Umgebung verwendet; die Vorlage `config.example.php` übernimmt die Variable ebenfalls. Ein fest in `config.php` eingetragener PIN hat Vorrang. |
| `data_file` | JSON-Datei für Text-Einträge. |
| `files_file` | JSON-Datei für Datei-Metadaten. |
| `upload_dir` | Ordner für hochgeladene Datei-Blobs und temporäre Chunks. |
| `max_entries` | Maximale Anzahl gespeicherter Text-Einträge. |
| `file_ttl_days` | Alter in Tagen, nach dem Uploads beim nächsten Aufruf gelöscht werden. |
| `confirm_delete` | `true` zeigt Browser-Bestätigungen vor einzelnen Löschvorgängen. |
| `session_name` | Name der PHP-Session. Nützlich, wenn mehrere Instanzen parallel laufen. |

## Deployment

1. Repository auf den Server kopieren oder per GitHub Actions/deinem Hosting-Workflow deployen.
2. `config.example.php` zu `config.php` kopieren und einen starken PIN setzen.
3. Schreibrechte prüfen:

```bash
touch data.json files.json
mkdir -p uploads
chmod 664 data.json files.json
chmod 775 uploads
```

4. Prüfen, dass `.htaccess` aktiv ist. Bei Apache muss `AllowOverride` für das Verzeichnis passend gesetzt sein.
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
- `mod_php` bleibt bewusst deaktiviert: Bei IONOS greifen `php_flag`-Direktiven in `.htaccess` nicht.

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
