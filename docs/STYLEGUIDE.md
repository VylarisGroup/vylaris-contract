# Gestaltung (nach VylarisOffers-Styleguide)

Die Oberfläche folgt dem Styleguide von VylarisOffers: ruhiges Büro-Werkzeug, **eine Markenfarbe**, alles Übrige Slate, Inter, Ringe statt Rahmen, weiche Schatten.

## Umgesetzt
- **Markenfarbe** (Standard Indigo `#4f46e5`): Systemeinstellungen → Allgemein → Markenfarbe. Töne 50–900 werden per `color-mix` abgeleitet (`--brand-*`). Ausgeliefert als `/brand.css` (die CSP erlaubt keine Inline-Styles); nur `#rrggbb` wird akzeptiert.
- **Schrift:** Inter Variable, lokal; `cv11`, `ss01`; tabellarische Ziffern für Zahlen.
- **Layout:** feste Seitenleiste ab 1024 px (gruppierte Navigation, Profilmenü unten, Zähler für Benachrichtigungen), darunter Off-Canvas mit Menü-Knopf; Anmeldeseiten mit zentrierter Karte und weichem Farbfleck.
- **Komponenten:** Karten (`rounded-2xl`, Ring + `shadow-soft`), Eingabefelder (Ring, Fokus `brand-500`, eigener Select-Pfeil), Buttons (`primary`, `secondary`, `ghost`, `danger`, Ladezustand), Badges in Status-Farben (Sky/Amber/Emerald/Rose/Slate/Marke), Tabellen mit Versalien-Kopf.
- **Bewegung:** Seiteneinblendung, Toasts (Erfolg/Info blenden aus, Fehler bleiben), eigener Bestätigungsdialog (`data-confirm`, Esc, Fokus auf »Bestätigen«), Ladespinner, Hover-Anhebung; `prefers-reduced-motion` schaltet fast alles ab.
- **Barrierefreiheit:** Skip-Link, sichtbarer Fokus, `aria-live` für Hinweise, Menü-Knopf mit `aria-expanded`.

## Bewusste Abweichungen
- **Tailwind vorab gebaut** (CLI, `public/assets/app.css`) statt Browser-Build: auf dem Server ist weder Node noch ein Laufzeit-Compiler nötig, und die CSP bleibt streng (`script-src 'self'`). Quelle der Gestaltung: `design/input.css` (siehe unten).
- **Kein Dunkelmodus** (wie im Styleguide, 13.13). Der frühere Umschalter ist entfallen.
- **Keine Dokumente im Styleguide-Sinn für PDF/E-Mail:** Dieses Projekt erzeugt PDFs/Mails derzeit nicht im Markenlayout; E-Mails sind Klartext.

## CSS neu erzeugen (nur für Entwickler)
`design/input.css` + `design/tailwind.config.js`; `npm i tailwindcss@3` und
`npx tailwindcss -c design/tailwind.config.js -i design/input.css -o public/assets/app.css --minify`.
Auf dem Server wird nichts kompiliert.
