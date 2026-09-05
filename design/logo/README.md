# Handoff: SzámlaFolyó logó (3A irány)

## Overview
A SzámlaFolyó márkajel és szóvédjegy (logo lockup) implementálása a weboldal fejlécében és faviconként.
A SzámlaFolyó AI-alapú szolgáltatás: könyvelési dokumentumokat olvas ki és exportálhatóvá tesz.
A kiválasztott irány a `3A`: tömör terrakotta négyzet, benne krém színű dokumentum levágott lapsarokkal,
a szövegsorok helyén két hullám (a lapon átfolyó adat), mellette a kétszínű `SzámlaFolyó` szóvédjegy.

## About the Design Files
A csomagban lévő HTML fájl **design referencia** — a szándékolt megjelenést mutató prototípus,
nem közvetlenül átvehető production kód. A feladat a design újraalkotása a cél kódbázis
saját környezetében (React, Vue, Svelte, natív stb.), annak bevett mintáival és könyvtáraival.
Ha még nincs ilyen környezet, válaszd a projekthez legjobban illő keretrendszert és ott valósítsd meg.
A mellékelt SVG fájlok viszont **közvetlenül használhatók** assetként.

## Fidelity
**High-fidelity.** A színek, tipográfia, geometria és méretek véglegesek — az itt megadott hex értékek,
SVG path-ok és betűbeállítások pixelpontosan átvehetők.

## Screens / Views

### 1. Header lockup (vízszintes, jel + szóvédjegy)
- **Purpose**: a márka azonosítása a weboldal fejlécében, e-mailekben, exportált PDF-ek fejlécében.
- **Layout**: `display: flex; align-items: center; gap: 16px;` — balra zárt, soha nem középre.
- **Components**:
  - **Jel (mark)**: 56 × 56 px négyzet, `border-radius: 0`, fill `#be6846`.
    Benne a dokumentum: `M14 11 H34 L42 19 V45 H14 Z`, fill `#f5ece2` (56-as viewBox koordinátákban,
    tehát a lapsarok levágott, nem lekerekített).
    Két hullám a lapon, stroke `#be6846`, `stroke-width: 3`, fill none:
    `M18 27 C22 23, 26 31, 30 27 S38 23, 38 27` és `M18 35 C22 31, 26 39, 30 35 S38 31, 38 35`.
    Skálázás: a jel minden méretben a 56-as viewBox arányait tartja; 32 px alatt használd a
    favicon-egyszerűsítést (lásd lentebb).
  - **Szóvédjegy (wordmark)**: `SzámlaFolyó` egy szóban, szóköz nélkül.
    Font: Archivo, `font-weight: 800`, `font-size: 36px`, `line-height: 1`, `letter-spacing: -0.035em`.
    Szín: `Számla` = `#3a3634`, `Folyó` = `#be6846` (a két rész egy szövegfolyam, két `<span>`-ben).
  - **Minimális védőtávolság**: a jel magasságának 25%-a (14 px 56 px-es jelnél) a lockup körül.
  - **Minimális méret**: fejlécben a jel nem lehet kisebb 28 px-nél; a szóvédjegy nem lehet kisebb 20 px-nél.

### 2. Favicon / app ikon (egyszerűsített)
- **Purpose**: böngészőfül, PWA ikon, social avatar.
- **Layout**: teli négyzet, a jel a kerethez közelebb ér, egy hullám marad.
- **Components**: 56-as viewBox, fill `#be6846`; dokumentum `M12 9 H34 L44 19 V47 H12 Z` fill `#f5ece2`;
  egy hullám `M17 31 C22 25, 27 37, 32 31 S39 25, 39 31`, stroke `#be6846`, `stroke-width: 6`.
- 16 px-es kiadásban a hullám elhagyható, csak a lap sziluettje marad.

## Interactions & Behavior
- A fejléc-lockup a főoldalra mutató link. `:hover` állapotban a `Folyó` szórész
  `#a34b2b`-re (egy lépéssel mélyebb terrakotta) vált, `transition: color 120ms ease`. A jel nem változik.
- `:focus-visible`: `outline: 2px solid #be6846; outline-offset: 2px;` — a böngésző alap kék fókuszgyűrűt
  ne hagyd meg.
- Nincs animáció, nincs árnyék, nincs lekerekítés, a jelet nem szabad elforgatni vagy átszínezni.
- Sötét háttéren: a jel változatlan, a `Számla` szórész `#f5ece2`-re vált.
- Responsive: 640 px alatt a szóvédjegy elhagyható, csak a jel jelenik meg (32 px).

## State Management
Nincs állapot — statikus, azonosító elem. Egyetlen kivétel a `:hover` / `:focus-visible`,
amit CSS kezel; nincs szükség JS-re vagy adatlekérésre.

## Design Tokens
| Token | Érték | Használat |
| --- | --- | --- |
| `--brand-terracotta` | `#be6846` | jel háttere, `Folyó` szórész, fókuszgyűrű |
| `--brand-terracotta-deep` | `#a34b2b` | hover / pressed állapot |
| `--brand-cream` | `#f5ece2` | a jelen belüli dokumentum, sötét háttéren a szöveg |
| `--brand-ink` | `#3a3634` | `Számla` szórész, 2px vonalak |
| `--brand-muted` | `#6a6360` | opcionális alcím a lockup alatt |
| Betűtípus | Archivo (400 / 600 / 800) | Google Fonts |
| Betűméret (lockup) | 36 px / weight 800 / tracking -0.035em | szóvédjegy |
| Alcím | 11 px / uppercase / tracking 0.16em | opcionális kísérő sor |
| Border radius | `0` mindenhol | a rendszer alapszabálya |
| Rács / vonalak | 2 px tömör `#3a3634` | szekcióválasztók |

## Assets
- `logo-3a-lockup.svg` — teljes vízszintes lockup (jel + szóvédjegy szövegként; ha nincs Archivo a
  renderelő környezetben, a szöveget path-ra kell konvertálni, vagy a lockupot HTML-ben összeállítani
  a jel-SVG + valódi szöveg párosból — ez utóbbi az ajánlott web-megoldás).
- `logo-3a-mark.svg` — csak a jel, 56 × 56.
- `favicon-3a.svg` — az egyszerűsített ikonváltozat kis méretekhez.
- Betűtípus: Archivo, Google Fonts (`https://fonts.googleapis.com/css2?family=Archivo:wght@400;600;800`).
- A jel teljes egészében vektor — nincs raszteres asset, nincs harmadik féltől származó ikon.

## Files
- `logo-explorations.dc.html` — a teljes explorációs tábla; a `3A` irány a `#3a` id-jű kártyában van
  (a 3. kör első kártyája). Ugyanitt látható a 44 és 24 px-es favicon-próba is.
- `logo-3a-lockup.svg`, `logo-3a-mark.svg`, `favicon-3a.svg` — kiexportált assetek.
