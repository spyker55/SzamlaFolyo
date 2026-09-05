{{--
    A böngészőfül ikonja. Külön komponens ugyanazon az alapon, mint a
    betűkészlet: három `<head>` van (nyilvános oldal, belépés, alkalmazás), és
    a hármat kézzel szinkronban tartani nem lehet.

    Két formátum, mert a kettő mást tud. Az **SVG** minden méretben éles, ezt
    használja minden mai böngésző. Az **ICO** a tartalék, és 16, 32 és 48
    pixeles változatot is tartalmaz — a rendszer választ közülük, például
    amikor a felhasználó az asztalra teszi ki az oldalt.

    A `?v=` nem babona: a `favicon.ico` korábban **nulla bájtos** volt (a
    Laravel így telepít), és épp a favicont gyorsítótárazza a böngésző a
    legmakacsabbul — az üres válasz enélkül heteken át megmaradna. Ha egyszer
    változik az ikon, ezt a számot kell növelni.
--}}
@php($v = '2')
<link rel="icon" href="{{ asset('favicon.ico') }}?v={{ $v }}" sizes="32x32">
<link rel="icon" href="{{ asset('favicon.svg') }}?v={{ $v }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v={{ $v }}">
