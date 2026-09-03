<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ÁFA-kategória az EN 16931 szerint (UNCL5305 kódkészlet).
 *
 * Miért kell a kulcs mellé: egy nulla százalékos sor önmagában értelmezhetetlen
 * — nem derül ki, *miért* nulla. A fordított adózás, a tárgyi mentesség és a
 * közösségi értékesítés mind nulla ÁFÁ-t mutat a papíron, a könyvelésben
 * viszont három különböző dolog. A kategóriakód ezt dönti el.
 *
 * A lista az EN 16931 kódkészlet gyakorlatilag releváns része; a Kanári-
 * szigetekre és Ceutára vonatkozó kódok szándékosan hiányoznak.
 */
enum AfaKategoria: string
{
    case Normal = 'S';
    case ForditottAdozas = 'AE';
    case NullaKulcsos = 'Z';
    case Mentes = 'E';
    case Kozossegi = 'K';
    case Export = 'G';
    case HatalyonKivul = 'O';

    public function cimke(): string
    {
        return match ($this) {
            self::Normal => 'Normál',
            self::ForditottAdozas => 'Fordított adózás',
            self::NullaKulcsos => 'Nulla kulcsos',
            self::Mentes => 'Mentes (AAM, TAM)',
            self::Kozossegi => 'Közösségi értékesítés',
            self::Export => 'Export',
            self::HatalyonKivul => 'ÁFA hatályán kívül',
        };
    }

    /**
     * Elvárt-e nulla ÁFA ebben a kategóriában. Ez a validátor kérdése: a
     * normál kategórián kívül minden más nulla adóösszeget jelent.
     */
    public function nullaAfa(): bool
    {
        return $this !== self::Normal;
    }

    /** @return array<string, string> */
    public static function opciok(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->cimke();
        }

        return $out;
    }

    /**
     * Ismeretlen kódot nem sorolunk be találomra — magát a kódot írjuk ki,
     * ahogy a bizonylattípusnál is.
     */
    public static function cimkeje(?string $ertek): string
    {
        if ($ertek === null || $ertek === '') {
            return '—';
        }

        return self::tryFrom($ertek)?->cimke() ?? $ertek;
    }
}
