# E-mail beérkeztetés — terv

A 2. mérföldkő első darabja. A számlák túlnyomó része e-mailben érkezik; ma
ezeket le kell tölteni és kézzel bedobni a Beérkezőbe. Ez a lépés megszünteti
azt a súrlódást, és semmi mást nem változtat: a levél mellékletéből ugyanaz a
`received → extracting → needs_review → iktatva` út indul, mint egy kézi
feltöltésnél.

## Döntések

| Kérdés | Döntés |
|---|---|
| Fogadó cím | `<token>@iktato.szamlafolyo.hu` — **aldomain**, hogy a `szamlafolyo.hu` levelezése érintetlen maradjon |
| Cégazonosítás | cégenként egyedi, kitalálhatatlan token a címben (`company.inbox_token`) |
| Ismeretlen feladó | beérkezik, de megjelölve — semmi nem vész el csendben, de átverést sem iktatunk észrevétlenül |
| Szolgáltató | Resend (`email.received` webhook) |

## Miért token a címben

A bejövő e-mail **hitelesítés nélküli írási út** a rendszerbe: aki ismeri a
címet, iratot tehet a cégedhez. Ezért a cím nem kitalálható (`8 bájt` véletlen,
hexadecimálisan), és a webhook aláírását is ellenőrizzük. A token maga nem
titok abban az értelemben, hogy a feladóknak meg kell adni — ezért nem
elegendő védelem önmagában, csak az első réteg.

A második réteg a feladó ismertsége. Az ismertséget nem kézzel karbantartott
lista adja, hanem a saját döntésed: **ha egy e-mailből érkezett iratot
leiktatsz, a feladó címe onnantól ismert.** Így a lista magától áll össze
abból, amit tényleg elfogadtál, és nem kell előre feltölteni.

Harmadik réteg, hogy e-mailből **soha nem történik automatikus iktatás** — a
levél mellékletei ugyanúgy ellenőrzésre várnak, mint bármi más.

## Felépítés

1. Resend fogadja a levelet a `<token>@iktato.szamlafolyo.hu` címen.
2. `email.received` webhook → `POST /api/email/inbound`.
3. Az endpoint ellenőrzi az aláírást, feloldja a tokenből a céget, és
   **elmenti a nyers payloadot** (`inbound_email.raw_payload`), mielőtt bármit
   értelmezne.
4. A mellékletek nem inline érkeznek: a Resend API-tól kérjük le őket az
   e-mail azonosítójával, időkorlátos letöltő URL-lel.
5. Minden engedélyezett típusú melléklet egy-egy `document` lesz — ugyanaz a
   sha256-alapú duplikátumszűrés, mint a feltöltésnél —, és elindul a kinyerés.

## Idempotencia

A webhookok ismétlődhetnek. A `(company_id, provider_message_id)` egyedi, így
a második kézbesítés nem hoz létre újabb iratot. Ez a feltöltésnél megszokott
sha256-duplikátumszűréstől független, és előbb hat.

## Amit szándékosan nem csinálunk most

- **Nem dobjuk el a melléklet nélküli levelet.** Rögzítjük `no_attachment`
  állapottal, hogy látható legyen — a Beérkező-hibából megtanultuk, hogy egy
  csendben elnyelt eset üres képernyőnek látszik.
- Nem iktatunk automatikusan, nem javaslunk ügyet, nem kezelünk levéltestet
  iratként. Ezek külön döntést érdemelnek.

## Külső függőség (nem kódolható)

A `iktato.szamlafolyo.hu` aldomaint fel kell venni a Resendben, engedélyezni a
fogadást, és beállítani az MX rekordokat a DNS-ben. Amíg ez nincs meg, az
endpoint kész és tesztelt, de nem kap forgalmat.
