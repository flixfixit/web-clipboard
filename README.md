# Clipboard Webpage

Eine kleine, eigenständige PHP-Clipboard-Webapp für private Textschnipsel und temporäre Dateiübertragungen. Die App besteht aus einem PHP-Skript, speichert Daten als JSON-Dateien und braucht keinen Build-Schritt und keine Datenbank.

## Features

- PIN-geschützter Zugriff per PHP-Session
- Text-Clipboard mit Kopieren, Löschen und automatischem Refresh
- Datei-Uploads mit Drag & Drop, Fortschrittsanzeige und Chunked Uploads
- Automatisches Löschen hochgeladener Dateien nach konfigurierbarer TTL
- Direkter Zugriff auf JSON-Speicher und Upload-Blobs per Apache-Regeln blockiert

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

Es gibt keinen Build-Prozess. Sinnvolle Checks vor Änderungen:

```bash
php -l index.php
php -S 127.0.0.1:8080
```

Teste danach manuell:

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
