@props(['hiba' => null, 'mezo'])

{{-- A bukott ellenőrzés indoklása. A színen kívül szövegesen is kimondjuk,
     mert a szín önmagában nem mondja meg, mi a baj — és színvakság mellett
     nem is látszik. --}}
@if ($hiba)
    <p class="mt-1 text-xs text-red-700">{{ $hiba }}</p>
@endif

@error("mezok.$mezo")
    <p class="fhiba">{{ $message }}</p>
@enderror
