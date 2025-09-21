# Montagerechner – Team‑Leitfaden (für Shop‑Mitarbeitende)

Dieser Leitfaden erklärt den Ablauf mit Bestellstatus, Kunden‑E‑Mails und wie Montage‑ und Etagenlieferpreise entstehen. Er hilft bei der täglichen Abwicklung (Zahlung, Abholung, Service‑Termine).

## 1) Status – Was setzen wir wann, und welche E‑Mails gehen raus?

- Zahlung offen (`In Wartestellung` / `on-hold`)
  - Entsteht z. B. bei Banküberweisung.
  - Kunde: Standard‑Woo E‑Mail „Zahlung offen“ geht raus.

- Zahlung eingegangen (`In Bearbeitung` / `processing`)
  - Setzen, sobald Zahlung eingetroffen ist (bei Payrexx meist automatisch).
  - Kunde: Es geht die „In Bearbeitung“‑E‑Mail raus (Woo‑Design) mit Hinweisblock:
    - Immer: „Wir haben Ihre Zahlung erhalten.“
    - Je nach Fall:
      - Abholung: „Sie erhalten eine E‑Mail, sobald abholbereit.“
      - Montage: „Montageteam meldet sich zur Terminierung.“
      - Etagenlieferung: „Lieferteam kontaktiert Sie telefonisch.“
      - Versand: „Wir versenden in Kürze. Tracking folgt.“
  - Notiz: Beim Wechsel von `on-hold` → `processing` wird automatisch die Bestellnotiz „Zahlungseingang bestätigt.“ erzeugt.

- Zur Abholung bereit (`wc-ready-pickup`)
  - Setzen, sobald Ware im Laden/Depot zur Abholung bereit liegt.
  - Kunde: E‑Mail „Zur Abholung bereit“ (Woo‑Design) mit Abholadresse + Öffnungszeiten‑Link.
  - Hinweis: Dieser Status ist nur zulässig, wenn im Auftrag „Abholung“ ausgewählt wurde (sonst wird der Wechsel blockiert und eine Notiz angelegt).

- Abgeholt (`wc-picked-up`)
  - Setzen, wenn Kunde abgeholt hat.
  - Kunde: Danke‑E‑Mail (Woo‑Design).
  - Hinweis: Nur zulässig bei Abholung, sonst blockiert.

- Montage/Etagenlieferung erfolgt (`wc-service-done`)
  - Terminaler Abschluss für Service‑Aufträge (Montage oder Etagenlieferung).
  - Kunde: Keine E‑Mail.
  - Hinweis: Nur zulässig, wenn der Auftrag einen Service enthält, sonst blockiert.

Status‑Badges im Admin:
- „Abgeholt“ & „Montage/Etagenlieferung erfolgt“ sind blau (analog „Versendet“).
- „Zur Abholung bereit“ ist dezent grün/türkis.

## 2) Wo sehe ich, was der Kunde gewählt hat?

- In der Bestellung sind Gebühren‑Zeilen und/oder interne Auswahl‐Daten hinterlegt (z. B. „Montage …“, „Etagenlieferung …“).
- Im Warenkorb wurde je Position „Service: Versand / Etagenlieferung / Montage / Abholung“ gewählt.
- Abholung dominiert: Wenn „Abholung“ gewählt ist, behandeln wir den Auftrag als Abholung (Montage-/Etage‑Texte werden nicht angezeigt).

## 3) Preise – Wie berechnet sich Montage/Etage?

Gemeinsamkeiten
- PLZ‑Radius: Nur innerhalb des konfigurierten Zeit‑Radius (Minuten). Fahrzeit wird als Minuten über Freigrenze (Freiminuten) berechnet.
- Fixpreis (Basispreis pro Kategorie) + Fahrzeit (CHF/Minute) = Grundpreis der Leistung.

Montage (zusätzlich möglich)
- Altgerät‑Mitnahme (pro Stück)
- Turm‑Montage (nur Wasch/Trockner‑Kategorien)
- Kochfeld‑Montageart: flächenbündig / aufliegend (mit eigenem Basis/Aufpreis)
- Express: Zuschlag (Basis + pro Minute über Schwelle), Ziel‑AT als Info
- Kühlschrank‑Zuschlag: nur bei Montage, wenn Höhe > definiertem Schwellwert (z. B. > 160 cm)

