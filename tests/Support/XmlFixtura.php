<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Valósághű e-számla-minták a két formátumhoz.
 *
 * Szándékosan a valódi névterekkel és elemsorrenddel, mert épp az a kérdés,
 * hogy a tényleges fájlokban is megtaláljuk-e az adatot. A minták tartalmaznak
 * olyan zavaró elemeket is (tételsorok saját összegekkel), amikbe egy pontatlan
 * útvonal beleakadna.
 */
final class XmlFixtura
{
    /** Factur-X / ZUGFeRD (UN/CEFACT Cross Industry Invoice). */
    public static function cii(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rsm:CrossIndustryInvoice
            xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"
            xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"
            xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
          <rsm:ExchangedDocument>
            <ram:ID>SZ-2026-0042</ram:ID>
            <ram:TypeCode>380</ram:TypeCode>
            <ram:IssueDateTime>
              <udt:DateTimeString format="102">20260314</udt:DateTimeString>
            </ram:IssueDateTime>
          </rsm:ExchangedDocument>
          <rsm:SupplyChainTradeTransaction>
            <ram:IncludedSupplyChainTradeLineItem>
              <ram:SpecifiedLineTradeSettlement>
                <ram:ApplicableTradeTax>
                  <ram:CalculatedAmount>999.99</ram:CalculatedAmount>
                  <ram:CategoryCode>S</ram:CategoryCode>
                  <ram:RateApplicablePercent>27</ram:RateApplicablePercent>
                </ram:ApplicableTradeTax>
              </ram:SpecifiedLineTradeSettlement>
            </ram:IncludedSupplyChainTradeLineItem>
            <ram:ApplicableHeaderTradeAgreement>
              <ram:SellerTradeParty>
                <ram:Name>Példa Szállító Kft.</ram:Name>
                <ram:SpecifiedTaxRegistration>
                  <ram:ID schemeID="FC">10773381-2-44</ram:ID>
                </ram:SpecifiedTaxRegistration>
                <ram:SpecifiedTaxRegistration>
                  <ram:ID schemeID="VA">HU10773381</ram:ID>
                </ram:SpecifiedTaxRegistration>
              </ram:SellerTradeParty>
              <ram:BuyerTradeParty>
                <ram:Name>Vevő Zrt.</ram:Name>
                <ram:SpecifiedTaxRegistration>
                  <ram:ID schemeID="VA">HU10537914</ram:ID>
                </ram:SpecifiedTaxRegistration>
              </ram:BuyerTradeParty>
            </ram:ApplicableHeaderTradeAgreement>
            <ram:ApplicableHeaderTradeDelivery>
              <ram:ActualDeliverySupplyChainEvent>
                <ram:OccurrenceDateTime>
                  <udt:DateTimeString format="102">20260315</udt:DateTimeString>
                </ram:OccurrenceDateTime>
              </ram:ActualDeliverySupplyChainEvent>
            </ram:ApplicableHeaderTradeDelivery>
            <ram:ApplicableHeaderTradeSettlement>
              <ram:InvoiceCurrencyCode>HUF</ram:InvoiceCurrencyCode>
              <ram:SpecifiedTradeSettlementPaymentMeans>
                <ram:TypeCode>30</ram:TypeCode>
              </ram:SpecifiedTradeSettlementPaymentMeans>
              <ram:ApplicableTradeTax>
                <ram:CalculatedAmount>24300.00</ram:CalculatedAmount>
                <ram:BasisAmount>90000.00</ram:BasisAmount>
                <ram:CategoryCode>S</ram:CategoryCode>
                <ram:RateApplicablePercent>27</ram:RateApplicablePercent>
              </ram:ApplicableTradeTax>
              <ram:ApplicableTradeTax>
                <ram:CalculatedAmount>500.00</ram:CalculatedAmount>
                <ram:BasisAmount>10000.00</ram:BasisAmount>
                <ram:CategoryCode>S</ram:CategoryCode>
                <ram:RateApplicablePercent>5</ram:RateApplicablePercent>
              </ram:ApplicableTradeTax>
              <ram:SpecifiedTradePaymentTerms>
                <ram:DueDateDateTime>
                  <udt:DateTimeString format="102">20260328</udt:DateTimeString>
                </ram:DueDateDateTime>
              </ram:SpecifiedTradePaymentTerms>
              <ram:SpecifiedTradeSettlementHeaderMonetarySummation>
                <ram:LineTotalAmount>100000.00</ram:LineTotalAmount>
                <ram:TaxBasisTotalAmount>100000.00</ram:TaxBasisTotalAmount>
                <ram:TaxTotalAmount currencyID="HUF">24800.00</ram:TaxTotalAmount>
                <ram:GrandTotalAmount>124800.00</ram:GrandTotalAmount>
                <ram:DuePayableAmount>124800.00</ram:DuePayableAmount>
              </ram:SpecifiedTradeSettlementHeaderMonetarySummation>
            </ram:ApplicableHeaderTradeSettlement>
          </rsm:SupplyChainTradeTransaction>
        </rsm:CrossIndustryInvoice>
        XML;
    }

