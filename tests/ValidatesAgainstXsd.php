<?php
namespace Tests;

use UXML\UXML;

/**
 * Helpers to check exported documents against the XSD schemas vendored under
 * tests/fixtures/xsd, plus a DOM child-order assertion for the syntaxes whose
 * schema is not available as a complete EN 16931 profile.
 *
 * Available schemas:
 *  - FACTUR-X_EN16931/       Complete Factur-X EN 16931 CII profile. This is the
 *                            reference target for CiiWriter output.
 *  - F1_{BASE,FULL}_{UBL_2.1,CII_D22B}/
 *                            PPF "Spécifications externes FE v3.2" F1 profiles.
 *                            These are heavily reduced subsets: BASE carries no
 *                            invoice lines at all (BG-25 is commented out, see
 *                            Changelog_XSD.md) and both UBL profiles drop
 *                            EN 16931 mandatory terms (BT-27 RegistrationName,
 *                            BT-106 LineExtensionAmount, BT-112, BT-115,
 *                            BT-126 line identifier, ClassifiedTaxCategory...).
 *                            A conformant invoice therefore cannot validate
 *                            against them; they are kept as reference material
 *                            and for the CII structures they do cover.
 *
 * UBL element ordering is asserted with assertChildOrder() against the standard
 * UBL 2.1 sequences below, which were recovered from the F1 schemas (the PPF
 * profiles comment elements out but preserve the OASIS sequence).
 */
trait ValidatesAgainstXsd {
    /** Standard UBL 2.1 InvoiceType sequence */
    protected const UBL_INVOICE_ORDER = [
        'UBLVersionID', 'CustomizationID', 'ProfileID', 'ProfileExecutionID', 'ID',
        'CopyIndicator', 'UUID', 'IssueDate', 'IssueTime', 'DueDate',
        'InvoiceTypeCode', 'Note', 'TaxPointDate', 'DocumentCurrencyCode',
        'TaxCurrencyCode', 'PricingCurrencyCode', 'PaymentCurrencyCode',
        'PaymentAlternativeCurrencyCode', 'AccountingCostCode', 'AccountingCost',
        'LineCountNumeric', 'BuyerReference', 'InvoicePeriod', 'OrderReference',
        'BillingReference', 'DespatchDocumentReference', 'ReceiptDocumentReference',
        'StatementDocumentReference', 'OriginatorDocumentReference',
        'ContractDocumentReference', 'AdditionalDocumentReference', 'ProjectReference',
        'Signature', 'AccountingSupplierParty', 'AccountingCustomerParty', 'PayeeParty',
        'BuyerCustomerParty', 'SellerSupplierParty', 'TaxRepresentativeParty', 'Delivery',
        'DeliveryTerms', 'PaymentMeans', 'PaymentTerms', 'PrepaidPayment', 'AllowanceCharge',
        'TaxExchangeRate', 'PricingExchangeRate', 'PaymentExchangeRate',
        'PaymentAlternativeExchangeRate', 'TaxTotal', 'WithholdingTaxTotal',
        'LegalMonetaryTotal', 'InvoiceLine'
    ];

    /**
     * Standard UBL 2.1 CreditNoteType sequence. Note the differences with an
     * invoice: no DueDate at all, and TaxPointDate comes before the type code.
     */
    protected const UBL_CREDIT_NOTE_ORDER = [
        'UBLVersionID', 'CustomizationID', 'ProfileID', 'ProfileExecutionID', 'ID',
        'CopyIndicator', 'UUID', 'IssueDate', 'IssueTime', 'TaxPointDate',
        'CreditNoteTypeCode', 'Note', 'DocumentCurrencyCode', 'TaxCurrencyCode',
        'PricingCurrencyCode', 'PaymentCurrencyCode', 'PaymentAlternativeCurrencyCode',
        'AccountingCostCode', 'AccountingCost', 'LineCountNumeric', 'BuyerReference',
        'InvoicePeriod', 'DiscrepancyResponse', 'OrderReference', 'BillingReference',
        'DespatchDocumentReference', 'ReceiptDocumentReference', 'ContractDocumentReference',
        'AdditionalDocumentReference', 'StatementDocumentReference',
        'OriginatorDocumentReference', 'Signature', 'AccountingSupplierParty',
        'AccountingCustomerParty', 'PayeeParty', 'BuyerCustomerParty', 'SellerSupplierParty',
        'TaxRepresentativeParty', 'Delivery', 'DeliveryTerms', 'PaymentMeans', 'PaymentTerms',
        'TaxExchangeRate', 'PricingExchangeRate', 'PaymentExchangeRate',
        'PaymentAlternativeExchangeRate', 'AllowanceCharge', 'TaxTotal', 'LegalMonetaryTotal',
        'CreditNoteLine'
    ];

    /** Path of the complete Factur-X EN 16931 CII schema */
    protected const FACTUR_X_EN16931 = 'FACTUR-X_EN16931/FACTUR-X_EN16931.xsd';

    /**
     * Absolute path of a vendored XSD, relative to tests/fixtures/xsd
     */
    protected function xsdPath(string $relativePath): string {
        return __DIR__ . '/fixtures/xsd/' . $relativePath;
    }

    /**
     * Asserts that $xml validates against the XSD at $xsdPath.
     * Skips the test when the fixture is not available.
     */
    protected function assertValidAgainstXsd(string $xml, string $xsdPath): void {
        if (!is_file($xsdPath)) {
            $this->markTestSkipped("XSD fixture not available: $xsdPath");
        }

        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $doc->loadXML($xml);
        $valid = $doc->schemaValidate($xsdPath);
        $errors = array_map(
            fn($e) => trim($e->message) . " (line {$e->line})",
            libxml_get_errors()
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertTrue($valid, "XSD validation failed:\n" . implode("\n", $errors));
    }

    /**
     * Asserts that $xml validates against the Factur-X EN 16931 CII schema
     */
    protected function assertValidFacturX(string $xml): void {
        $this->assertValidAgainstXsd($xml, $this->xsdPath(self::FACTUR_X_EN16931));
    }

    /**
     * Asserts that the direct children of $parentPath appear in the relative order given
     * (children absent from $expectedOrder are ignored; those present must be ordered).
     *
     * @param string[] $expectedOrder Local names, in the order the XSD sequence requires
     */
    protected function assertChildOrder(UXML $root, string $parentPath, array $expectedOrder): void {
        $parent = ($parentPath === '') ? $root : $root->get($parentPath);
        $this->assertNotNull($parent, "Element not found: $parentPath");

        $positions = array_flip($expectedOrder);
        $seen = [];
        foreach ($parent->element()->childNodes as $child) {
            if (!($child instanceof \DOMElement)) {
                continue;
            }
            if (isset($positions[$child->localName])) {
                $seen[] = $child->localName;
            }
        }

        $previousIndex = -1;
        $previousName = null;
        foreach ($seen as $name) {
            $index = $positions[$name];
            $this->assertGreaterThanOrEqual(
                $previousIndex,
                $index,
                "$parentPath: <$name> must not appear after <$previousName>."
                    . " Found order: " . implode(', ', $seen)
                    . " / expected order: " . implode(', ', $expectedOrder)
            );
            $previousIndex = $index;
            $previousName = $name;
        }
    }
}
