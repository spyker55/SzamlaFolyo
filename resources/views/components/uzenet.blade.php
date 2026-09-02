@props(['uzenet' => null, 'tipus' => 'siker'])

@if ($uzenet)
    <div class="alert alert-{{ $tipus }} mb-4 flex items-start justify-between gap-3">
        <span>{{ $uzenet }}</span>
        <button type="button" wire:click="uzenetTorles"
                class="shrink-0 text-current opacity-50 hover:opacity-100" aria-label="Bezárás">&times;</button>
    </div>
@endif
