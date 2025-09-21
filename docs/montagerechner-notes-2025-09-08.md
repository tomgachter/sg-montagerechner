# SG Montagerechner – Implementierungsnotizen (2025‑09‑08)

Diese Datei dokumentiert den aktuellen Funktionsumfang, Status‑ und E‑Mail‑Abläufe, Kategorienregeln sowie Admin‑Optionen des Montagerechners.

## Bestellstatus

- Standard (Woo):
  - `on-hold` → „Zahlung offen“
  - `processing` → „In Bearbeitung“
- Custom:
  - `wc-ready-pickup` → „Zur Abholung bereit“
  - `wc-picked-up` → „Abgeholt“ (Danke‑Mail)
  - `wc-service-done` → „Montage/Etagenlieferung erfolgt“ (terminal)

UI/UX:
- Terminale Badges („Abgeholt“, „Montage/Etagenlieferung erfolgt“) im Admin wie „Versendet“ (blau) gestylt.
- „Zur Abholung bereit“ mit dezenter Teal‑Badge.
- Dropdown‑Guard: „Abgeholt“/„Zur Abholung bereit“ nur zulässig, wenn Abholung im Auftrag; „Montage/Etagenlieferung erfolgt“ nur, wenn Service enthalten ist. Andernfalls Statuswechsel wird blockiert und Bestellnotiz gesetzt.
- On‑hold → Processing: Bestellnotiz „Zahlungseingang bestätigt.“

## E‑Mails (Woo‑Style)

- Processing‑Mail: Woo‑Intro entfernt. Stattdessen ein Hinweisblock (Woo‑Stil) vor Bestelldetails:
  - „Wir haben Ihre Zahlung erhalten.“
  - je nach Modus:
    - Montage: „Unser Montageteam meldet sich …“
    - Etagenlieferung: „Unser Lieferteam kontaktiert Sie …“
    - Abholung: „Sie erhalten eine Bestätigungs‑E‑Mail, sobald die Ware abholbereit ist.“
    - Versand: „Wir versenden … Tracking folgt …“
- Custom E‑Mails (Woo‑Header/Footer, HTML):
  - Zur Abholung bereit: inkl. Abholadresse + Link Öffnungszeiten
  - Abgeholt (Danke): kurze Bestätigung
- Versand der Abhol‑Mails ist zusätzlich über Status‑Hooks abgesichert.
- PDF‑Anhänge: Für Standardkunden‑Mails (processing/completed/on‑hold/invoice/new_order) werden hinterlegte PDFs je Kategorie + optional global angehängt.

## Service‑Erkennung (Dominanz)

1) Abholung → dominiert alle anderen
2) Montage vorhanden → „Montage“
3) sonst Etagenlieferung → „Etagenlieferung“
4) sonst Versand

Erkennung basiert vorrangig auf gespeicherter Auswahl (`_sg_mr_sel`), sekundär Fee‑Namen.

## Kategorienregeln Montage

- Keine Montage:
  - `…/kuehlen-und-gefrieren/gefrierschrank/`
  - `…/buegeln/`, `zubehoer-haushaltgeraete/`, `…/staubsauger/`
  - `…/gefriertruhe/`

- Montage nur auf Anfrage (nicht im Warenkorb wählbar):
  - `…/backen-kochen-und-steamen/kaffeemaschinen/`
  - `…/kassiersysteme/`, `…/raumluftwaeschetrockner/`
  - `…/weinschrank/`, `…/gas-herd/`, `…/geschirrspuelen/modular/`
  - `…/backen-kochen-und-steamen/dunstabzug/`

- Montage erlaubt nur bei Bauform = Einbau:
  - `…/geschirrspuelen/geschirrspueler-einbau-45/55/60/`
  - `…/kuehl-gefrierkombi/`, `…/kuehlschrank/`
  - `…/backofen/`, `…/steamer/`, `…/herd/`, `…/mikrowelle/`
  - `…/kochfeld/`, `…/induktions-kochfeld/`, `…/induktions-kochfeld-mit-dunstabzug/`
  - `…/back-mikro-kombi/`, `…/waermeschublade/`, `…/bedienelement/`

