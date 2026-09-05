{{--
    Ide az adatkezelési tájékoztató kerül. Amit ez a rendszer ténylegesen
    csinál, és amiről a szövegnek szólnia kell:

    - a bizonylatok és a belőlük kiolvasott adatok magyar szerveren (nethely.hu)
      tárolódnak;
    - a kiolvasáshoz a bizonylat képe egy AI-szolgáltatóhoz (OpenRouter) kerül —
      ez adattovábbítás, és meg kell nevezni;
    - a beküldött eredeti fájlokat az export után töröljük;
    - a beküldési e-mail cím postafiókját a rendszer olvassa;
    - a fizetést a Stripe intézi, kártyaadat nálunk nem tárolódik.
--}}
<x-layouts.jogi cim="Adatkezelési tájékoztató">
    @include('jogi.keszul', ['mit' => 'Az adatkezelési tájékoztató'])
</x-layouts.jogi>
