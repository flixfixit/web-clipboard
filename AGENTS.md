# Agent Guide

Diese Datei ist für Coding Agents gedacht, die das Projekt ohne Vorwissen weiterentwickeln.

## Projektüberblick

`Clipboard Webpage` ist eine bewusst kleine Single-File-PHP-App. Sie dient als privates, PIN-geschütztes Clipboard für Text und temporäre Dateiübertragungen.

Die Laufzeitdaten liegen standardmäßig im Projektverzeichnis:

- `data.json`: Text-Einträge
- `files.json`: Metadaten zu Uploads
- `uploads/`: zufällig benannte Datei-Blobs und temporäre Chunk-Ordner

Diese Dateien und Ordnerinhalte sind lokale Laufzeitdaten und dürfen nicht ins Git-Repository.

## Dateistruktur

```text
.
├── index.php              # App, Routing, Auth, Storage, HTML, CSS, JS
├── config.example.php     # Vorlage für lokale Konfiguration
├── config.php             # lokale Konfiguration, nicht committen
├── .htaccess              # Schutz für JSON-Speicher und config.php
├── uploads/
│   └── .htaccess          # blockiert direkten Zugriff auf Upload-Blobs
├── README.md              # Nutzer- und Deployment-Doku
├── SECURITY.md            # Sicherheitsnotizen
├── CHANGELOG.md           # Projektänderungen
└── LICENSE                # MIT
```

## Architektur

- Es gibt keinen Router und keine Framework-Abhängigkeiten.
- `index.php` verarbeitet Login, Logout, Textaktionen, Dateiaktionen, JSON-Polling und HTML-Ausgabe.
- Authentifizierung erfolgt über einen PIN und eine PHP-Session.
- Uploads werden im Browser in 5-MB-Chunks gesendet und serverseitig zusammengesetzt.
- Downloads laufen immer über `index.php?download=...`, damit der PIN-Schutz greift.
- Direkter Zugriff auf Upload-Dateien soll durch `uploads/.htaccess` verhindert werden.

## Wichtige Invarianten

- Keine neuen Aktionen dürfen vor der Authentifizierungsprüfung erreichbar sein.
- Upload-Blobs dürfen nie direkt öffentlich verlinkt oder anhand ihres Dateinamens ausgeliefert werden.
- `config.php`, JSON-Daten und Upload-Inhalte bleiben aus Git ausgeschlossen.
- UI-Texte sind aktuell Deutsch; neue sichtbare Texte bitte ebenfalls Deutsch halten.
- Keine Datenbank oder Build-Toolchain hinzufügen, wenn die Aufgabe nicht ausdrücklich danach verlangt.
- Bei Sicherheitsänderungen immer README und SECURITY prüfen und aktualisieren.

## Typische Änderungsstellen

- Konfiguration: oberer Block in `index.php` und `config.example.php`
- Textspeicher: `loadEntries()`, `saveEntries()` und Textaktionen
- Datei-Metadaten: `loadFiles()`, `saveFiles()` und Dateiaktionen
- Upload-Verhalten: PHP-Block `chunked` und JavaScript-Konstante `CHUNK_SIZE`
- Darstellung: `<style>` und gerendertes HTML in `index.php`
- Client-Interaktion: JavaScript am Ende von `index.php`

## Verifikation

Vor Abschluss einer Änderung mindestens ausführen:

```bash
php -l index.php
```

Für UI- oder Upload-Änderungen zusätzlich lokal starten:

```bash
php -S 127.0.0.1:8080
```

Manuell prüfen:

- Login und Logout
- Text speichern, kopieren und löschen
- Datei hochladen, herunterladen und löschen
- `Alle löschen`
- Auto-Refresh im angemeldeten Zustand
- Verhalten bei leerem Clipboard

## Sicherheits-Checkliste für Agents

- Wird `htmlspecialchars()` oder ein anderer sicherer Escaping-Pfad für alle nutzergenerierten Werte genutzt?
- Bleibt `basename()` oder ein gleichwertiger Schutz für sichtbare Upload-Namen erhalten?
- Werden gespeicherte Dateinamen weiterhin zufällig erzeugt?
- Werden Downloads nur nach Authentifizierung und nur per Datei-ID ausgeliefert?
- Wird bei neuen Dateien `.gitignore` angepasst, falls Laufzeitdaten entstehen?
- Werden `.htaccess`-Regeln nicht entfernt, ohne Ersatz in der Doku zu beschreiben?

## Stil

Dieses Projekt bevorzugt einfache, direkt lesbare Änderungen. Wenn eine neue Funktion mit wenigen klaren Funktionen in `index.php` verständlich bleibt, ist das besser als eine frühe Aufteilung in viele Dateien. Größere Features dürfen natürlich modulare Struktur bekommen, sollten dann aber in README und dieser Datei erklärt werden.
