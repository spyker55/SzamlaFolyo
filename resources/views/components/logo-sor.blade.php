@props(['jel' => 'h-7 w-7', 'szoveg' => 'text-xl'])
{{--
    A teljes logó: jel + szóvédjegy, balra zárva, egy sorban.

    A „SzámlaFolyó" egyetlen szó, két színnel — nem két szó egymás mellett,
    ezért nincs köztük szóköz és nem törhet. A `Folyó` rész a márkaszínt viszi,
    és linkben állva sötétebbre vált; ezt a `.logo-folyo` osztály intézi a
    designrendszerben, nem itt.

    A méret hívónként változik, mert a fejléc és a bejelentkező kártya nem
    ugyanaz a helyzet — de a designcsomag alsó határa mindkettőre áll: a jel ne
    legyen 28 pixelnél, a szöveg 20-nál kisebb ott, ahol ez azonosít.
--}}
<span {{ $attributes->merge(['class' => 'logo-sor']) }}>
    <x-logo :class="$jel"/>
    <span class="logo-szoveg {{ $szoveg }}">Számla<span class="logo-folyo">Folyó</span></span>
</span>
