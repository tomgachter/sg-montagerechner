# Changelog – 13.02.2025

## Fluent Booking Oberfläche
- Dauerüberschreibung via Server-Hooks (`fluent_booking/public_event_vars`) mit korrekten Minuten/Labels.
- Slot-Labels zeigen nun `Start – Ende`; Fallback-JS aktualisiert bestehende DOM-Knoten.
- E-Mail/Bestätigungen greifen auf `fluent_booking/booking_time_text` zu und liefern lokalisierte Zeitspannen.
- ICS-Einträge übernehmen Titel `{PLZ} – {Name} – {Telefon}` und Beschreibung inkl. Adresse, Services, Gebühren/Versand.

## WooCommerce Statusfluss
- Neue Automatik für „Service erfolgt“ → Folgestatus (`sg-online` bzw. `sg-phone`) inkl. Logging.
- Filter `sgmr_service_done_next_status` erlaubt projektspezifische Overrides.
- Übergänge und Guard lassen `sg-done → sg-online/sg-phone` zu, ohne doppelte E-Mails.

## Datenbasis
- Prefill liefert Gebühren- und Versandpositionen an ICS-/E-Mail-Templates.
- Öffentliche Variablen enthalten `duration_lookup`/`slot_lookup` zur Synchronisation mit dem JS-Fallback.
