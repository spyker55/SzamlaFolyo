<?php

declare(strict_types=1);

namespace App\Services\Extraction\Xml;

use App\Enums\DokumentumTipus;
use DOMDocument;
use DOMXPath;

/**
 * OASIS UBL számla — a Peppol-hálózat és az XRechnung formátuma.
 *
 * Önálló XML-ként érkezik (nem PDF-be ágyazva), és ez az az alak, amerre az
 * európai e-számlázás — és vele a ViDA — halad.
 */
final class UblErtelmezo extends Ertelmezo
{
    public function tamogatja(DOMDocument $doc): bool
    {
        $gyoker = $doc->documentElement;

        if ($gyoker === null || ! in_array($gyoker->localName, ['Invoice', 'CreditNote'], true)) {
            return false;
        }

        // A puszta „Invoice" gyökérnév túl gyakori ahhoz, hogy elég legyen:
        // egy tetszőleges házi XML is hívhatja így a gyökerét. A névtér az,
        // ami valóban UBL-nek minősíti.
        return str_contains((string) $gyoker->namespaceURI, 'oasis:names:specification:ubl');
    }

    public function nev(): string
    {
        return 'xml/ubl';
    }

    public function ertelmez(DOMXPath $xpath): array
    {
        $mezok = [
            'doc_type' => $this->bizonylattipus($xpath),
            'supplier_name' => $this->felNeve($xpath, 'AccountingSupplierParty'),
            'supplier_tax_number' => $this->adoszam($xpath, 'AccountingSupplierParty'),
            'customer_name' => $this->felNeve($xpath, 'AccountingCustomerParty'),
            'customer_tax_number' => $this->adoszam($xpath, 'AccountingCustomerParty'),
            'doc_number' => $this->szoveg($xpath, '/*/*[local-name()="ID"]'),
            'issue_date' => $this->datum($this->szoveg($xpath, '/*/*[local-name()="IssueDate"]')),
            'fulfillment_date' => $this->datum(
                $this->szoveg($xpath, $this->ut('Delivery', 'ActualDeliveryDate'))
            ),
            'due_date' => $this->datum($this->szoveg($xpath, '/*/*[local-name()="DueDate"]')),
            'payment_method' => Kodok::fizetesiMod(
                $this->szoveg($xpath, $this->ut('PaymentMeans', 'PaymentMeansCode'))
            ),
            'currency' => $this->szoveg($xpath, '/*/*[local-name()="DocumentCurrencyCode"]'),
            'net_amount' => $this->osszeg($xpath, 'TaxExclusiveAmount'),
            'vat_amount' => $this->afaOsszesen($xpath),
            'gross_amount' => $this->osszeg($xpath, 'TaxInclusiveAmount'),
            'fizetendo' => $this->osszeg($xpath, 'PayableAmount'),
        ];

        $bontas = $this->bontas($xpath);

        return $mezok + [
            'afa_bontas' => $bontas,
            'tobb_irat_gyanu' => false,
            // A strukturált adat nem átírás kérdése: nincs mit félreolvasni.
            'nehezen_olvashato' => false,
            'confidence' => $this->konfidencia($mezok, $bontas !== []),
        ];
    }

    /**
     * A jóváíró számlának (CreditNote) saját gyökereleme van, típuskód nélkül
     * is egyértelmű. Egyébként az UNCL1001 kód dönt.
     */
    private function bizonylattipus(DOMXPath $xpath): ?string
    {
        if ($xpath->document->documentElement?->localName === 'CreditNote') {
            return DokumentumTipus::SztornoSzamla->value;
        }

        return Kodok::bizonylattipus($this->szoveg($xpath, '/*/*[local-name()="InvoiceTypeCode"]'));
    }

    /**
     * A fél neve. A bejegyzett cégnév (`PartyLegalEntity`) a pontosabb — a
     * `PartyName` gyakran rövidített kereskedelmi név.
     */
    private function felNeve(DOMXPath $xpath, string $fel): ?string
    {
        return $this->szoveg($xpath, $this->ut($fel, 'Party', 'PartyLegalEntity', 'RegistrationName'))
            ?? $this->szoveg($xpath, $this->ut($fel, 'Party', 'PartyName', 'Name'));
    }

    /**
     * Az adószám. Az UBL-ben a `PartyTaxScheme/CompanyID` az ÁFA-szám; ha az
     * nincs, a cégjegyzékszám helyett inkább semmit nem adunk vissza, mert az
     * nem adószám.
     */
    private function adoszam(DOMXPath $xpath, string $fel): ?string
    {
        return $this->szoveg($xpath, $this->ut($fel, 'Party', 'PartyTaxScheme', 'CompanyID'));
    }

    private function osszeg(DOMXPath $xpath, string $nev): ?float
    {
        return $this->szam($xpath, $this->ut('LegalMonetaryTotal', $nev));
    }

    /**
     * A fejléc ÁFA-összesenje. Csak a közvetlenül a `TaxTotal` alatt álló
     * `TaxAmount` kell — az al-összegeknek (`TaxSubtotal`) is van ilyen nevű
     * gyerekük, és azok soronkénti értékek.
     */
    private function afaOsszesen(DOMXPath $xpath): ?float
    {
        return $this->szam($xpath, '/*/*[local-name()="TaxTotal"]/*[local-name()="TaxAmount"]');
    }

    /**
     * ÁFA-bontás a `TaxSubtotal` elemekből. Az UBL a kategóriakódot és a
     * kulcsot is a `TaxCategory` alatt tartja.
     *
     * @return array<int, array<string, mixed>>
     */
    private function bontas(DOMXPath $xpath): array
    {
        $elemek = $xpath->query($this->ut('TaxTotal', 'TaxSubtotal'));

        if ($elemek === false) {
            return [];
        }

        $sorok = [];

        foreach ($elemek as $elem) {
            $sorok[] = [
                'kulcs' => $this->szam($xpath, $this->ut('TaxCategory', 'Percent'), $elem),
                'kategoria' => $this->szoveg($xpath, $this->ut('TaxCategory', 'ID'), $elem),
                'netto' => $this->szam($xpath, $this->ut('TaxableAmount'), $elem),
                'afa' => $this->szam($xpath, $this->ut('TaxAmount'), $elem),
            ];
        }

        return $sorok;
    }
}
