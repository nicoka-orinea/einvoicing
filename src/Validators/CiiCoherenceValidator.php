<?php
namespace Einvoicing\Validators;

use UXML\UXML;
use function abs;
use function array_key_exists;
use function count;
use function in_array;
use function round;
use function sprintf;

/**
 * Re-runs, on the written CII document, the arithmetic a French PA checks with no tolerance
 * (line, allowance and document totals) and the VAT rules a wrong category breaks.
 *
 * Every amount is read as printed, so the check sees exactly what the platform will see.
 * Rule ids follow EN16931 where one exists; "PA-LINE" and "PA-ALLOWANCE" are the platform's
 * own recomputations (BT-131 and BT-136/BT-141).
 */
class CiiCoherenceValidator {
    private const TRANSACTION = 'rsm:SupplyChainTradeTransaction';
    private const SETTLEMENT = 'rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement';
    private const SUMMATION = self::SETTLEMENT . '/ram:SpecifiedTradeSettlementHeaderMonetarySummation';

    /** Half a cent: two amounts written with two decimals are equal or differ by ≥ 0.01 */
    private const EXACT = 0.005;

    /** BR-CO-17 grants one cent on the VAT amount of a category */
    private const VAT_TOLERANCE = 0.01 + self::EXACT;

    private const ZERO_RATED_CATEGORIES = ['E', 'Z', 'G', 'K', 'AE'];

    /**
     * @param string               $xml      CII document
     * @param array<string,float>  $expected Optional amounts the document must match, keys
     *                                       `lineTotal`, `taxBasisTotal`, `taxTotal`, `grandTotal`
     * @return CoherenceViolation[]
     */
    public function validate(string $xml, array $expected = []): array {
        $doc = UXML::fromString($xml);
        $violations = [];

        $lineTaxable = $this->validateLines($doc, $violations);
        $this->validateDocumentTotals($doc, $lineTaxable, $violations);
        $this->validateExpectedTotals($doc, $expected, $violations);

        return $violations;
    }

    /**
     * @param CoherenceViolation[] $violations
     * @return array<string,float> taxable amount per "category|rate", from the line nets
     */
    private function validateLines(UXML $doc, array &$violations): array {
        $taxable = [];
        foreach ($doc->getAll(self::TRANSACTION . '/ram:IncludedSupplyChainTradeLineItem') as $line) {
            $id = $line->get('ram:AssociatedDocumentLineDocument/ram:LineID')?->asText() ?? '?';
            $settlement = $line->get('ram:SpecifiedLineTradeSettlement');
            $price = $this->amount($line->get('ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount'));
            $grossPrice = $this->amount($line->get('ram:SpecifiedLineTradeAgreement/ram:GrossPriceProductTradePrice/ram:ChargeAmount'));
            $basisQuantity = $this->amount($line->get('ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity')) ?? 1.0;
            $quantity = $this->amount($line->get('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity'));
            $lineTotal = $this->amount($settlement?->get('ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount'));

            if ($price !== null && $price < 0) {
                $violations[] = new CoherenceViolation('BR-27', sprintf('line %s: net price %s is negative', $id, $price));
            }
            if ($grossPrice !== null && $grossPrice < 0) {
                $violations[] = new CoherenceViolation('BR-28', sprintf('line %s: gross price %s is negative', $id, $grossPrice));
            }

            [$allowances, $charges] = $this->allowancesAndCharges($settlement, sprintf('line %s', $id), $violations);

            if ($price !== null && $quantity !== null && $lineTotal !== null && $basisQuantity > 0) {
                $computed = round($price / $basisQuantity * $quantity - $allowances + $charges, 2);
                if (!$this->same($computed, $lineTotal)) {
                    $violations[] = new CoherenceViolation('PA-LINE', sprintf(
                        'line %s: %s × %s − %s + %s = %s but LineTotalAmount is %s',
                        $id, $price, $quantity, $allowances, $charges, $computed, $lineTotal
                    ));
                }
            }

            $category = $settlement?->get('ram:ApplicableTradeTax/ram:CategoryCode')?->asText();
            $rate = $this->amount($settlement?->get('ram:ApplicableTradeTax/ram:RateApplicablePercent'));
            if ($category !== null) {
                $this->validateCategoryRate($category, $rate, sprintf('line %s', $id), $violations);
                $key = $category . '|' . ($rate === null ? '' : (string) $rate);
                $taxable[$key] = ($taxable[$key] ?? 0.0) + ($lineTotal ?? 0.0);
            }
        }
        return $taxable;
    }

