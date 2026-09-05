{{--
    A DM Sans betöltése. Külön komponens, mert mind a három elrendezés
    (nyilvános oldal, belépés, alkalmazás) ugyanazt a betűt használja, és a
    három `<head>` közül egyet mindig elfelejtene az ember.

    A `display=swap` szándékos: a rendszerbetűvel kirajzolt szöveg olvasható
    marad akkor is, ha a Google betűszervere lassú vagy elérhetetlen — a CSS
    tartalék betűsora pontosan erre van.

    Az Archivo **csak a szóvédjegyé**, ezért egyetlen vastagság (800) jön belőle,
    és ugyanabban a kérésben, mint a DM Sans — egy kapcsolat, két betűcsalád.
--}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@800&family=DM+Sans:opsz,wght@9..40,400..800&display=swap" rel="stylesheet">
