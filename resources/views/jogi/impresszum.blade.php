{{--
    Ide az üzemeltető azonosító adatai kerülnek. Ezeket nem lehet kitalálni,
    ezért nincs itt kitöltetlen űrlap sem — az üresen hagyott mező úgy néz ki,
    mintha elromlott volna valami. Amit a szövegnek tartalmaznia kell:

    - a cég neve, székhelye, cégjegyzékszáma, adószáma;
    - a képviselő neve és elérhetősége;
    - a tárhelyszolgáltató neve és elérhetősége (nethely.hu);
    - a panaszkezelés útja.
--}}
<x-layouts.jogi cim="Impresszum">
    @include('jogi.keszul', ['mit' => 'Az impresszum'])
</x-layouts.jogi>