    /**
     * @param array<string,float> $lineTaxable
     * @param CoherenceViolation[] $violations
     */
    private function validateDocumentTotals(UXML $doc, array $lineTaxable, array &$violations): void {
        $summation = $doc->get(self::SUMMATION);
        if ($summation === null) {
            $violations[] = new CoherenceViolation('BR-12', 'missing SpecifiedTradeSettlementHeaderMonetarySummation');
            return;
        }
        $settlement = $doc->get(self::SETTLEMENT);
        $currency = $settlement?->get('ram:InvoiceCurrencyCode')?->asText();

        $lineTotal = $this->amount($summation->get('ram:LineTotalAmount'));
        $allowanceTotal = $this->amount($summation->get('ram:AllowanceTotalAmount')) ?? 0.0;
        $chargeTotal = $this->amount($summation->get('ram:ChargeTotalAmount')) ?? 0.0;
        $taxBasis = $this->amount($summation->get('ram:TaxBasisTotalAmount'));
        $taxTotal = $this->amountInCurrency($summation->getAll('ram:TaxTotalAmount'), $currency);
        $rounding = $this->amount($summation->get('ram:RoundingAmount')) ?? 0.0;
        $grandTotal = $this->amount($summation->get('ram:GrandTotalAmount'));
        $prepaid = $this->amount($summation->get('ram:TotalPrepaidAmount')) ?? 0.0;
        $due = $this->amount($summation->get('ram:DuePayableAmount'));

        $sumOfLines = 0.0;
        foreach ($doc->getAll(self::TRANSACTION . '/ram:IncludedSupplyChainTradeLineItem/ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount') as $node) {
            $sumOfLines += (float) $node->asText();
        }
        $this->assertSame('BR-CO-10', 'sum of line net amounts', $sumOfLines, 'LineTotalAmount', $lineTotal, $violations);

        [$headerAllowances, $headerCharges, $adjustments] = $this->headerAllowancesAndCharges($settlement, $violations);
        $this->assertSame('BR-CO-11', 'sum of document allowances', $headerAllowances, 'AllowanceTotalAmount', $allowanceTotal, $violations);
        $this->assertSame('BR-CO-12', 'sum of document charges', $headerCharges, 'ChargeTotalAmount', $chargeTotal, $violations);

        if ($lineTotal !== null) {
            $this->assertSame('BR-CO-13', 'LineTotal − allowances + charges', round($lineTotal - $allowanceTotal + $chargeTotal, 2), 'TaxBasisTotalAmount', $taxBasis, $violations);
        }

        $taxes = $settlement?->getAll('ram:ApplicableTradeTax') ?? [];
        if (count($taxes) === 0) {
            $violations[] = new CoherenceViolation('BR-CO-18', 'no VAT breakdown (ApplicableTradeTax)');
        }
        $sumOfTaxes = 0.0;
        $sumOfBases = 0.0;
        $seen = [];
        foreach ($taxes as $tax) {
            $category = $tax->get('ram:CategoryCode')?->asText() ?? '?';
            $rate = $this->amount($tax->get('ram:RateApplicablePercent'));
            $basis = $this->amount($tax->get('ram:BasisAmount')) ?? 0.0;
            $calculated = $this->amount($tax->get('ram:CalculatedAmount')) ?? 0.0;
            $label = sprintf('VAT breakdown %s %s%%', $category, $rate ?? '-');
            $key = $category . '|' . ($rate === null ? '' : (string) $rate);

            if (isset($seen[$key])) {
                $violations[] = new CoherenceViolation('BR-S-08', sprintf('%s appears twice', $label));
            }
            $seen[$key] = true;

            $this->validateCategoryRate($category, $rate, $label, $violations);
            if ($category !== 'S' && $category !== 'Z'
                && $tax->get('ram:ExemptionReason') === null && $tax->get('ram:ExemptionReasonCode') === null) {
                $violations[] = new CoherenceViolation('BR-' . $category . '-10', sprintf('%s has neither an exemption reason nor a reason code', $label));
            }

            $expectedVat = round($basis * (($rate ?? 0.0) / 100), 2);
            if (abs($expectedVat - $calculated) > self::VAT_TOLERANCE) {
                $violations[] = new CoherenceViolation('BR-CO-17', sprintf('%s: %s × %s%% = %s but CalculatedAmount is %s', $label, $basis, $rate ?? 0, $expectedVat, $calculated));
            }

            $expectedBasis = round(($lineTaxable[$key] ?? 0.0) + ($adjustments[$key] ?? 0.0), 2);
            if (array_key_exists($key, $lineTaxable) || array_key_exists($key, $adjustments)) {
                $this->assertSame('BR-' . $category . '-08', $label . ' taxable amount from lines and document allowances/charges', $expectedBasis, 'BasisAmount', $basis, $violations);
            } else {
                $violations[] = new CoherenceViolation('BR-' . $category . '-08', sprintf('%s has no line nor document allowance/charge', $label));
            }

            $sumOfTaxes += $calculated;
            $sumOfBases += $basis;
        }
        $this->assertSame('BR-CO-14', 'sum of VAT category amounts', $sumOfTaxes, 'TaxTotalAmount', $taxTotal, $violations);
        if ($taxBasis !== null) {
            $this->assertSame('BR-CO-13', 'sum of VAT category taxable amounts', $sumOfBases, 'TaxBasisTotalAmount', $taxBasis, $violations);
        }

        if ($taxBasis !== null && $taxTotal !== null) {
            $this->assertSame('BR-CO-15', 'TaxBasisTotal + TaxTotal', round($taxBasis + $taxTotal, 2), 'GrandTotalAmount', $grandTotal, $violations);
        }
        if ($grandTotal !== null) {
            $this->assertSame('BR-CO-16', 'GrandTotal − prepaid + rounding', round($grandTotal - $prepaid + $rounding, 2), 'DuePayableAmount', $due, $violations);
        }

        if ($due !== null && $due > 0) {
            $terms = $settlement?->get('ram:SpecifiedTradePaymentTerms');
            if ($terms === null || ($terms->get('ram:DueDateDateTime') === null && $terms->get('ram:Description') === null)) {
                $violations[] = new CoherenceViolation('BR-CO-25', 'amount due is positive but neither a due date nor payment terms are given');
            }
        }
    }

