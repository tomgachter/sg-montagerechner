# SG Montagerechner – Variante A

Dieses Plugin erweitert den bestehenden SG Montagerechner um die Variante A (ohne Überschneidung).

## Funktionsüberblick

- Pflichtfeld „Terminvereinbarung" im Checkout (nur sichtbar bei Montage/Etagenlieferung und gültiger PLZ)
- Lager-/Zahlungs-Gate: Online-Terminlink erst nach Zahlungseingang und Lagerprüfung
- Unterschiedliche E-Mail-Flows für Online- und Telefonterminierung
- FluentBooking-Integration mit festen Zeitfenstern, Team-Routing und Region-Shortcodes
- Automatische Slot-Sequenz für Mehrfach-Aufträge via `[sg_booking_auto]`
- AJAX-Handler `sgmr_create_composite_booking` reserviert Slots beim FluentBooking-Client
- Admin-Einstellungen für Region-Links, Team-Shortcodes und E-Mail-Texte
- Öffentliche Fluent-UI mit realer Dauer (`Start – Ende`) inkl. Fallback-JS und ICS/E-Mail-Anpassungen
- Status „Service erfolgt“ triggert Folge-Status (online/telefonisch) samt detailliertem Logging

## Auftrags-Flow (Paid → Arrived)

- **Status**: `wc-`\`sg-paid` („Zahlung erhalten“) und `wc-`\`sg-arrived` („Ware eingetroffen“) werden hinter „In Bearbeitung“ einsortiert und stehen in Filter-, Einzel- und Sammelaktionen bereit.
- **State Guard**: Speichert den zuletzt gültigen Status in `_sgmr_last_status`, blockiert verbotene Sprünge (z. B. `on-hold → sg-arrived`), setzt den alten Status zurück und meldet dies via Admin-Notice und Bestellnotiz.
- **Geführte Admin-Schritte**: Meta Box „SGMR – Nächster Schritt“ blendet nur zulässige Folgeaktionen ein (on-hold ⇒ „Zahlung erhalten“, sg-paid/processing/completed ⇒ „Ware eingetroffen“). Die Status-Dropdown-Optionen werden kosmetisch gesperrt.
- **Order Actions**: Schnellaktionen „Markieren: Zahlung erhalten“ und „Markieren: Ware eingetroffen“ respektieren die Guard-Regeln.
- **E-Mail-Trigger**: Beim Wechsel zu „Zahlung erhalten“ (inkl. Gateways, die direkt nach `processing`/`completed` springen) bzw. „Ware eingetroffen“ werden exakt einmal die Woo-E-Mails gesendet:
  - Sanigroup – Termin freigegeben (sofort)
  - Sanigroup – Ware eingetroffen
  - Sanigroup – Telefonische Terminvereinbarung
  Die Mails erscheinen unter „WooCommerce → Einstellungen → E-Mails“ inkl. Test-Versand.
- **Buchungslink**: Online-Szenarien erzeugen einen region-spezifischen Link mit Parametern `region`, `m`, `e`, `order`, `name`, `email`; telefonische Fälle enthalten keinen Link.
- **Logging**: Jeder Statuswechsel sowie die Mailentscheidungen erzeugen `error_log`-Einträge und Bestellnotizen (`from`, `to`, `terminart`, `wirklich_an_lager`, `email_template`, Grund, anonymisierte Links).

## Optionen (autoload=yes)

| Option | Beschreibung |
| --- | --- |
| `sg_fb_mapping` | Teams, Shortcodes und Region→Team Zuordnung |
| `sg_regions` | Ziel-URLs pro Region |
| `sg_email_templates` | HTML-Defaults für Online/Offline-E-Mails |
| `sg_mode_overlap_guard` | Overlap-Experiment (derzeit `false`) |
| `sg_lead_time_days` | Mindestvorlauf (Arbeitstage) für Online-Buchungen |

## Shortcodes

- `[sg_booking region="zurich_limmattal" type="montage"]`
- `[sg_booking_both region="basel_fricktal"]`
- `[sg_booking_auto region="basel_fricktal"]` (erwartet Parameter `m`, `e`, `region`, `order` in der URL)

## Tests

1. Checkout mit Montage im Radius → Feld sichtbar, required, Speicherung als `_sg_terminvereinbarung`
2. Payrexx-Bestellung mit Lagergerät → Zahlung -> Status `Sofort verfügbar`, Terminlink-Mail (online)
3. Vorkasse ohne Lager → erst Mail bei Status `Ware komplett eingetroffen`
4. Telefonisch gewählte Bestellung → interne Mail, Status `Montage in Planung`
5. Regionenseite → Shortcodes liefern Team-Embeds laut Zuordnung (`[sg_booking region="zuerich_limmattal" ...]`)
6. Reminder → 5 Arbeitstage nach `Warten auf Zahlung`
7. `[sg_booking_auto]` mit `m`/`e` Parametern → automatische Sequenz, Fallback-Meldung bei fehlender Kapazität

## Hinweise

- Logging kann via Filter `sg_mr_logging_enabled` aktiviert werden
- Zeitfenster: 08:00–10:00, 10:00–12:30, 13:00–15:00, 15:00–16:30, 16:30–18:00
- Vorlauf: 2 Arbeitstage (`sg_lead_time_days`) für Online-Buchungen
