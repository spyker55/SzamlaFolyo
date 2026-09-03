<?php

declare(strict_types=1);

namespace App\Services\Extraction\Xml;

use DOMDocument;
use DOMXPath;

/**
 * UN/CEFACT Cross Industry Invoice — ezt hordozza a Factur-X és a ZUGFeRD.
 *
 * A PDF-be ágyazott e-számlák túlnyomó része ilyen, ezért ez a legfontosabb
 * értelmező: itt spórolható a legtöbb modellhívás.
 */
final class CiiErtelmezo extends Ertelmezo
{
    public function tamogatja(DOMDocument $doc): bool
    {
        // A ZUGFeRD 1.0 „CrossIndustryDocument"-nek hívja ugyanezt.
        return in_array(
            $doc->documentElement?->localName,
            ['CrossIndustryInvoice', 'CrossIndustryDocument'],
            true,
        );
    }

    public function nev(): string
    {
        return 'xml/cii';
    }

    public function ertelmez(DOMXPath $xpath): array
    {
        $mezok = [
            'doc_type' => Kodok::bizonylattipus(
                $this->szoveg($xpath, $this->ut('ExchangedDocument', 'TypeCode'))
            ),
            'supplier_name' => $this->szoveg($xpath, $this->ut('SellerTradeParty', 'Name')),
            'supplier_tax_number' => $this->adoszam($xpath, 'SellerTradeParty'),
            'customer_name' => $this->szoveg($xpath, $this->ut('BuyerTradeParty', 'Name')),
            'customer_tax_number' => $this->adoszam($xpath, 'BuyerTradeParty'),
            'doc_number' => $this->szoveg($xpath, $this->ut('ExchangedDocument', 'ID')),
            'issue_date' => $this->datum(
                $this->szoveg($xpath, $this->ut('ExchangedDocument', 'IssueDateTime', 'DateTimeString'))
            ),
            'fulfillment_date' => $this->datum($this->szoveg($xpath, $this->ut(
                'ActualDeliverySupplyChainEvent', 'OccurrenceDateTime', 'DateTimeString',
            ))),
            'due_date' => $this->datum($this->szoveg($xpath, $this->ut(
                'SpecifiedTradePaymentTerms', 'DueDateDateTime', 'DateTimeString',
            ))),
            'payment_method' => Kodok::fizetesiMod(
                $this->szoveg($xpath, $this->ut('SpecifiedTradeSettlementPaymentMeans', 'TypeCode'))
            ),
            'currency' => $this->szoveg($xpath, $this->ut('InvoiceCurrencyCode')),
            'net_amount' => $this->osszeg($xpath, 'TaxBasisTotalAmount'),
            'vat_amount' => $this->osszeg($xpath, 'TaxTotalAmount'),
            'gross_amount' => $this->osszeg($xpath, 'GrandTotalAmount'),
            'fizetendo' => $this->osszeg($xpath, 'DuePayableAmount'),
        ];

        $bontas = $this->bontas($xpath);

        return $mezok + [
            'afa_bontas' => $bontas,
            'tobb_irat_gyanu' => false,
            'confidence' => $this->konfidencia($mezok, $bontas !== []),
        ];
    }

    /**
     * Az összegek a fejléc-összesítőből. A `SpecifiedTradeSettlementHeader-
     * MonetarySummation` alatt kell keresni, mert ugyanezek a nevek a
     * tételsorok alatt is előfordulnak — ott viszont soronkénti értékek
     * állnak, nem a végösszeg.
     */
    private function osszeg(DOMXPath $xpath, string $nev): ?float
    {
        return $this->szam($xpath, $this->ut('SpecifiedTradeSettlementHeaderMonetarySummation', $nev));
    }

    /**
     * Az adószám. A `SpecifiedTaxRegistration` többször is szerepelhet (ÁFA-
     * szám és belföldi adószám), ezért a `VA` sémájút keressük elsőként — az
     * az ÁFA-alanyiságot igazoló szám.
     */
    private function adoszam(DOMXPath $xpath, string $fel): ?string
    {
        $afaSzam = $this->szoveg($xpath, $this->ut($fel, 'SpecifiedTaxRegistration', 'ID').'[@schemeID="VA"]');

        return $afaSzam ?? $this->szoveg($xpath, $this->ut($fel, 'SpecifiedTaxRegistration', 'ID'));
    }

    /**
     * ÁFA-bontás az `ApplicableTradeTax` elemekből — a CII eleve kulcsonként
     * egy elemet ír elő, tehát itt nincs mit összevonni.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bontas(DOMXPath $xpath): array
    {
        $elemek = $xpath->query($this->ut('ApplicableHeaderTradeSettlement', 'ApplicableTradeTax'));

        if ($elemek === false) {
            return [];
        }

        $sorok = [];

        foreach ($elemek as $elem) {
            $sorok[] = [
                'kulcs' => $this->szam($xpath, $this->ut('RateApplicablePercent'), $elem),
                'kategoria' => $this->szoveg($xpath, $this->ut('CategoryCode'), $elem),
                'netto' => $this->szam($xpath, $this->ut('BasisAmount'), $elem),
                'afa' => $this->szam($xpath, $this->ut('CalculatedAmount'), $elem),
            ];
        }

        return $sorok;
    }
}