    /**
     * @param array<string,float> $expected
     * @param CoherenceViolation[] $violations
     */
    private function validateExpectedTotals(UXML $doc, array $expected, array &$violations): void {
        if ($expected === []) {
            return;
        }
        $summation = $doc->get(self::SUMMATION);
        $currency = $doc->get(self::SETTLEMENT . '/ram:InvoiceCurrencyCode')?->asText();
        $written = [
            'lineTotal' => $this->amount($summation?->get('ram:LineTotalAmount')),
            'taxBasisTotal' => $this->amount($summation?->get('ram:TaxBasisTotalAmount')),
            'taxTotal' => $summation === null ? null : $this->amountInCurrency($summation->getAll('ram:TaxTotalAmount'), $currency),
            'grandTotal' => $this->amount($summation?->get('ram:GrandTotalAmount')),
        ];
        foreach ($expected as $name => $value) {
            if (!array_key_exists($name, $written)) {
                continue;
            }
            if ($written[$name] === null) {
                $violations[] = new CoherenceViolation('DOCUMENT', sprintf('%s is missing', $name));
                continue;
            }
            // VAT is rounded per rate on the caller's side and per category here: one cent of
            // drift on the VAT and grand totals is not a coherence defect, the net total is exact.
            $tolerance = in_array($name, ['taxTotal', 'grandTotal'], true) ? self::VAT_TOLERANCE : self::EXACT;
            if (abs(round((float) $value, 2) - $written[$name]) > $tolerance) {
                $violations[] = new CoherenceViolation('DOCUMENT', sprintf('expected %s = %s but %s is %s', $name, round((float) $value, 2), $name, $written[$name]));
            }
        }
    }

    /**
     * Line-level allowances and charges: totals and percentage arithmetic.
     * @param CoherenceViolation[] $violations
     * @return array{0: float, 1: float}
     */
    private function allowancesAndCharges(?UXML $parent, string $context, array &$violations): array {
        $allowances = 0.0;
        $charges = 0.0;
        foreach ($parent?->getAll('ram:SpecifiedTradeAllowanceCharge') ?? [] as $item) {
            $isCharge = $item->get('ram:ChargeIndicator/udt:Indicator')?->asText() === 'true';
            $actual = $this->amount($item->get('ram:ActualAmount')) ?? 0.0;
            $this->validatePercentage($item, $actual, $context . ($isCharge ? ' charge' : ' allowance'), $violations);
            if ($isCharge) {
                $charges += $actual;
            } else {
                $allowances += $actual;
            }
        }
        return [round($allowances, 2), round($charges, 2)];
    }

