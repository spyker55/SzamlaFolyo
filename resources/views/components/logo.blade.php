@props(['class' => 'h-7 w-7'])
{{-- Egy lap, amin átfolyik az adat. Kézzel rajzolva: két path miatt nincs
     szükség ikoncsomagra és a vele járó letöltésre. --}}
<svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none"
     stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true">
    <path d="M6 3h8l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z" class="text-blue-700"/>
    <path d="M8.5 13.5c1.5-1.6 3.5-1.6 5 0s3.5 1.6 5 0" class="text-blue-500"/>
</svg>