Etagenlieferung (zusätzlich möglich)
- Option „mit Mitnahme Altgerät“
- Schwer & hoch (stiller Zuschlag): wenn Gewicht > X kg UND Höhe > Y cm

Rabatte
- Stückzahlrabatt auf Montage‑Anteile (ab 2/3/4 Stück)
- Versand‑Rabatt, Etage‑Rabatt möglich, wenn Montage im Auftrag ist (admin‑gesteuert)

## 4) Produktseite – Was sieht der Kunde?

- Montage‑Button: „Montage ab CHF X – jetzt PLZ prüfen“
  - Bei Kategorien „nur auf Anfrage“: „Montage ab CHF X – nur auf Anfrage“ + kurzer Hinweis unter dem Button (mit Kontakt‑Link).
- Rechner (Karte): PLZ‑Eingabe, ggf. Kochfeld‑Art und Express‑Option, Richtpreis mit Klartext‑Hinweis.
- Wichtig: Der Button/Rechner erscheinen nur, wenn ein Basispreis hinterlegt ist (auch bei „auf Anfrage“). Im Warenkorb bleibt „Montage“ bei „auf Anfrage“-Kategorien nicht wählbar.

## 5) Was pflegen wir im Admin?

Menü: SG Services →

- „Montagepreise“: Basispreise pro Kategorie (Fixpreis). Nur wenn hier > 0 hinterlegt, kann Montage angezeigt/berechnet werden.
- „Etagenlieferung Preise“: Basispreise pro Kategorie (Fixpreis). Ebenfalls > 0, sonst inaktiv.
- „Versand & Parameter“:
  - PLZ/Radius: Minuten‑Radius, Freiminuten, Tarif (CHF/Minute)
  - Zuschläge: Altgerät, Turm‑Montage
  - Express: aktivieren, Basis, pro Minute, Schwelle, Ziel‑AT, Tooltip
  - Kochfeld: Basis/Aufpreise für flächenbündig/aufliegend
  - Stückzahlrabatt: ab 2/3/4 Geräte
  - Abholung: Adresse und Öffnungszeiten‑URL (wird in Mail „Zur Abholung bereit“ verwendet)
  - Montage‑Zuschlag Kühlschrank: Höhe‑Schwelle (cm) + Zuschlag (CHF)
  - Etagen‑Zuschlag (schwer & hoch): Gewicht (kg), Höhe (cm), Zuschlag (CHF)
- „PDFs“: Montagehinweise als Anhang je Kategorie (Attachment‑ID oder URL) und optional global.

## 6) Häufige Fälle / Hinweise

- Banküberweisung: Bestellung ist „Zahlung offen“. Bei Zahlungseingang Status auf „In Bearbeitung“ setzen → Kunde erhält Processing‑Mail mit Hinweisblock und korrektem Szenario‑Text.
- Abholung: Erst „In Bearbeitung“, dann „Zur Abholung bereit“ → Mail mit Adresse/Öffnungszeiten. Nach Abholung auf „Abgeholt“ → Danke‑Mail.
- Service‑Aufträge: Nach Zahlung „In Bearbeitung“. Nach Durchführung auf „Montage/Etagenlieferung erfolgt“ (keine Mail).
- Status blockiert? Wenn „Abholung“ nicht im Auftrag ist, sind „Zur Abholung bereit/Abgeholt“ nicht zulässig; ohne Service ist „Montage/Etagenlieferung erfolgt“ blockiert.
- Keine E‑Mail angekommen? Prüfen unter WooCommerce → E‑Mails: unsere Custom‑Mails „Zur Abholung bereit“ / „Abholung bestätigt“ sind aktiv und auf HTML gesetzt. SMTP (SureMail/Mailgun) überprüfen.
- PLZ‑Minuten aktualisieren? In „Versand & Parameter“ → „CSV‑Cache (PLZ‑Minuten) leeren“.

## 7) Kontakt & Sonderfälle

- Kategorien „nur auf Anfrage“ (z. B. Dunstabzug, Kaffeemaschinen, Weinschrank, …): Auf Produktseite wird ein Richtpreis gezeigt, im Warenkorb ist Montage nicht direkt buchbar. Kunde bitte über Kontakt aufnehmen.
- Sanitärgeräte (z. B. Dusch‑WC, Enthärtungsanlage): können analog ergänzt werden; aktuell nicht aktiviert.

---
Stand: 2025‑09‑08 (siehe auch `docs/montagerechner-notes-2025-09-08.md`).
