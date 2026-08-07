<?php

namespace Tests\Writers;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use function count;
use function implode;
use function in_array;
use function preg_match;
use function sprintf;

/**
 * Conformance assertions for Flux 10 e-reporting XML.
 *
 * The XSD types every date as `xs:string` and every amount as `xs:decimal`, so schema
 * validation happily accepts documents the PPF rejects on sight. These assertions cover
 * exactly that gap: the constraints from Annexe 6 (format sémantique) and Annexe 7
 * (règles de gestion) that no XSD can express.
 *
 * Violations are collected rather than thrown one at a time, so a failure reads as a
 * checklist of spec references instead of stopping at the first problem.
 */
trait Flux10SemanticAssertions
{
    /** ISO 6523 (ICD) scheme identifiers allowed for Seller/Buyer — G2.19 */
    private const ICD_SCHEME_IDS = ['0002', '0223', '0227', '0228', '0229'];

    /** Profile identifier for the e-reporting flow — S1.12 */
    private const EREPORTING_PROFILE = 'urn.cpro.gouv.fr:1p0:ereporting';

    /**
     * Assert a Flux 10 document satisfies the semantic rules the XSD cannot carry.
     */
    private function assertFlux10Semantics(string $xml): void
    {
        $violations = $this->findFlux10Violations($xml);

        if (count($violations) > 0) {
            $this->fail(sprintf(
                "%d Flux 10 semantic violation(s):\n  - %s",
                count($violations),
                implode("\n  - ", $violations)
            ));
        }

        $this->assertSame([], $violations);
    }

