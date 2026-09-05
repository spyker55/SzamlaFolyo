{{--
    Az ÁSZF szövege még nincs meg. Amit tartalmaznia kell — a rendszer mai
    viselkedéséből, nem sablonból:

    - **Kizárólag vállalkozásoknak.** A felhasználó a regisztrációval kijelenti,
      hogy vállalkozásként jár el. Ez nem csak mondat: a cégnyitás érvényes
      magyar adószámot követel (`App\Livewire\App\CegLetrehozas`), különben a
      kikötés papír maradna — a fogyasztóvédelmi jog kógens.
    - A szolgáltatás tárgya: gépi kiolvasás, ember általi ellenőrzéssel. **A
      kiolvasás eredményéért nem vállalunk szavatosságot**; a jóváhagyás az
      ügyfélé, és ő felel a könyvelésbe kerülő adatért.
    - Csomagok, darabkeret, túlhasználat és annak forintplafonja; a keret a
      Stripe számlázási ciklusára szól. Éves fizetés nincs.
    - 14 napos próbaidő kártya nélkül, és mi zárja le (idő **vagy** darabszám).
    - Az eredeti fájlok megőrzési ideje és törlésük az export után.
    - A beküldési e-mail cím: aki arra küld, annak a küldeménye feldolgozásra kerül.
    - Felmondás, a fiók és az adatok sorsa megszűnéskor.
    - Rendelkezésre állás, karbantartás, felelősségkorlátozás.
    - Alkalmazandó jog és a jogviták fóruma.
--}}
<x-layouts.jogi cim="Általános Szerződési Feltételek">
    @include('jogi.keszul', ['mit' => 'Az Általános Szerződési Feltételek'])
</x-layouts.jogi>
