# Link-Sammlung

Flat-File Link-Dashboard (PHP + Vanilla JS + JSON), öffentliches Frontend zeigt Links gruppiert nach Kategorie, geschütztes Backend zur Verwaltung.

## Stack

- **Backend:** PHP 8.x (kein Framework, keine Composer-Dependencies)
- **Frontend:** Vanilla JS (fetch/AJAX), kein Build-Step
- **Speicher:** JSON-Dateien (`data/links.json`, `data/config.json`), Schreibzugriff mit `flock()` abgesichert
- **Auth:** Session-basiert, Passwort als bcrypt-Hash

## Verzeichnisstruktur

```
linksammlung/
├── index.php               # Öffentliches Frontend
├── login.php               # Login-Formular
├── logout.php              # Session beenden
├── backend/
│   ├── index.php           # Admin-Dashboard (geschützt)
│   ├── api.php             # AJAX-API für CRUD-Operationen
│   └── export.php          # JSON-Export-Download (geschützt)
├── includes/
│   └── functions.php       # JSON-Helper, Auth, Favicon-Cache, Audit-Log, Upload-Logik
├── data/
│   ├── config.json         # Seitentitel, Logo, Hintergrund, Passwort-Hash
│   ├── links.json          # Kategorien + Links
│   ├── favicon_cache.json  # Mapping Host → lokaler Favicon-Pfad (autom. angelegt)
│   ├── audit_log.json      # Änderungsprotokoll (autom. angelegt)
│   ├── links.backup-*.json # Automatische Sicherheitskopien vor jedem Import
│   └── .htaccess           # blockiert direkten HTTP-Zugriff
├── uploads/
│   ├── favicons/           # Lokal gecachte Favicon-Bilder (Links)
│   │   └── local/          # Site-Icon (Tab-Favicon, Apple-Touch-Icon, PWA-Manifest) — manuell befüllt
│   └── .htaccess           # verhindert Skriptausführung
└── assets/
    ├── css/style.css
    └── js/admin.js
```

## Installation

1. Gesamten Ordner `linksammlung/` auf den Webspace (Plesk) hochladen.
2. Schreibrechte sicherstellen für:
   - `data/` (gesamter Ordner — für `config.json`, `links.json`, `favicon_cache.json`, `audit_log.json`, Backup-Dateien)
   - `uploads/` (gesamter Ordner inkl. `uploads/favicons/`)
3. **Standard-Login:** Passwort ist initial `admin123`.
   → **Sofort nach dem ersten Login im Backend ein neues Passwort setzen** (Funktion `change_password` ist in der API vorhanden, im UI aktuell noch nicht verdrahtet — siehe „Nächste Schritte" unten, oder Passwort-Hash direkt per PHP erzeugen:
   ```php
   php -r "echo password_hash('DeinNeuesPasswort', PASSWORD_BCRYPT);"
   ```
   Ergebnis in `data/config.json` unter `password_hash` eintragen.)

## Nutzung

### Frontend (`index.php`)
Zeigt alle Kategorien als Karten, sortiert nach dem `order`-Feld. Pro Kategorie werden die zugehörigen Links als Liste angezeigt (Icon oder Buchstaben-Fallback + Titel). Klick auf das Zahnrad-Icon oben rechts führt zum Login.

- **Live-Suche:** Suchfeld filtert alle Links client-seitig in Echtzeit (ohne Reload), Kategorien ohne Treffer werden ausgeblendet. Tastenkürzel `/` fokussiert die Suche, `Esc` leert sie.
- **Uhrzeit/Datum:** wird oben rechts im Header live angezeigt (aktualisiert sekündlich, Format `de-CH`).
- **Über/Copyright:** "?"-Symbol oben rechts öffnet ein Popup (natives `<dialog>`-Element) mit Copyright-Hinweis und Kontaktadresse. Text ist aktuell fest in `index.php` hinterlegt (nicht über das Backend änderbar).

### Backend (`backend/index.php`)
Nach Login erreichbar. Fünf Bereiche:
- **Einstellungen:** Seitentitel, Logo-Upload, Hintergrundbild-Upload
- **Kategorien:** anlegen, umbenennen, löschen (löscht zugehörige Links mit), **per Ziehen (Drag & Drop) sortierbar**
- **Links:** anlegen, bearbeiten, löschen — jeweils mit Titel, URL, optionaler Beschreibung und Pflicht-Kategorie. Favicons werden automatisch anhand der Link-URL geladen. Angezeigt als **eingeklappte Baumstruktur pro Kategorie** (`<details>`/`<summary>`), innerhalb einer Kategorie per Drag & Drop sortierbar.
- **Wartung:** Favicon-Cache aktualisieren/leeren, JSON-Export-Download, JSON-Import (mit automatischer Sicherheitskopie vorher)
- **Änderungsprotokoll:** zeigt die letzten Backend-Aktionen (wer/was/wann — ohne Benutzer-Unterscheidung, da Single-Login)

