# PizzaVote – Setup

Zero-Config PHP + SQLite Webanwendung für gemeinsame Pizzabestellungen im lokalen Netzwerk
(z. B. LAN-Party). Kein Login für Teilnehmer, kein separater Server (Datenbank, Account-System
etc.) nötig — nur PHP.

## Wie funktioniert die Nutzer-Zuordnung?

**Teilnehmer identifizieren sich nicht per Login, sondern automatisch über ihre lokale
IP-Adresse** (`getClientIP()` in `index.php`, ausgewertet aus `HTTP_X_FORWARDED_FOR` /
`HTTP_CLIENT_IP` / `REMOTE_ADDR`):

- Beim ersten Besuch wird einmalig nach einem Namen gefragt.
- Dieser Name wird zusammen mit der IP-Adresse in der `users`-Tabelle gespeichert
  (`ip` ist `UNIQUE`) — **kein** Cookie, **kein** `localStorage`, **kein** Passwort.
- Bei jedem weiteren Besuch von derselben IP wird der Nutzer automatisch wiedererkannt.
- Der Name lässt sich jederzeit ändern (✎-Symbol oben), die Zuordnung zur IP bleibt dabei
  bestehen.

**Wichtig zu wissen:**

- Das funktioniert zuverlässig, solange jedes Gerät im LAN eine **eigene** IP per DHCP
  bekommt (Standard-Verhalten in fast jedem Heimrouter/Hotspot).
- Geräte **hinter derselben IP** (z. B. gemeinsames NAT/Proxy) werden als **eine** Person
  behandelt — die letzte gespeicherte Auswahl gewinnt.
- Beim lokalen Testen von einem einzigen Rechner über `localhost`/`127.0.0.1` sehen **alle**
  Anfragen wie ein einziger Nutzer aus. Um das Mehrnutzer-Verhalten zu testen, muss über die
  echte LAN-IP des Host-Rechners von verschiedenen Geräten zugegriffen werden (siehe
  "Schnellstart" unten).
- Ändert ein Gerät seine IP (z. B. neue DHCP-Lease, anderes WLAN), erscheint es als neuer
  Nutzer und wird erneut nach einem Namen gefragt.

## Projektstruktur

```
www/
├── config.php             ← Konfiguration: Admin-Passwort, Sprache, Zeitzone, DB-Setup
├── i18n.php               ← Übersetzungs-Helper (Funktion t())
├── lang/                  ← Sprachdateien für die Oberfläche
│   ├── de.json
│   └── en.json
├── index.php              ← Frontend für alle Teilnehmer (IP-basiert, kein Login)
├── backend/
│   └── index.php          ← Admin-Panel (passwortgeschützt): Bestellungen + Produkte
├── phpliteadmin/          ← Drittanbieter-Tool zur direkten DB-Ansicht (siehe Sicherheit!)
├── data/                  ← wird automatisch angelegt (SQLite-DB: bestellung.db)
└── img/                   ← Produktbilder (lokale Dateien oder externe URLs in der DB)
    ├── margherita.jpg
    ├── salami.jpg
    └── ...
```

## Installation (eigener Webserver)

1. **PHP-Modul prüfen:**
   ```bash
   # Fedora / Nobara:
   sudo dnf install php php-pdo php-sqlite3

   # Debian / Ubuntu:
   sudo apt install php php-sqlite3
   ```

2. **Dateien auf Server kopieren** (z.B. `/var/www/html/bestellung/`)

3. **Schreibrecht für `data/`- und `img/`-Ordner:**
   ```bash
   mkdir data
   chmod 755 data img
   # oder falls nötig:
   chown www-data:www-data data img
   ```

4. **`config.php` anpassen:**
   - `ADMIN_PASSWORD` ändern!
   - Zeitzone ggf. anpassen (Standard: Europe/Vienna)
   - `APP_LANG` setzen: `'de'` oder `'en'` (passende Datei muss in `lang/` existieren)