    /**
     * Document-level allowances and charges, plus their effect on each VAT category basis.
     * @param CoherenceViolation[] $violations
     * @return array{0: float, 1: float, 2: array<string,float>}
     */
    private function headerAllowancesAndCharges(?UXML $settlement, array &$violations): array {
        $allowances = 0.0;
        $charges = 0.0;
        $adjustments = [];
        foreach ($settlement?->getAll('ram:SpecifiedTradeAllowanceCharge') ?? [] as $item) {
            $isCharge = $item->get('ram:ChargeIndicator/udt:Indicator')?->asText() === 'true';
            $actual = $this->amount($item->get('ram:ActualAmount')) ?? 0.0;
            $this->validatePercentage($item, $actual, $isCharge ? 'document charge' : 'document allowance', $violations);

            $category = $item->get('ram:CategoryTradeTax/ram:CategoryCode')?->asText();
            $rate = $this->amount($item->get('ram:CategoryTradeTax/ram:RateApplicablePercent'));
            if ($category === null) {
                $violations[] = new CoherenceViolation('BR-32', sprintf('document %s of %s has no VAT category', $isCharge ? 'charge' : 'allowance', $actual));
            } else {
                $key = $category . '|' . ($rate === null ? '' : (string) $rate);
                $adjustments[$key] = ($adjustments[$key] ?? 0.0) + ($isCharge ? $actual : -$actual);
            }

            if ($isCharge) {
                $charges += $actual;
            } else {
                $allowances += $actual;
            }
        }
        return [round($allowances, 2), round($charges, 2), $adjustments];
    }

    /** @param CoherenceViolation[] $violations */
    private function validatePercentage(UXML $item, float $actual, string $context, array &$violations): void {
        $percent = $this->amount($item->get('ram:CalculationPercent'));
        $basis = $this->amount($item->get('ram:BasisAmount'));
        if ($percent === null || $basis === null) {
            return;
        }
        $computed = round($basis * $percent / 100, 2);
        if (!$this->same($computed, $actual)) {
            $violations[] = new CoherenceViolation('PA-ALLOWANCE', sprintf('%s: %s × %s%% = %s but ActualAmount is %s', $context, $basis, $percent, $computed, $actual));
        }
    }

    /** @param CoherenceViolation[] $violations */
    private function validateCategoryRate(string $category, ?float $rate, string $context, array &$violations): void {
        if ($category === 'S' && !($rate > 0)) {
            $violations[] = new CoherenceViolation('BR-S-05', sprintf('%s: category S with rate %s', $context, $rate ?? 'missing'));
        } elseif (in_array($category, self::ZERO_RATED_CATEGORIES, true) && ($rate === null || $rate != 0)) {
            $violations[] = new CoherenceViolation('BR-' . $category . '-05', sprintf('%s: category %s requires a 0 rate, got %s', $context, $category, $rate ?? 'missing'));
        } elseif ($category === 'O' && $rate !== null) {
            $violations[] = new CoherenceViolation('BR-O-05', sprintf('%s: category O must not carry a rate', $context));
        }
    }

    /** @param CoherenceViolation[] $violations */
    private function assertSame(string $rule, string $computedLabel, ?float $computed, string $writtenLabel, ?float $written, array &$violations): void {
        if ($written === null) {
            $violations[] = new CoherenceViolation($rule, sprintf('%s is missing', $writtenLabel));
            return;
        }
        if ($computed !== null && !$this->same($computed, $written)) {
            $violations[] = new CoherenceViolation($rule, sprintf('%s = %s but %s is %s', $computedLabel, round($computed, 2), $writtenLabel, $written));
        }
    }

    private function same(float $a, float $b): bool {
        return abs(round($a, 2) - round($b, 2)) < self::EXACT;
    }

    private function amount(?UXML $node): ?float {
        if ($node === null) {
            return null;
        }
        $text = trim($node->asText());
        return $text === '' ? null : (float) $text;
    }

    /** @param UXML[] $nodes */
    private function amountInCurrency(array $nodes, ?string $currency): ?float {
        foreach ($nodes as $node) {
            $nodeCurrency = $node->element()->getAttribute('currencyID');
            if ($currency === null || $nodeCurrency === '' || $nodeCurrency === $currency) {
                return $this->amount($node);
            }
        }
        return null;
    }
}