Alle Aktionen laufen über `backend/api.php` per `fetch()` — kein Full-Page-Reload nötig (ausser nach Einstellungen-Speichern und Import).

## Datenmodell

**`data/links.json`**
```json
{
  "categories": [{ "id": "cat-...", "name": "Entwicklung", "order": 1 }],
  "links": [
    {
      "id": "link-...",
      "title": "GitHub",
      "url": "https://github.com",
      "category_id": "cat-...",
      "description": "Code-Repositories und Versionsverwaltung",
      "type": "web",
      "rdp_username": "",
      "order": 1
    },
    {
      "id": "link-...",
      "title": "Server01",
      "url": "192.168.1.10:3389",
      "category_id": "cat-...",
      "description": "",
      "type": "rdp",
      "rdp_username": "DOMÄNE\\admin",
      "order": 2
    }
  ]
}
```

**`data/config.json`**
```json
{
  "site_title": "Link-Sammlung",
  "logo": "uploads/logo-....png",
  "background": "uploads/background-....jpg",
  "password_hash": "$2y$10$..."
}
```

## Favicons

Favicons werden primär **lokal gecacht** (`uploads/favicons/`). Solange nichts gecacht ist, greift ein **Live-Fallback**, der je nach Host-Typ unterschiedlich funktioniert:

- **Öffentliche Hosts** (z.B. `github.com`): Live-Fallback über `https://www.google.com/s2/favicons` — Google liefert ein passendes Icon auch für Seiten mit ungewöhnlichem Favicon-Pfad.
- **Interne/private Hosts** (z.B. `172.16.x.x`, `192.168.x.x`, IP-lose Hostnamen wie `srvvcs01`, oder `.local`/`.lan`/`.internal`/`.intra`/`.corp`/`.home`-Domains): Google kann diese Seiten nicht erreichen. Stattdessen wird **direkt das `favicon.ico` der Zielseite selbst** verwendet (z.B. `http://172.16.1.231:5480/favicon.ico`) — das lädt der **Browser des Anwenders**, der ja im gleichen Netzwerk wie diese Geräte ist, direkt vom Zielgerät. Erkennung der Erreichbarkeit erfolgt automatisch über `is_private_host()` in `includes/functions.php` (IP-Bereichsprüfung via PHP `FILTER_FLAG_NO_PRIV_RANGE`/`FILTER_FLAG_NO_RES_RANGE`, plus Hostname-Heuristik).
- Schlägt der Request ganz fehl (weder Cache noch Live-Fallback erreichbar), greift automatisch der Buchstaben-Fallback (`handleFaviconError()` in `assets/js/app.js`).

**Backend → Wartung → "Favicons aktualisieren"** lädt für alle in `links.json` vorkommenden Hosts einmalig das Favicon herunter und speichert es lokal (`data/favicon_cache.json` als Host→Pfad-Mapping, inkl. Quelle `google` oder `direct`). Danach lädt das Frontend das Icon von der eigenen Domain statt live — schneller und weniger externe Requests pro Seitenaufruf.