5. **Produktbilder** in `img/` ablegen — oder URLs in den Produkten verwenden.

6. **Fertig!** Beim ersten Aufruf wird die Datenbank automatisch angelegt
   und mit 9 Beispielprodukten befüllt.

## Schnellstart (lokal testen, ohne eigenen Webserver)

PHPs eingebauter Server reicht für eine LAN-Party völlig aus:

```bash
cd www
# Falls die Erweiterung pdo_sqlite in der globalen php.ini deaktiviert ist,
# nur für diesen einen Prozess aktivieren (keine Systemdatei anfassen):
php -d extension=pdo_sqlite -S 0.0.0.0:8000
```

- `0.0.0.0` statt `localhost` binden, damit die App auch von anderen Geräten im selben
  Netzwerk erreichbar ist (siehe oben zur IP-Zuordnung — das ist für den Mehrnutzer-Betrieb
  notwendig).
- Lokale IP herausfinden: `ipconfig | findstr IPv4` (Windows) bzw. `ip addr | grep inet`
  (Linux/Mac).
- Dann `http://<lokale-IP>:8000` an alle Teilnehmer im selben Netzwerk weitergeben.

## Nutzung

- **Frontend:** `http://<server-ip>:<port>/`
- **Backend:** `http://<server-ip>:<port>/backend/` — Login mit `ADMIN_PASSWORD` aus
  `config.php`

### Admin-Funktionen (Backend)

- **Bestellungen**: neue starten (mit optionaler Deadline), bearbeiten, abschließen,
  reaktivieren, löschen; Zusammenfassung mit Gesamtsumme je Person; Druckansicht
- **Produkte**: anlegen, bearbeiten, aktivieren/deaktivieren, löschen
- **Produktbild**: Pfad/URL manuell eintragen ODER Bilddatei hochladen (JPG/PNG/GIF/WEBP) —
  wird automatisch auf 800×600 zugeschnitten/skaliert. Bleibt unverändert, wenn beim
  Bearbeiten nichts Neues hochgeladen wird.

## Mehrsprachigkeit

Die Oberfläche (Labels, Buttons, Meldungen) unterstützt Deutsch und Englisch:

- Sprache wird **statisch** über `define('APP_LANG', 'de');` in `config.php` gewählt —
  kein Sprach-Umschalter für Endnutzer in der Oberfläche.
- Neue Sprache hinzufügen: `lang/<code>.json` mit denselben Schlüsseln wie `lang/de.json`
  anlegen.
- **Nicht** übersetzt werden Pizzanamen, Beschreibungen und Kommentare — die bleiben so,
  wie sie in der Datenbank/im Admin-Panel eingetragen wurden.

## Sicherheit

- **`ADMIN_PASSWORD` unbedingt ändern** — der Standardwert ist nur ein Platzhalter.
- **Nicht öffentlich ins Internet routen.** Diese App ist als reine LAN-Lösung gedacht
  (kein CSRF-Schutz, einfacher Session-Login). Bereits beobachtet: Ein automatisierter
  SQL-Injection-Versuch über das Namensfeld von einer öffentlichen IP — durch durchgängige
  Verwendung von PDO Prepared Statements wirkungslos, zeigt aber, dass ein offener Port von
  Bots gefunden und abgetastet wird.
- Die `data/`-Mappe sollte per `.htaccess` oder Webserver-Config
  nicht direkt erreichbar sein:
  ```apache
  # .htaccess in data/
  Deny from all
  ```
- **`phpliteadmin/`** erlaubt direkten Datenbankzugriff übers Web und steht aktuell noch
  auf dem **Standard-Passwort `admin`** (`phpliteadmin.config.php`, Zeile `$password`) —
  das ist ein öffentlich bekanntes Default-Credential. **Vor jedem Einsatz unbedingt
  ändern** oder den Ordner ganz entfernen, falls nicht benötigt.
