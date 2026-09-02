<!DOCTYPE html>
<html lang="hu" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'SzámlaFolyó' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full items-center justify-center px-4 py-12">
<div class="w-full max-w-md">
    <div class="mb-6 flex items-center justify-center gap-2">
        <x-logo class="h-8 w-8"/>
        <span class="text-xl font-semibold text-slate-900">SzámlaFolyó</span>
    </div>

    <div class="card card-pad">
        @if (session('siker'))
            <div class="alert alert-siker mb-4">{{ session('siker') }}</div>
        @endif
        {{ $slot }}
    </div>

    <p class="mt-6 text-center text-xs text-slate-400">
        A bizonylatokat AI olvassa ki, az adatok magyar szervereken tárolódnak.
    </p>
</div>

</body>
</html>