    /** Peppol / XRechnung (OASIS UBL). */
    public static function ubl(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
                 xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
                 xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
          <cbc:ID>SZ-2026-0042</cbc:ID>
          <cbc:IssueDate>2026-03-14</cbc:IssueDate>
          <cbc:DueDate>2026-03-28</cbc:DueDate>
          <cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode>
          <cbc:DocumentCurrencyCode>HUF</cbc:DocumentCurrencyCode>
          <cac:AccountingSupplierParty>
            <cac:Party>
              <cac:PartyName><cbc:Name>Példa Szállító</cbc:Name></cac:PartyName>
              <cac:PartyTaxScheme>
                <cbc:CompanyID>10773381-2-44</cbc:CompanyID>
              </cac:PartyTaxScheme>
              <cac:PartyLegalEntity>
                <cbc:RegistrationName>Példa Szállító Kft.</cbc:RegistrationName>
              </cac:PartyLegalEntity>
            </cac:Party>
          </cac:AccountingSupplierParty>
          <cac:AccountingCustomerParty>
            <cac:Party>
              <cac:PartyTaxScheme>
                <cbc:CompanyID>10537914-4-44</cbc:CompanyID>
              </cac:PartyTaxScheme>
              <cac:PartyLegalEntity>
                <cbc:RegistrationName>Vevő Zrt.</cbc:RegistrationName>
              </cac:PartyLegalEntity>
            </cac:Party>
          </cac:AccountingCustomerParty>
          <cac:Delivery>
            <cbc:ActualDeliveryDate>2026-03-15</cbc:ActualDeliveryDate>
          </cac:Delivery>
          <cac:PaymentMeans>
            <cbc:PaymentMeansCode>30</cbc:PaymentMeansCode>
          </cac:PaymentMeans>
          <cac:TaxTotal>
            <cbc:TaxAmount currencyID="HUF">24800.00</cbc:TaxAmount>
            <cac:TaxSubtotal>
              <cbc:TaxableAmount currencyID="HUF">90000.00</cbc:TaxableAmount>
              <cbc:TaxAmount currencyID="HUF">24300.00</cbc:TaxAmount>
              <cac:TaxCategory>
                <cbc:ID>S</cbc:ID>
                <cbc:Percent>27</cbc:Percent>
              </cac:TaxCategory>
            </cac:TaxSubtotal>
            <cac:TaxSubtotal>
              <cbc:TaxableAmount currencyID="HUF">10000.00</cbc:TaxableAmount>
              <cbc:TaxAmount currencyID="HUF">500.00</cbc:TaxAmount>
              <cac:TaxCategory>
                <cbc:ID>S</cbc:ID>
                <cbc:Percent>5</cbc:Percent>
              </cac:TaxCategory>
            </cac:TaxSubtotal>
          </cac:TaxTotal>
          <cac:LegalMonetaryTotal>
            <cbc:LineExtensionAmount currencyID="HUF">100000.00</cbc:LineExtensionAmount>
            <cbc:TaxExclusiveAmount currencyID="HUF">100000.00</cbc:TaxExclusiveAmount>
            <cbc:TaxInclusiveAmount currencyID="HUF">124800.00</cbc:TaxInclusiveAmount>
            <cbc:PayableAmount currencyID="HUF">124800.00</cbc:PayableAmount>
          </cac:LegalMonetaryTotal>
          <cac:InvoiceLine>
            <cbc:ID>1</cbc:ID>
            <cbc:LineExtensionAmount currencyID="HUF">90000.00</cbc:LineExtensionAmount>
            <cac:TaxTotal>
              <cbc:TaxAmount currencyID="HUF">24300.00</cbc:TaxAmount>
            </cac:TaxTotal>
          </cac:InvoiceLine>
        </Invoice>
        XML;
    }
}
