<?php
// MeoloVEND – Beschreibung aller Stammdaten-Tabellen. Daraus entstehen Tabellen, API und Eingabemasken.
// Feld: [name, Bezeichnung, Typ, Zusatz]. Typen: text, textarea, int, dec, date, bool, select (Zusatz: Liste), ref (Zusatz: Tabelle), email, tel
declare(strict_types=1);

const MV_MODULE = [
    'stammdaten' => 'Stammdaten',
    'automaten' => 'Automaten und Technik',
    'waren' => 'Artikel und Lager',
    'touren' => 'Touren und Befüllung',
    'einnahmen' => 'Einnahmen und Abschlüsse',
    'rabatte' => 'Rabatte und Verträge',
    'auswertungen' => 'Auswertungen',
    'system' => 'Benutzer und Einstellungen',
];

const MV_KOMPONENTENTYPEN = [
    'grundautomat' => 'Grundautomat', 'muenzer' => 'Münzwechsler', 'banknotenleser' => 'Banknotenleser',
    'bezahlsystem' => 'Bezahlsystem (bargeldlos)', 'telemetrie' => 'Telemetrie-Modul', 'zubehoer' => 'Zubehör', 'verschleissteil' => 'Verschleißteil',
];

function mv_resources(): array
{
    static $r = null;
    if ($r !== null) return $r;
    $r = [
        'orte' => ['title' => 'Orte', 'single' => 'Ort', 'modul' => 'stammdaten', 'label' => ['plz', 'ort'], 'order' => 'plz, ort',
            'fields' => [['plz', 'PLZ', 'text', 'req'], ['ort', 'Ort', 'text', 'req'], ['land', 'Land', 'text']]],
        'mitarbeiter' => ['title' => 'Mitarbeiter', 'single' => 'Mitarbeiter', 'modul' => 'stammdaten', 'label' => ['nachname', 'vorname'], 'order' => 'nachname, vorname', 'docs' => true,
            'fields' => [
                ['personalnr', 'Personalnummer', 'text'], ['vorname', 'Vorname', 'text', 'req'], ['nachname', 'Nachname', 'text', 'req'],
                ['strasse', 'Straße', 'text'], ['ort_id', 'PLZ / Ort', 'ref', 'orte'], ['telefon', 'Telefon', 'tel'], ['email', 'E-Mail', 'email'],
                ['geburtsdatum', 'Geburtsdatum', 'date'], ['sv_nummer', 'Sozialversicherungsnummer', 'text'],
                ['einsatzbereich', 'Einsatzbereich', 'select', ['Fahrer', 'Techniker', 'Büro', 'Lager', 'Geschäftsführung']],
                ['eintritt', 'Eintritt', 'date'], ['austritt', 'Austritt', 'date'], ['haccp_schulung', 'Letzte HACCP-Schulung', 'date'],
                ['bemerkung', 'Bemerkung', 'textarea'],
            ], 'list' => ['personalnr', 'nachname', 'vorname', 'einsatzbereich', 'telefon', 'haccp_schulung']],
        'cluster' => ['title' => 'Kunden-Cluster', 'single' => 'Cluster', 'modul' => 'stammdaten', 'label' => ['name'], 'order' => 'name',
            'fields' => [['name', 'Name', 'text', 'req'], ['bemerkung', 'Bemerkung', 'textarea']]],
        'kunden' => ['title' => 'Kunden', 'single' => 'Kunde', 'modul' => 'stammdaten', 'label' => ['kundennr', 'firma'], 'order' => 'firma', 'docs' => true, 'auto' => ['kundennr', 10001],
            'fields' => [
                ['kundennr', 'Kundennummer', 'text'], ['firma', 'Firma / Name', 'text', 'req'], ['ansprechpartner', 'Ansprechpartner', 'text'],
                ['strasse', 'Straße', 'text'], ['ort_id', 'PLZ / Ort', 'ref', 'orte'], ['telefon', 'Telefon', 'tel'], ['email', 'E-Mail', 'email'],
                ['cluster_id', 'Cluster', 'ref', 'cluster'], ['ust_id', 'USt-IdNr.', 'text'], ['iban', 'IBAN (für Provisionen)', 'text'],
                ['debitor', 'Debitor / Kreditor (DATEV)', 'text'], ['bemerkung', 'Bemerkung', 'textarea'],
            ], 'list' => ['kundennr', 'firma', 'ort_id', 'cluster_id', 'ansprechpartner', 'telefon']],
        'standorte' => ['title' => 'Standorte', 'single' => 'Standort', 'modul' => 'stammdaten', 'label' => ['name'], 'order' => 'name',
            'fields' => [
                ['kunde_id', 'Kunde', 'ref', 'kunden', 'req'], ['name', 'Bezeichnung', 'text', 'req'], ['strasse', 'Straße', 'text'], ['ort_id', 'PLZ / Ort', 'ref', 'orte'],
                ['gebaeude', 'Gebäude / Etage / Raum', 'text'], ['zugang', 'Zugang (Schlüssel, Code, Zeiten)', 'textarea'], ['ansprechpartner', 'Ansprechpartner vor Ort', 'text'], ['telefon', 'Telefon', 'tel'],
            ], 'list' => ['name', 'kunde_id', 'ort_id', 'gebaeude', 'ansprechpartner']],
        'lieferanten' => ['title' => 'Lieferanten und Hersteller', 'single' => 'Lieferant / Hersteller', 'modul' => 'stammdaten', 'label' => ['name'], 'order' => 'name', 'docs' => true,
            'fields' => [
                ['art', 'Art', 'select', ['Lieferant', 'Hersteller', 'Lieferant und Hersteller', 'Werkstatt / Service']], ['name', 'Name', 'text', 'req'],
                ['ansprechpartner', 'Ansprechpartner', 'text'], ['strasse', 'Straße', 'text'], ['ort_id', 'PLZ / Ort', 'ref', 'orte'],
                ['telefon', 'Telefon', 'tel'], ['email', 'E-Mail', 'email'], ['kundennr_bei', 'Unsere Kundennummer dort', 'text'], ['kreditor', 'Kreditor (DATEV)', 'text'], ['bemerkung', 'Bemerkung', 'textarea'],
            ], 'list' => ['name', 'art', 'ort_id', 'ansprechpartner', 'telefon']],

        'automaten' => ['title' => 'Automaten', 'single' => 'Automat', 'modul' => 'automaten', 'label' => ['nummer', 'bezeichnung'], 'order' => 'nummer', 'docs' => true, 'auto' => ['nummer', 1001],
            'fields' => [
                ['nummer', 'Automatennummer', 'text'], ['bezeichnung', 'Bezeichnung', 'text', 'req'],
                ['art', 'Art', 'select', ['Heißgetränke', 'Kaltgetränke', 'Snacks', 'Kombi', 'Wasserspender', 'Sonstiges']],
                ['status', 'Status', 'select', ['aktiv', 'im Lager', 'in Werkstatt', 'ausgemustert']],
                ['standort_id', 'Standort', 'ref', 'standorte'], ['tour_id', 'Tour', 'ref', 'touren'], ['aufgestellt_am', 'Aufgestellt am', 'date'],
                ['planumsatz', 'Planumsatz je Monat (brutto)', 'dec'], ['mwst', 'MwSt.-Satz Verkauf', 'dec'], ['telemetrie_id', 'Telemetrie- / Geräte-ID (EVA-DTS)', 'text'],
                ['kostenstelle', 'Kostenstelle', 'text'], ['bemerkung', 'Bemerkung', 'textarea'],
            ], 'list' => ['nummer', 'bezeichnung', 'art', 'status', 'standort_id', 'tour_id', 'planumsatz']],
        'komponenten' => ['title' => 'Komponenten', 'single' => 'Komponente', 'modul' => 'automaten', 'label' => ['typ', 'bezeichnung', 'seriennr'], 'order' => 'typ, bezeichnung', 'docs' => true,
            'fields' => [
                ['typ', 'Typ', 'select', MV_KOMPONENTENTYPEN, 'req'], ['bezeichnung', 'Bezeichnung / Modell', 'text', 'req'], ['hersteller_id', 'Hersteller', 'ref', 'lieferanten'],
                ['seriennr', 'Seriennummer', 'text'], ['lieferant_id', 'Lieferant', 'ref', 'lieferanten'], ['kaufdatum', 'Kaufdatum', 'date'], ['kaufpreis', 'Kaufpreis (netto)', 'dec'],
                ['nutzungsdauer_jahre', 'Abschreibung (Jahre)', 'int'], ['lebensdauer_tage', 'Lebensdauer (Tage)', 'int'], ['lebensdauer_verkaeufe', 'Lebensdauer (Verkäufe)', 'int'],
                ['status', 'Status', 'select', ['im Lager', 'eingebaut', 'defekt', 'in Reparatur', 'ausgemustert'], 'ro'],
                ['automat_id', 'Eingebaut in', 'ref', 'automaten', 'ro'], ['eingebaut_am', 'Eingebaut am', 'date', 'ro'], ['bemerkung', 'Bemerkung', 'textarea'],
            ], 'list' => ['typ', 'bezeichnung', 'seriennr', 'status', 'automat_id', 'eingebaut_am']],
        'stoerungen' => ['title' => 'Störungen und Service', 'single' => 'Störung', 'modul' => 'automaten', 'label' => ['datum', 'art'], 'order' => 'datum DESC',
            'fields' => [
                ['automat_id', 'Automat', 'ref', 'automaten', 'req'], ['datum', 'Gemeldet am', 'date', 'req'], ['art', 'Art', 'select', ['Störung', 'Wartung', 'Reparatur', 'Reinigung', 'Hygienekontrolle (HACCP)', 'Kundenreklamation']],
                ['quelle', 'Gemeldet von', 'select', ['Fahrer', 'Kunde', 'Telemetrie', 'Techniker', 'Büro']], ['beschreibung', 'Beschreibung', 'textarea', 'req'],
                ['prioritaet', 'Priorität', 'select', ['normal', 'hoch', 'sofort']], ['status', 'Status', 'select', ['offen', 'in Arbeit', 'erledigt']],
                ['mitarbeiter_id', 'Techniker', 'ref', 'mitarbeiter'], ['erledigt_am', 'Erledigt am', 'date'], ['massnahme', 'Maßnahme', 'textarea'],
                ['kosten', 'Kosten (netto)', 'dec'], ['dauer_min', 'Dauer (Minuten)', 'int'],
            ], 'list' => ['datum', 'automat_id', 'art', 'prioritaet', 'status', 'mitarbeiter_id', 'erledigt_am']],
        'kosten' => ['title' => 'Kosten je Automat', 'single' => 'Kostenbuchung', 'modul' => 'automaten', 'label' => ['datum', 'art'], 'order' => 'datum DESC', 'lock' => 'datum',
            'fields' => [
                ['automat_id', 'Automat', 'ref', 'automaten', 'req'], ['datum', 'Datum', 'date', 'req'], ['art', 'Kostenart', 'select', ['Reparatur', 'Ersatzteile', 'Reinigung', 'Strom', 'Wasser', 'Fahrtkosten', 'Abschreibung', 'Sonstiges'], 'req'],
                ['betrag', 'Betrag (netto)', 'dec', 'req'], ['beleg', 'Beleg', 'text'], ['text', 'Text', 'text'],
            ], 'list' => ['datum', 'automat_id', 'art', 'betrag', 'beleg', 'text']],

        'warengruppen' => ['title' => 'Warengruppen', 'single' => 'Warengruppe', 'modul' => 'waren', 'label' => ['name'], 'order' => 'name',
            'fields' => [['name', 'Name', 'text', 'req'], ['erloeskonto', 'Erlöskonto (DATEV)', 'text']]],
        'artikel' => ['title' => 'Artikel', 'single' => 'Artikel', 'modul' => 'waren', 'label' => ['artikelnr', 'bezeichnung'], 'order' => 'bezeichnung', 'auto' => ['artikelnr', 1001],
            'fields' => [
                ['artikelnr', 'Artikelnummer', 'text'], ['bezeichnung', 'Bezeichnung', 'text', 'req'], ['warengruppe_id', 'Warengruppe', 'ref', 'warengruppen'],
                ['einheit', 'Einheit', 'select', ['Stück', 'Portion', 'Becher', 'kg', 'Liter']], ['ean', 'EAN', 'text'],
                ['ek', 'Einkaufspreis (netto)', 'dec'], ['vk', 'Verkaufspreis (brutto)', 'dec'], ['mwst', 'MwSt. %', 'dec'],
                ['lieferant_id', 'Lieferant', 'ref', 'lieferanten'], ['mhd_tage', 'Haltbarkeit (Tage)', 'int'], ['aktiv', 'Aktiv', 'bool'],
            ], 'list' => ['artikelnr', 'bezeichnung', 'warengruppe_id', 'ek', 'vk', 'mwst', 'aktiv']],
        'lager' => ['title' => 'Lager und Fahrzeuge', 'single' => 'Lager', 'modul' => 'waren', 'label' => ['name'], 'order' => 'name',
            'fields' => [['name', 'Name', 'text', 'req'], ['art', 'Art', 'select', ['Lager', 'Fahrzeug']], ['kennzeichen', 'Kennzeichen', 'text'], ['mitarbeiter_id', 'Verantwortlich', 'ref', 'mitarbeiter']]],
        'faecher' => ['title' => 'Fachbelegung', 'single' => 'Fach', 'modul' => 'automaten', 'label' => ['fach'], 'order' => 'fach',
            'fields' => [
                ['automat_id', 'Automat', 'ref', 'automaten', 'req'], ['fach', 'Fach / Wahltaste', 'text', 'req'], ['artikel_id', 'Artikel', 'ref', 'artikel', 'req'],
                ['preis', 'Verkaufspreis (brutto)', 'dec'], ['kapazitaet', 'Kapazität', 'int'], ['meldebestand', 'Meldebestand', 'int'],
            ], 'list' => ['fach', 'artikel_id', 'preis', 'kapazitaet', 'meldebestand']],

        'touren' => ['title' => 'Touren', 'single' => 'Tour', 'modul' => 'touren', 'label' => ['name'], 'order' => 'name',
            'fields' => [
                ['name', 'Name', 'text', 'req'], ['mitarbeiter_id', 'Fahrer', 'ref', 'mitarbeiter'], ['fahrzeug_id', 'Fahrzeug', 'ref', 'lager'],
                ['wochentage', 'Wochentage', 'text'], ['bemerkung', 'Bemerkung', 'textarea'],
            ], 'list' => ['name', 'mitarbeiter_id', 'fahrzeug_id', 'wochentage']],

        'rabatte' => ['title' => 'Rabatte und Provisionen', 'single' => 'Rabatt / Provision', 'modul' => 'rabatte', 'label' => ['bezeichnung'], 'order' => 'bezeichnung',
            'fields' => [
                ['bezeichnung', 'Bezeichnung', 'text', 'req'], ['kunde_id', 'Empfänger (Kunde)', 'ref', 'kunden', 'req'], ['automat_id', 'Automat (leer = alle des Kunden)', 'ref', 'automaten'],
                ['art', 'Art', 'select', ['provision' => 'Provision in % vom Umsatz', 'staffel' => 'Staffelprovision', 'pauschale' => 'Pauschale je Zeitraum', 'standplatz' => 'Standplatzgebühr je Monat', 'einmalig' => 'Einmalzahlung', 'zuschuss' => 'Zuschuss vom Kunden'], 'req'],
                ['wert', 'Prozent / Betrag', 'dec'], ['staffel', 'Staffel (z. B. „0:5; 400:10“ = ab 400 € 10 %)', 'text'],
                ['basis', 'Bemessung', 'select', ['brutto' => 'Umsatz brutto', 'netto' => 'Umsatz netto']],
                ['intervall', 'Abrechnung', 'select', ['monat' => 'monatlich', 'quartal' => 'vierteljährlich', 'halbjahr' => 'halbjährlich', 'jahr' => 'jährlich']],
                ['gueltig_ab', 'Gültig ab', 'date'], ['gueltig_bis', 'Gültig bis', 'date'], ['bemerkung', 'Bemerkung', 'textarea'],
            ], 'list' => ['bezeichnung', 'kunde_id', 'automat_id', 'art', 'wert', 'intervall', 'gueltig_ab', 'gueltig_bis']],
        'vertraege' => ['title' => 'Verträge', 'single' => 'Vertrag', 'modul' => 'rabatte', 'label' => ['vertragsnr', 'art'], 'order' => 'beginn DESC', 'docs' => true,
            'fields' => [
                ['vertragsnr', 'Vertragsnummer', 'text'], ['art', 'Art', 'select', ['Aufstellvertrag', 'Mietvertrag (wir vermieten)', 'Mietvertrag (wir mieten)', 'Ratenkauf (Kunde zahlt)', 'Ratenkauf / Leasing (wir zahlen)', 'Wartungsvertrag'], 'req'],
                ['kunde_id', 'Vertragspartner', 'ref', 'kunden'], ['lieferant_id', 'oder Lieferant / Leasinggeber', 'ref', 'lieferanten'], ['automat_id', 'Automat', 'ref', 'automaten'],
                ['beginn', 'Beginn', 'date', 'req'], ['ende', 'Ende', 'date'], ['rate', 'Rate (netto)', 'dec'],
                ['intervall', 'Zahlungsweise', 'select', ['monat' => 'monatlich', 'quartal' => 'vierteljährlich', 'halbjahr' => 'halbjährlich', 'jahr' => 'jährlich']],
                ['anzahl_raten', 'Anzahl Raten', 'int'], ['kuendigungsfrist', 'Kündigungsfrist', 'text'], ['bemerkung', 'Bemerkung', 'textarea'],
            ], 'list' => ['vertragsnr', 'art', 'kunde_id', 'automat_id', 'beginn', 'ende', 'rate', 'intervall']],
    ];
    return $r;
}

// Richtung eines Vertrags: +1 Einnahme für uns, -1 Ausgabe
function mv_vertrag_richtung(string $art): int
{
    return in_array($art, ['Mietvertrag (wir mieten)', 'Ratenkauf / Leasing (wir zahlen)'], true) ? -1 : (in_array($art, ['Aufstellvertrag', 'Wartungsvertrag'], true) ? 0 : 1);
}

function mv_field_options($opts): array
{
    // Liste ['a','b'] oder Zuordnung ['key' => 'Text']
    return array_is_list($opts) ? array_combine($opts, $opts) : $opts;
}
