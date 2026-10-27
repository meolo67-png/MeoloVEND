<p><img src="app/symbol.svg" alt="" height="48"></p>

# MeoloVEND – Automatenverwaltung für Aufsteller

MeoloVEND verwaltet Verkaufsautomaten von der Aufstellung bis zur Abrechnung: Automaten als Baukasten aus Komponenten, Touren und Befüllung, Kassierung mit Soll/Ist und Fehlgeld, Lager vom Wareneingang bis in den Automaten, Rabatte und Provisionen mit Endkontrolle, Verträge, Abschlüsse und eine frei einstellbare Erfolgsrechnung je Automat. Vorbild ist der Funktionsumfang von 4Vending.

Läuft im Browser auf PC, Tablet und Handy, mit PHP und MySQL/MariaDB, auch auf normalem Webspace.

## Ordner

| Ordner | Inhalt |
|---|---|
| `app/` | Das Programm: `api.php` (JSON-API), `install.php`, Oberfläche (`index.html`, `app.js`, `js/`, `style.css`) |
| `app/lib/meta.php` | Beschreibung aller Stammdaten-Tabellen. Daraus entstehen Datenbank, API und Eingabemasken. Ein neues Feld hier eintragen genügt. |
| `app/lib/api/` | Fachlogik nach Bereichen: Stammdaten, Automaten, Waren, Touren, Einnahmen, Rabatte, Auswertungen, System |
| `tests/` | Ablauftest gegen eine laufende Installation mit Beispieldaten |

## Installation

1. Leere Datenbank anlegen, PHP 8.1 oder neuer (mit `pdo_mysql` und `fileinfo`), HTTPS einschalten.
2. Inhalt von `app/` hochladen, z. B. nach `/automaten`. Die Datei `.htaccess` muss mit.
3. `https://deine-domain.de/automaten/install.php` aufrufen, Datenbank, Firma und Administrator eintragen. Mit „Beispieldaten“ entsteht ein kleiner Betrieb zum Ausprobieren.
4. `install.php` danach löschen.

Dokumente (Verträge, Fotos …) landen in `app/daten/dokumente/`. Der Ordner wird per `.htaccess` gesperrt; bei nginx `location ~ /(lib|daten)/ { deny all; }` setzen.

## Entwickeln und testen

```bash
cd app && php -S 127.0.0.1:8090        # lokal starten, dann install.php aufrufen (mit Beispieldaten)
php tests/api-test.php http://127.0.0.1:8090 admin <passwort>
```

## Fachliches in Kürze

- **Soll bar** = Bargeld-Zähler jetzt minus Zähler beim letzten Besuch. **Ist** = Münzen + Scheine + Röhrenentnahme. **Fehlgeld** = Ist − Soll.
- **Umsatz** eines Besuchs = Soll bar + bargeldlos (aus dem Bargeldlos-Zähler).
- **Abschluss** sperrt alles bis zum Datum. Änderungen danach nur als Korrekturbuchung mit Grund.
- **Staffelprovision** gilt je Monatsumsatz, z. B. `0:5; 400:10` = 5 %, ab 400 € Monatsumsatz 10 %.
- **Deckungsbeitrag** = Umsatz netto − Wareneinsatz + Fehlgeld − Provision − Kosten − Service ± Verträge − Abschreibung.
- **EVA-DTS:** gelesen werden ID1 (Seriennummer), ID4 (Nachkommastellen), VA1, CA2, DA2, PA1/PA2.
