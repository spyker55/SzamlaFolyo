# Számlafolyó — termékdefiníció

**Verzió:** v0.2 · 2026. július
*(v0.1-hez képest: számla-központú termékből iktató- és ügykezelő rendszer lett, a pénzügyi rész modulként.)*

---

## Egy mondatban

A Számlafolyó **iktató- és ügykezelő rendszer kis cégeknek**: a beérkező és kimenő iratokat AI olvassa be és iktatja, egy ügybe fűzi őket, és megmutatja, **mi jár le, kinél van, és mit kell kifizetni**.

**Az ígéret:** semmi nem csúszik el.

## A probléma

A 20–150 fős, ERP nélküli cégeknél az iratkezelés ma egy Excel-tábla, amit kézzel töltenek: *Sorszám · Előadó · Irattári jel · Érkezett · Beküldő · Irat száma · Mellékletek · Tárgy · Kezelési feljegyzések · Határidő · Irattárba helyezés.* Minden bejövő levél, szerződés, teljesítés, számla és díjbekérő így kerül be, egyesével.

Következmények:

- napi órák mennek el gépeléssel, és a törzsadat romlik *(valós példa egy ügyfélnél: a típuslistában egyszerre szerepel a „bejövő számla" és a „bjövő számla")*
- **nem látszik, ki min dolgozik és mi jár le** — az Excelben van határidő oszlop, de nincs figyelmeztetés
- az összetartozó iratok — szerződés, teljesítés, számla, díjbekérő — nem kapcsolódnak össze
- a kifizetendő kötelezettség sehol nincs egyben

## Célügyfél

**Kinek:** 20–150 fős magyar cég, ERP nélkül, Excellel iktatva, külsős könyvelőirodával.
Iparág: építőipar és alvállalkozói kör, nagykereskedelem, szállítmányozás, több telephelyes szolgáltatás, szerviz.

**Felhasználó:** irodavezető / pénzügyi ügyintéző / előadók (napi használat).
**Vásárló:** ügyvezető — a kontrollért fizet, nem az időmegtakarításért.

**Kinek nem:**

- **közfeladatot ellátó szervek** — ott az iratkezelés jogszabályban szabályozott és tanúsított szoftvert kíván (3/2018 BM r.); ezt a piacot a DMS One és társai birtokolják, egyedül fejlesztőként nem támadható. Ezt a marketingben is ki kell mondani.
- SAP / Business Central felhasználók · egyéni vállalkozó és mikrocég

## Pozicionálás: az önkiszolgáló alternatíva

A magyar piacon a hasonló rendszerek (Dox / Ügykezelő.hu — mindkettő a DOCCA-tól) **sales-vezéreltek**: öröklicenc 7 millió forinttól, bevezetési projekt, óradíjas tanácsadás, telefonos kapcsolatfelvétel.

A Számlafolyó ennek a szándékolt ellentéte:

| Ők | Mi |
|---|---|
| ajánlatkérés, bevezetési projekt | regisztráció, 5 perc alatt működik |
| ár egyeztetés után | kiírt havidíj |
| e-mail-központú ügykezelés | **dokumentum-központú** — a papír is első osztályú állampolgár |
| a felhasználó tölti ki az adatlapot | **az AI tölti ki, az ember jóváhagyja** |
| mindent tud (flotta, HR, munkaidő) | iktatás + ügykövetés + pénzügy, semmi más |

Az AI-alapú kitöltés az egyetlen valódi technológiai előnyünk — a versenytársak a *rögzítés kényelmét* javítják, mi a rögzítést vesszük el.

## Mit csinál

| | |
|---|---|
| **Bevitel** | dedikált e-mail cím · mobilos fotózás · PDF feltöltés · meglévő iktató-Excel importja induláskor |
| **Iktatás** | AI kinyeri a tárgyat, beküldőt, irat számát, dátumot, határidőt; iktatószámot ad (főszám/alszám/év); típus **irány + fajta** bontásban, kötött listából |
| **Ügy** | az összetartozó iratokat egy ügybe fűzi, és a kapcsolatot magától javasolja a tartalom alapján |
| **Határidők** | mi jár le, kinél van, mi késett — előadónként és cégszinten |
| **Pénzügyi modul** | fizetési naptár · NAV Online Számla egyeztetés · **banki utalási fájl** · havi export a könyvelőnek |
| **Irattár** | irattári jel és őrzési idő alapján mi helyezhető irattárba, minek járt le a megőrzése |

## Miben más

- **Iktató, ami magától kitölti magát.** A kategória létezik, az AI-bevitel nem.
- **A díjbekérőt is kezeli.** Nem számviteli bizonylat, ezért sehol nincs nyilvántartva — mégis abból fizet a cég.
- **Banki utalási csomag.** Kézzelfogható, azonnal demózható.
- **Egy nap alatt bevezethető.** Ez nem funkció, hanem a teljes üzleti modell.

## Árazás

Cégre szabott, dokumentumszám szerinti sávok — **nem per felhasználó** (az előadók száma nem nőhet költséggel, különben nem viszik be a céget).
Nagyságrend: ~10 / 20 / 40 ezer Ft/hó. 30 napos próba, freemium nélkül.

## Amit v1-ben NEM építünk

Többszintű jóváhagyási workflow · költséghely-dimenziók · főkönyv · banki API (PSD2) · ERP-csatlakozó · munkaidőmérés, flotta, HR és minden más „amíg úgyis itt vagyunk" funkció · angol nyelv · közigazgatási tanúsítás.

## Amit korán kezelni kell

**Adatbizalom:** EU-s tárolás, világos adatkezelési tájékoztató, egyenes válasz arra, mi történik az adattal az AI-feldolgozás során.
**Megőrzés:** a bizonylat- és iratmegőrzési követelmények befolyásolják a tárolási architektúrát. *(Jogi megerősítést igényel — nem építünk feltételezésre.)*

## Mit tesztelünk legelőször

10 célcégnél, fejlesztés előtt: **hány irat/hó · mennyi idő darabonként · mi csúszott el az elmúlt évben és mibe került · ki nézi ma a határidőket · mit fizetne érte az ügyvezető.**

Sikerkritérium: 10-ből legalább 6 magától kimondja, hogy ez fáj, és rákérdez az árra.