    /**
     * @return string[] Human-readable violations, each naming its TT/TG and G rule
     */
    private function findFlux10Violations(string $xml): array
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);

        return [
            ...$this->checkDateFormats($xpath),
            ...$this->checkTimestampFormat($xpath),
            ...$this->checkSender($xpath),
            ...$this->checkIssuer($xpath),
            ...$this->checkPartyScheme($xpath),
            ...$this->checkVatCurrency($xpath),
            ...$this->checkBlockExclusivity($xpath),
            ...$this->checkTransmissionType($xpath),
            ...$this->checkReportPeriods($xpath),
            ...$this->checkProfile($xpath),
        ];
    }

    /**
     * Every date is `AAAAMMJJ` in Flux 10 — G1.09 — with a year in [2000, 2099] — G1.36.
     *
     * Selects every element whose name ends in "Date" (Date, IssueDate, StartDate,
     * EndDate, DueDate), which also picks up the fields added later without changes
     * here. `IssueDateTime` ends in "Time" and is checked separately.
     */
    private function checkDateFormats(DOMXPath $xpath): array
    {
        $violations = [];

        foreach ($xpath->query('//*[substring(local-name(), string-length(local-name()) - 3) = "Date"]') as $node) {
            $value = $node->textContent;
            $path = $this->nodePath($node);

            if (preg_match('/^\d{8}$/', $value) !== 1) {
                $violations[] = sprintf('%s = "%s" — expected AAAAMMJJ (G1.09)', $path, $value);
                continue;
            }

            $year = (int) substr($value, 0, 4);
            if ($year < 2000 || $year > 2099) {
                $violations[] = sprintf('%s = "%s" — year outside [2000, 2099] (G1.36)', $path, $value);
            }
        }

        return $violations;
    }

    /**
     * Transmission timestamp is `AAAAMMJJHHMMSS` on 14 characters — TT-3, G7.53.
     */
    private function checkTimestampFormat(DOMXPath $xpath): array
    {
        $violations = [];

        foreach ($xpath->query('//DateTimeString') as $node) {
            if (preg_match('/^\d{14}$/', $node->textContent) !== 1) {
                $violations[] = sprintf(
                    '%s = "%s" — expected AAAAMMJJHHMMSS (TT-3, G7.53)',
                    $this->nodePath($node),
                    $node->textContent
                );
            }
        }

        return $violations;
    }

    /**
     * Only an accredited platform (PA) may emit a transmission: 4-character matricule,
     * scheme 0238 — TT-8/TT-7, G6.22 — and role code WK — TT-10, G7.51.
     */
    private function checkSender(DOMXPath $xpath): array
    {
        $violations = [];

        $id = $xpath->query('//ReportDocument/Sender/Id')->item(0);
        if (!$id instanceof DOMElement) {
            return ['ReportDocument/Sender/Id is missing (TT-8)'];
        }

        $scheme = $id->getAttribute('schemeId');
        if ($scheme !== '0238') {
            $violations[] = sprintf('ReportDocument/Sender/Id/@schemeId = "%s" — expected "0238" (TT-7, G6.22)', $scheme);
        }

        if (preg_match('/^.{4}$/u', $id->textContent) !== 1) {
            $violations[] = sprintf(
                'ReportDocument/Sender/Id = "%s" — expected a 4-character platform matricule (TT-8, G6.22)',
                $id->textContent
            );
        }

        $role = $xpath->query('//ReportDocument/Sender/RoleCode')->item(0);
        if (!$role instanceof DOMNode || $role->textContent !== 'WK') {
            $violations[] = sprintf(
                'ReportDocument/Sender/RoleCode = "%s" — expected "WK" (TT-10, G7.51)',
                $role instanceof DOMNode ? $role->textContent : ''
            );
        }

        return $violations;
    }

    /**
     * The declarant is identified by its 9-digit SIREN with scheme 0002 — TT-13/TT-12,
     * G6.26 — and declares itself buyer or seller — TT-15, G7.52.
     */
    private function checkIssuer(DOMXPath $xpath): array
    {
        $violations = [];

        $id = $xpath->query('//ReportDocument/Issuer/Id')->item(0);
        if (!$id instanceof DOMElement) {
            return ['ReportDocument/Issuer/Id is missing (TT-13)'];
        }

        $scheme = $id->getAttribute('schemeId');
        if ($scheme !== '0002') {
            $violations[] = sprintf('ReportDocument/Issuer/Id/@schemeId = "%s" — expected "0002" (TT-12, G6.26)', $scheme);
        }

        if (preg_match('/^\d{9}$/', $id->textContent) !== 1) {
            $violations[] = sprintf(
                'ReportDocument/Issuer/Id = "%s" — expected a 9-digit SIREN (TT-13, G6.26)',
                $id->textContent
            );
        }

        $role = $xpath->query('//ReportDocument/Issuer/RoleCode')->item(0);
        $roleValue = $role instanceof DOMNode ? $role->textContent : '';
        if (!in_array($roleValue, ['SE', 'BY'], true)) {
            $violations[] = sprintf('ReportDocument/Issuer/RoleCode = "%s" — expected "SE" or "BY" (TT-15, G7.52)', $roleValue);
        }

        return $violations;
    }

    /**
     * Seller and Buyer identifiers carry an ISO 6523 (ICD) scheme — TT-33-1/TT-37, G2.19 —
     * and their VAT identifiers are qualified "VAT" — TT-34-0/TT-38-0, G2.33.
     */
    private function checkPartyScheme(DOMXPath $xpath): array
    {
        $violations = [];

        foreach ($xpath->query('//CompanyId') as $node) {
            $scheme = $node->getAttribute('schemeId');
            if (!in_array($scheme, self::ICD_SCHEME_IDS, true)) {
                $violations[] = sprintf(
                    '%s/@schemeId = "%s" — expected one of %s (G2.19)',
                    $this->nodePath($node),
                    $scheme,
                    implode(', ', self::ICD_SCHEME_IDS)
                );
            }
        }

        foreach ($xpath->query('//Seller/TaxRegistrationId | //Buyer/TaxRegistrationId') as $node) {
            $qualifier = $node->getAttribute('qualifyingId');
            if ($qualifier !== 'VAT') {
                $violations[] = sprintf(
                    '%s/@qualifyingId = "%s" — expected "VAT" (G2.33)',
                    $this->nodePath($node),
                    $qualifier
                );
            }
        }

        return $violations;
    }

    /**
     * The total VAT amount is expressed in euros — TT-202, G6.23.
     */
    private function checkVatCurrency(DOMXPath $xpath): array
    {
        $violations = [];

        foreach ($xpath->query('//MonetaryTotal/TaxAmount') as $node) {
            $currency = $node->getAttribute('CurrencyCode');
            if ($currency !== 'EUR') {
                $violations[] = sprintf(
                    '%s/@CurrencyCode = "%s" — VAT total must be in EUR (TT-202, G6.23)',
                    $this->nodePath($node),
                    $currency
                );
            }
        }

        return $violations;
    }

    /**
     * A transmission carries aggregated transactions or aggregated payments, never both,
     * and never neither — G6.29.
     */
    private function checkBlockExclusivity(DOMXPath $xpath): array
    {
        $transactions = $xpath->query('/Report/TransactionsReport')->length;
        $payments = $xpath->query('/Report/PaymentsReport')->length;

        if ($transactions > 0 && $payments > 0) {
            return ['Report carries both TransactionsReport and PaymentsReport — they must be transmitted separately (G6.29)'];
        }

        if ($transactions === 0 && $payments === 0) {
            return ['Report carries neither TransactionsReport nor PaymentsReport (G6.29)'];
        }

        return [];
    }

    /**
     * Transmission type is initial or rectificative — TT-4, G8.01.
     */
    private function checkTransmissionType(DOMXPath $xpath): array
    {
        $node = $xpath->query('//ReportDocument/TypeCode')->item(0);
        $value = $node instanceof DOMNode ? $node->textContent : '';

        if (!in_array($value, ['IN', 'RE'], true)) {
            return [sprintf('ReportDocument/TypeCode = "%s" — expected "IN" or "RE" (TT-4, G8.01)', $value)];
        }

        return [];
    }

    /**
     * A transmission period ends strictly after it starts — G6.25.
     */
    private function checkReportPeriods(DOMXPath $xpath): array
    {
        $violations = [];

        foreach ($xpath->query('//ReportPeriod') as $period) {
            $start = $xpath->query('StartDate', $period)->item(0);
            $end = $xpath->query('EndDate', $period)->item(0);
            if (!$start instanceof DOMNode || !$end instanceof DOMNode) {
                continue;
            }

            if ($end->textContent <= $start->textContent) {
                $violations[] = sprintf(
                    '%s: EndDate "%s" is not after StartDate "%s" (G6.25)',
                    $this->nodePath($period),
                    $end->textContent,
                    $start->textContent
                );
            }
        }

        return $violations;
    }

    /**
     * The profile identifier is the e-reporting one, not the source invoice's — TT-29, S1.12.
     */
    private function checkProfile(DOMXPath $xpath): array
    {
        $violations = [];

        foreach ($xpath->query('//BusinessProcess/TypeID') as $node) {
            if ($node->textContent !== self::EREPORTING_PROFILE) {
                $violations[] = sprintf(
                    '%s = "%s" — expected "%s" (TT-29, S1.12)',
                    $this->nodePath($node),
                    $node->textContent,
                    self::EREPORTING_PROFILE
                );
            }
        }

        return $violations;
    }

    /**
     * Build a readable element path for error messages.
     */
    private function nodePath(DOMNode $node): string
    {
        $segments = [];
        for ($current = $node; $current instanceof DOMElement; $current = $current->parentNode) {
            $segments[] = $current->nodeName;
        }

        return implode('/', array_reverse($segments));
    }
}