- Montage erlaubt (unabhängig von Attribut):
  - `…/kuehlen-und-gefrieren/food-center/`
  - `…/geschirrspuelen/freistehend/`
  - `…/waschmaschine/`, `…/waermepumpentrockner/`, `…/waeschetrockner/`
  - `…/mfh-waschmaschine/`, `…/mfh-waeschetrockner/`
  - `…/waschtrockner-kombigeraet/`

Hinweis: Attribut „Bauform“ (bauform/pa_bauform/Bauform/pa_Bauform) Werte „Einbau“/„Freistehend“.

## Etagenlieferung – Kategorien

- Im Admin pflegbar (Basispreise). Defaults erweitert um alle relevanten Kategorien, inkl. solche mit Startwert 0 (sichtbar, aber inaktiv bis bepreist).
- Etagenlieferung nur innerhalb Radius (wie Montage) und inkl. PLZ‑Fahrzeit.

## Zuschläge

- Montage: Kühlschrank‑Zuschlag ab Höhe > 160 cm (nur Montage). Admin: Schwelle cm + Zuschlag CHF.
- Etagenlieferung: Zuschlag wenn Gewicht > 60 kg UND Höhe > 170 cm. Admin: Gewicht kg, Höhe cm, Zuschlag CHF. (Stiller Zuschlag, kein Titelzusatz.)

## Kochfeld & Turm‑Montage

- Kochfeld: Montageart „flächenbündig“ / „aufliegend“ mit eigenem Basispreis/Aufpreis. Auswahl in Produkt‑Rechner und Warenkorb wirksam.
- Turm‑Montage: Option sichtbar und berechnet in den Wasch-/Trockner‑Kategorien inkl. MFH.

## Produktseite – UX

- Button: „Montage ab CHF X – jetzt PLZ prüfen“ (bzw. „– nur auf Anfrage“ bei Anfrage‑Kategorien).
- Unter dem Button bei Anfrage‑Kategorien ein kurzer Hinweis mit Kontakt‑Link.
- Rechner (Karte): PLZ, ggf. Kochfeld‑Option, Express‑Checkbox, Richtpreis (Fixpreis + Fahrzeit) und „nur auf Anfrage“-Hinweis.

Anzeige‑Logik:
- Button/Rechner erscheinen, wenn Montage erlaubt ODER „auf Anfrage“ ist und ein Basispreis > 0 hinterlegt ist.
- Warenkorb zeigt „Montage“ nur, wenn Montage erlaubt ist (nicht bei „auf Anfrage“) und Basispreis > 0 vorhanden ist.

## Admin – Parameter & Pflege

- Versand & Parameter:
  - PLZ‑Radius (Minuten), Freiminuten, Tarif CHF/Minute
  - Zuschläge: Altgerät, Turm‑Montage
  - Express: aktiv, Basis, pro Minute, Schwelle, Zieltage, Tooltip
  - Kochfeld: Basis/Aufpreise
  - Montage‑Stückzahlrabatte
  - Abholung: Adresse, Öffnungszeiten‑URL
  - Montage‑Zuschlag Kühlschrank (Schwelle, Zuschlag)
  - Etage‑Zuschlag (Gewicht, Höhe, Zuschlag)
- PDFs: je Kategorie (Attachment‑ID oder URL) + optional global
- Preise: Montage‑/Etage‑Basispreise je Kategorie

## Technische Notizen

- E‑Mail‑Override: `templates/emails/customer-processing-order.php` entfernt Standard‑Intro und nutzt unseren Hinweisblock über Woo‑Partials. Custom Mails sind HTML/Woo‑Style (E‑Mail‑Typ im Woo‑Backend einstellbar).
- Status‑Guards/Badges/Actions: in `sg-montagerechner.php` implementiert.
- JS: Produktrechner berücksichtigt Kochfeld‑Typ & Express (`sg-montage.js`).

## To‑Do (optional/später)

- Sanitärgeräte (Dusch‑WC, Enthärtungsanlagen) analog integrieren.
- Optionaler Hinweis vor dem Produkt‑Rechner bei „auf Anfrage“ (nicht nur im Ergebnis) – derzeit unter dem Button vorhanden.

