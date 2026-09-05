<div>
    <x-uzenet :uzenet="$uzenet" :tipus="$uzenetTipus ?? 'siker'"/>
    <h1 class="text-xl font-semibold text-slate-900">Archívum</h1>
    <p class="mt-1 mb-6 text-sm text-slate-500">
        A korábbi exportok. Egy tétel visszahívható, vagy véglegesen törölhető.
    </p>

    @if ($exportok->isEmpty())
        <div class="empty">Még nem készült export.</div>
    @else
        <div class="space-y-3">
            @foreach ($exportok as $export)
                <div class="card" wire:key="export-{{ $export->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                        <div>
                            <div class="font-medium text-slate-900">{{ $export->file_name }}</div>
                            <div class="text-xs text-slate-500">
                                {{ \App\Support\Ido::datumIdo($export->created_at) }} ·
                                {{ $export->documents_count }} tétel ·
                                {{ strtoupper($export->format) }} ·
                                {{ number_format($export->file_bytes / 1024, 0, ',', ' ') }} KB
                                @if ($export->keszitette)
                                    · {{ $export->keszitette->name }}
                                @endif
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($export->letoltheto())
                                <a href="{{ route('export.letoltes', $export) }}" class="btn btn-secondary btn-sm">Letöltés</a>
                            @endif
                            <button wire:click="nyit({{ $export->id }})" class="btn btn-ghost btn-sm">
                                {{ $nyitottExportId === $export->id ? 'Bezár' : 'Tételek' }}
                            </button>
                            {{-- A végleges törlés tulajdonosi jog: ezt már semmi nem hozza vissza. --}}
                            @if ($this->adminisztralhat())
                                <button wire:click="exportTorles({{ $export->id }})"
                                        wire:confirm="Az export és mind a {{ $export->documents_count }} tétele véglegesen törlődik. Biztos?"
                                        class="btn btn-ghost btn-sm text-red-700">Törlés</button>
                            @endif
                        </div>
                    </div>

                    @if ($nyitottExportId === $export->id)
                        <div class="border-t border-slate-100 overflow-x-auto">
                            <table class="tbl">
                                <thead>
                                    <tr>
                                        <th class="th">Típus</th>
                                        <th class="th">Szállító</th>
                                        <th class="th">Bizonylatszám</th>
                                        <th class="th">Kelt</th>
                                        <th class="th text-right">Bruttó</th>
                                        <th class="th"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                @foreach ($tetelek as $t)
                                    <tr class="trow" wire:key="arch-tetel-{{ $t->id }}">
                                        <td class="td">{{ \App\Enums\DokumentumTipus::cimkeje($t->doc_type?->value) }}</td>
                                        <td class="td">{{ $t->supplier_name ?? '—' }}</td>
                                        <td class="td">{{ $t->doc_number ?? '—' }}</td>
                                        <td class="td whitespace-nowrap">{{ $t->issue_date?->format('Y. m. d.') ?? '—' }}</td>
                                        <td class="td text-right whitespace-nowrap">
                                            {{ \App\Support\Osszeg::formaz($t->gross_amount, $t->currency) }}
                                        </td>
                                        <td class="td text-right whitespace-nowrap">
                                            @if ($this->szerkeszthet())
                                                <button wire:click="visszahiv({{ $t->id }})" class="btn btn-ghost btn-sm">Visszahívás</button>
                                            @endif
                                            @if ($this->adminisztralhat())
                                                <button wire:click="tetelTorles({{ $t->id }})"
                                                        wire:confirm="A tétel véglegesen törlődik. Biztos?"
                                                        class="btn btn-ghost btn-sm text-red-700">Törlés</button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                            @if ($tetelek->isNotEmpty() && $tetelek->every(fn ($t) => ! $t->vanFajlja()))
                                <p class="px-5 py-3 text-xs text-slate-400">
                                    Ezekhez a tételekhez már nincs eredeti fájl — az export után törlődtek.
                                    A visszahívott tétel adatai szerkeszthetők, de a bizonylat képe nem hívható vissza.
                                </p>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-4">{{ $exportok->links() }}</div>
    @endif
</div>