- Für interne Hosts versucht dieser Button ebenfalls den direkten `favicon.ico`-Weg — das funktioniert aber nur, wenn **der Server, auf dem die Linksammlung läuft**, selbst Netzwerkzugriff auf das jeweilige interne Gerät hat (z.B. weil er im selben LAN steht). Ist das nicht der Fall, bleibt der Host ungecacht, das Frontend greift dann weiterhin pro Seitenaufruf auf den client-seitigen Direktversuch zurück (der beim Anwender im LAN i.d.R. funktioniert, auch wenn der Server selbst das Gerät nicht erreicht).
- **"Cache leeren"** löscht alle lokal gespeicherten Favicon-Dateien und das Mapping; danach greift wieder der Live-Fallback.
- **Diagnose bei Fehlschlägen:** Die Ergebnismeldung nach "Favicons aktualisieren" listet bis zu 4 fehlgeschlagene Hosts mit Fehlergrund (z.B. `HTTP 404`, `cURL: Connection timed out`, `Schreibrechte prüfen`); vollständige Liste steht im Änderungsprotokoll. Häufige Ursachen: kein Netzwerkpfad vom Server zum internen Gerät, `uploads/favicons/` nicht beschreibbar, oder (seltener) fehlende PHP-cURL-Extension — dann greift automatisch ein `file_get_contents()`-Fallback, der aber `allow_url_fopen=On` in der PHP-Konfiguration voraussetzt.
- **Viele Links / grosse Geräte-Anzahl (z.B. 50+ interne IPs):** Ein Lauf verarbeitet nur Hosts, die noch nicht erfolgreich gecacht sind — bereits gecachte werden übersprungen (`skipped_cached`), damit wiederholtes Klicken den Cache schrittweise auffüllt statt jedes Mal alles neu zu laden. Zusätzlich gilt ein Zeitbudget (Standard 20 Sekunden): wird es überschritten, bricht der Lauf sauber ab und meldet, wie viele Hosts noch offen sind (`skipped_time_budget`) — einfach nochmal auf "Favicons aktualisieren" klicken, um fortzufahren. Ohne dieses Zeitbudget könnte ein Lauf mit vielen unerreichbaren internen Hosts PHPs `max_execution_time` überschreiten und mitten drin abbrechen, ohne dass im Frontend irgendeine Meldung erscheint ("keine Fehlermeldung" bei stillem Timeout).
- **Schreibrechte-Vorabprüfung:** Bevor überhaupt Favicons heruntergeladen werden, testet die Funktion mit einer Test-Datei, ob `uploads/favicons/` beschreibbar ist. Ist das nicht der Fall, kommt sofort eine einzelne klare Fehlermeldung statt vieler verwirrender Einzelfehler pro Host.
  - **Unter Windows/IIS** (z.B. `C:\inetpub\wwwroot\...`): läuft PHP meist unter der App-Pool-Identität (z.B. `IIS APPPOOL\<Name-des-App-Pools>`) oder `IUSR`. Diese braucht **Schreibzugriff** auf den Ordner `uploads\` (inkl. Unterordner `favicons\`): Rechtsklick auf `uploads` → Eigenschaften → Sicherheit → Bearbeiten → passenden Benutzer hinzufügen (App-Pool-Identität als `IIS APPPOOL\<AppPoolName>` eintragen) → Schreiben/Ändern erlauben. Ohne diese Rechte bleibt `uploads/favicons/` dauerhaft leer, auch wenn im Frontend Icons sichtbar sind (die kommen dann live von Google bzw. direkt vom Zielgerät, werden aber nie lokal zwischengespeichert).
  - **Unter Linux/Plesk:** Schreibrechte für den PHP-Prozessbenutzer (meist der Plesk-Systembenutzer der Subscription) auf `uploads/` sicherstellen, z.B. via Plesk-Dateimanager oder `chown`/`chmod`.
  - **Wenn Berechtigungen gesetzt sind, es aber trotzdem nicht funktioniert:** Button **"Diagnose anzeigen"** (neben "Favicons aktualisieren") liefert konkrete Fakten statt Vermutungen: PHP-Version, ob cURL/OpenSSL aktiv sind, `open_basedir`/`disable_functions`, den *tatsächlichen* Pfad, den PHP für `uploads/favicons/` verwendet (`realpath()` — deckt auf, falls IIS-Virtual-Directories auf einen anderen Ordner zeigen als erwartet), einen echten Schreibtest mit der originalen PHP-Fehlermeldung, und einen Netzwerktest (Download von `google.com/favicon.ico`, um Schreibproblem von Netzwerkproblem zu unterscheiden). Ergebnis lässt sich einfach als Screenshot teilen.
- **Hinweis:** Nach dem Hinzufügen neuer Links oder Änderung einer URL muss "Favicons aktualisieren" erneut ausgeführt werden, damit das neue Icon serverseitig gecacht wird (kein automatischer Trigger, um unnötige Requests zu vermeiden) — bis dahin greift bereits der Live-Fallback ohne weiteres Zutun.

## Browser-/Site-Icon (Tab-Favicon & PWA-Manifest)

Zusätzlich zu den automatisch geladenen Link-Favicons (siehe oben) gibt es ein **eigenes Icon für die Linksammlung-Seite selbst** — das Icon, das im Browser-Tab, bei "Zum Startbildschirm hinzufügen" (iOS) und in PWA-Manifesten erscheint. Eingebunden im `<head>` von `index.php` und `backend/index.php`:

```html
<link rel="apple-touch-icon" sizes="180x180" href="/uploads/favicons/local/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/uploads/favicons/local/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/uploads/favicons/local/favicon-16x16.png">
<link rel="manifest" href="/uploads/favicons/local/site.webmanifest">
```

- **Ablageort:** `uploads/favicons/local/` — bewusst ein eigenes Unterverzeichnis, getrennt von `uploads/favicons/` (dort landen die automatisch gecachten *Link*-Favicons, siehe oben). So gibt es keine Namenskollision und der Ordner wird nicht versehentlich durch "Cache leeren" mitgelöscht (dieser Befehl räumt nur direkt in `uploads/favicons/` liegende Dateien auf, nicht Unterordner).
- **Absoluter Pfad (`/uploads/...`) bewusst gewählt:** Da `backend/index.php` in einem Unterordner liegt, würde ein relativer Pfad dort ins Leere laufen (siehe der frühere Hintergrundbild-Bug mit relativen URLs). Mit führendem `/` funktioniert der Pfad identisch auf `index.php` und `backend/index.php`, sofern die Linksammlung im Webroot der Domain liegt. Liegt sie stattdessen in einem Unterverzeichnis (z.B. `https://domain.ch/links/`), müssen die Pfade entsprechend angepasst werden (z.B. `/links/uploads/favicons/local/...`).
- **Benötigte Dateien** (müssen manuell in `uploads/favicons/local/` abgelegt werden, kein automatischer Upload-Mechanismus dafür):
  - `apple-touch-icon.png` (180×180)
  - `favicon-32x32.png` (32×32)
  - `favicon-16x16.png` (16×16)
  - `site.webmanifest` (JSON-Manifest mit Namen, Icons, Theme-Farbe — z.B. via [realfavicongenerator.net](https://realfavicongenerator.net) erzeugbar)
- Der Ordner `uploads/favicons/local/` ist bereits im Auslieferungspaket angelegt (aktuell nur mit `.gitkeep` als Platzhalter) und durch die bestehende `uploads/.htaccess` ebenfalls vor Skriptausführung geschützt.

## Drag & Drop (Sortierung)

- **Kategorien:** im Backend-Panel "Kategorien" per Ziehen am ☰-Symbol neu anordnen. Wirkt sich auf die Sortierung im Frontend (Kategorie-Reihenfolge) und im Kategorie-Schnellsprung-Menü aus.
- **Links:** innerhalb einer aufgeklappten Kategorie im Panel "Links" per Ziehen sortierbar. Reihenfolge gilt nur innerhalb der jeweiligen Kategorie (kein Verschieben zwischen Kategorien per Drag & Drop — dafür weiterhin "Bearbeiten" nutzen).
- Technisch: natives HTML5-Drag&Drop (`draggable`, `dragstart`/`dragover`/`drop`), kein externes JS-Framework. Nutzt die bereits vorhandenen API-Endpunkte `reorder_categories` / `reorder_links`.

## Import / Export

- **Export** (Backend → Wartung): lädt `categories` + `links` als JSON-Datei herunter (`linksammlung-export-JJJJMMTT-HHMMSS.json`). Enthält bewusst **keinen** Passwort-Hash und keine Logo-/Hintergrund-Pfade (serverspezifisch, würden beim Import auf einem anderen System ins Leere laufen).
- **Import** (Backend → Wartung): JSON-Datei mit `categories`/`links`-Struktur hochladen. **Überschreibt alle bestehenden Kategorien und Links vollständig** — vor dem Import wird automatisch eine Sicherheitskopie unter `data/links.backup-JJJJMMTT-HHMMSS.json` angelegt. Diese Backups werden nicht automatisch gelöscht (bei Bedarf manuell aufräumen).
- Nützlich für: Backups vor grösseren Änderungen, Migration zwischen Umgebungen (z.B. Test- → Produktivsystem), Wiederherstellung nach einem fehlerhaften Import (einfach die passende `links.backup-*.json` in `links.json` umbenennen).

## Änderungsprotokoll (Audit-Log)

- Protokolliert automatisch: Kategorie/Link erstellt, umbenannt, bearbeitet, gelöscht, Neu-Sortierungen, Einstellungsänderungen, Passwortänderung, Favicon-Cache-Aktionen, Importe.
- Gespeichert in `data/audit_log.json`, auf die letzten 300 Einträge begrenzt (älteste werden automatisch verworfen).
- Da es nur einen gemeinsamen Backend-Login gibt (kein Mehrbenutzer-System), wird **wer** die Aktion ausgeführt hat nicht unterschieden — nur **was** und **wann**.
- Über "Protokoll leeren" im Backend vollständig zurücksetzbar.

## RDP-Verbindungen

Neben normalen Web-Links unterstützt die Linksammlung auch **RDP-Verbindungen** (Remotedesktop, z.B. für Windows-Server/VMs):

- Beim Anlegen/Bearbeiten eines Links im Backend **Typ auf "RDP-Verbindung"** stellen. Das URL-Feld wird dann zur "Server-Adresse" (Hostname/IP, optional mit `:Port`, z.B. `192.168.1.10:3389` oder `srvvcs01.msith.ch`). Ein optionales Feld für den Benutzernamen (z.B. `DOMÄNE\benutzer`) kann mit angegeben werden.
- Klick auf den Link im Frontend lädt eine `.rdp`-Datei herunter (dynamisch als `data:`-URI generiert, kein Extra-Request/-Skript nötig). Windows öffnet diese Datei standardmässig mit der **Remotedesktopverbindung (mstsc.exe)**.
- **Warum kein direkter `rdp://`-Link?** Es gibt keinen von Haus aus registrierten Browser-Protokollhandler für `rdp://` unter Windows — ein Klick würde ins Leere laufen oder eine Fehlermeldung zeigen. Der `.rdp`-Datei-Download ist der zuverlässige Standardweg (funktioniert identisch zum manuellen Export einer Verbindung aus der Remotedesktopverbindung selbst).
- RDP-Links werden im Frontend mit einem violetten Icon-Badge (🖥) statt eines Favicons dargestellt, da für reine Server-Adressen kein Favicon existiert.
- Das generierte `.rdp`-Profil enthält sinnvolle Standardwerte (Vollbild-Auflösung 1920×1080, Zwischenablage-Umleitung an, Komprimierung an, Netzwerk-Autoerkennung) — bei Bedarf lassen sich weitere Parameter in `includes/functions.php::build_rdp_file_content()` ergänzen (z.B. `redirectprinters:i:1` für Drucker-Umleitung).
- Serverseitige Validierung (`is_valid_rdp_target()`) erlaubt nur Hostname/IP optional mit Port — kein Freitext, keine Sonderzeichen.

## Sicherheit

- `data/*.json` ist per `.htaccess` (`Require all denied`) vor direktem HTTP-Zugriff geschützt.
- `uploads/` blockt Skriptausführung (`.php`, `.phtml` etc.) über `.htaccess`.
- Passwort wird nie im Klartext gespeichert (`password_hash()` / `password_verify()`).
- Bild-Uploads sind auf `jpg/jpeg/png/gif/webp/svg` und max. 5 MB begrenzt.
- URLs werden serverseitig validiert (`http(s)://` Pflicht).
- Session-ID wird nach Login neu generiert (Schutz vor Session-Fixation).

**Hinweis:** Diese `.htaccess`-Regeln greifen nur unter Apache (Plesk-Standard). Bei nginx müsste die Sperre äquivalent in der Server-Config nachgezogen werden.

## Bekannte Grenzen / nächste Schritte

- Passwort-Ändern-Funktion (`change_password`) existiert in der API, aber ohne UI-Formular im Backend — bei Bedarf leicht ergänzbar.
- Kein CSRF-Token auf den API-Calls (nur Session-Schutz) — für ein rein intern genutztes Tool i.d.R. ausreichend, bei Bedarf nachrüstbar.
- Kein Rate-Limiting auf den Login — bei extern erreichbarem Server empfehlenswert nachzurüsten (z.B. Sperre nach 5 Fehlversuchen).
- Favicon-Cache muss manuell aktualisiert werden (kein Cron-Job) — für automatisches periodisches Neuladen könnte ein Cron-Aufruf von `backend/api.php?action=refresh_favicons` (mit Session-Cookie oder separatem Auth-Token) ergänzt werden.
- Import ersetzt Kategorien/Links komplett (kein selektiver Merge) — für die meisten Backup/Migrations-Szenarien ausreichend, aber kein Zusammenführen zweier Datenbestände.
- `links.backup-*.json`-Dateien werden bei jedem Import neu angelegt, aber nie automatisch gelöscht — bei häufigen Imports gelegentlich manuell aufräumen.
