# SG Montagerechner v3

## Überblick

Der **SG Montagerechner** ist ein massgeschneidertes WordPress-/WooCommerce-Plugin für den Sanigroup Webshop.  
Es verbindet den Verkauf von Haushaltsgeräten mit Montage- und Lieferdiensten und automatisiert den gesamten Ablauf von der Bestellung bis zur Terminvereinbarung.

Das Plugin deckt drei große Bereiche ab:
1. **Preis- & Service-Berechnung** (Montage, Lieferung, Versand, Abholung)
2. **Automatisierte Terminbuchung** (online & telefonisch)
3. **Bestell- und E-Mail-Flows** für eine reibungslose Kommunikation

---

## Funktionsüberblick

- Pflichtfeld **„Terminvereinbarung“** im Checkout (sichtbar bei Montage/Etagenlieferung und gültiger PLZ)
- **Preisberechnung** anhand von Basispreisen, PLZ-Radius, Etagenaufschlägen, Rabatten und Pooling
- **Automatisierte Online-Terminierung** mit realen Dauerzeiten, Team-Routing und Region-Shortcodes
- Unterschiedliche **E-Mail-Flows** für Online- und Telefonterminierung
- **WooCommerce-Status-Erweiterung**: z. B. `sg-paid` (Zahlung erhalten) und `sg-arrived` (Ware eingetroffen)
- Automatische Trigger für Folge-Status und E-Mail-Benachrichtigungen
- **Shortcodes** für Richtpreise, Kartenanzeige und automatische Sequenzen
- Admin-Einstellungen für Regionen, Team-Zuordnung und E-Mail-Texte
- Automatisches Logging aller Statuswechsel und Mailentscheidungen

---

## Auftrags-Flow (Bestellung → Termin)

1. Kunde bestellt Produkt mit Montageoption
2. Plugin prüft Lager & Zahlung (Gate-Mechanismus)
3. Kunde erhält Terminlink (online) oder Info-Mail (telefonisch)
4. Admin/Monteure sehen nur zulässige Folgeaktionen im Backend (Status-Guard)
5. Bei Status „Ware eingetroffen“ werden automatisch passende E-Mails ausgelöst
6. Kunde bucht Termin über das Online-Interface oder wird telefonisch kontaktiert
7. Status „Service erfolgt“ schließt den Auftrag ab

---

## Technische Details

### WooCommerce Status & Trigger
- **Neue Status**: `sg-paid`, `sg-arrived`, `service-done`
- **Guard-Funktion** verhindert falsche Status-Sprünge
- **Meta Box** „Nächster Schritt“ zeigt nur erlaubte Folgeaktionen
- **Order Actions**: Schnellauswahl für Zahlung/Lager-Eingang

### E-Mail Integration
- Zusätzliche WooCommerce-Mails:
  - **Termin freigegeben**
  - **Ware eingetroffen**
  - **Telefonische Terminvereinbarung**
- Regionenspezifische Buchungslinks (Parameter: `region`, `order`, `name`, `email`)
- Vorlagen im Backend anpassbar

### Online-Buchung
- Erweiterung der öffentlichen Buchungsoberfläche um **Start–Ende Zeiten**
- Zeitfenster-Definitionen (08:00–18:00, 5 Blöcke)
- Mindestvorlauf über `sg_lead_time_days` steuerbar

### Shortcodes
- `[sg_booking region="zuerich_limmattal" type="montage"]`
- `[sg_booking_both region="basel_fricktal"]`
- `[sg_booking_auto region="basel_fricktal"]`  
  (Parameter: `m`, `e`, `region`, `order` → automatische Sequenz)

---

## Optionen (autoload=yes)

| Option | Beschreibung |
| --- | --- |
| `sg_booking_mapping` | Zuordnung von Teams, Shortcodes und Regionen |
| `sg_regions` | Ziel-URLs pro Region |
| `sg_email_templates` | HTML-Defaults für Online/Offline-E-Mails |
| `sg_mode_overlap_guard` | Verhindert Überschneidungen (aktuell `false`) |
| `sg_lead_time_days` | Mindestvorlauf in Arbeitstagen für Buchungen |

---

## Vorteile

- Automatisiert Preisberechnung & Terminbuchung  
- Reduziert Fehler durch klare Statusführung  
- Transparente Kunden-Kommunikation per E-Mail  
- Nahtlose Integration mit WooCommerce
- Spart Zeit im Backoffice und verbessert Kundenerlebnis  

---

## Hinweise

- Logging kann via Filter `sg_mr_logging_enabled` aktiviert werden
- Zeitfenster: 08:00–10:00, 10:00–12:30, 13:00–15:00, 15:00–16:30, 16:30–18:00
- Reminder-Logik: 5 Arbeitstage nach „Warten auf Zahlung“
- Kompatibel mit PHP 8.2+ und WooCommerce (aktuelle Versionen)

---

© Sanigroup – internes Plugin für Montageplanung & Terminbuchung
