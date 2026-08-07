<?php

namespace Einvoicing\Flux10\Traits;

use DateTimeInterface;
use Einvoicing\Exceptions\ValidationException;
use Einvoicing\Flux10\Enums\InvoiceTypeCode;
use Einvoicing\Flux10\Enums\VatRate;
use Einvoicing\Flux10\Invoice;
use Einvoicing\Flux10\Report;
use Einvoicing\Flux10\TaxBreakdown;
use Einvoicing\Flux10\Transaction;
use function abs;
use function array_filter;
use function preg_match;
use function sprintf;
use function strlen;

/**
 * Business rules from Annexe 7, applied to a Flux 10 report before serialization.
 *
 * Only rules that can be decided from the report itself are implemented. Those the
 * annexe marks as "ne peut pas être contrôlée d'un point de vue applicatif" (G1.18,
 * G1.38, G1.44, G1.65, G1.66, G1.67, G6.11, G6.14, G6.15, G6.30, G1.52, G1.57, G6.06)
 * depend on the underlying business reality and are deliberately absent rather than
 * approximated.
 *
 * Rules already made unrepresentable by the enums — G8.01 (transmission type), G1.02
 * (invoicing framework), G1.68 (transaction category), G2.31 (VAT category) — are not
 * re-checked here: an invalid value cannot reach this point.
 */
trait ReportValidationTrait
{
    /**
     * Validate the report against the applicable business rules.
     *
     * @throws ValidationException on the first rule that fails, carrying its rule ID
     */
    public function validate(): void
    {
        foreach ($this->getRules() as $ruleId => $rule) {
            $errorMessage = $rule($this);
            if (!empty($errorMessage)) {
                throw new ValidationException($errorMessage, (string) $ruleId);
            }
        }
    }

    /**
     * @return array<string,callable(Report):?string>
     */
    private function getRules(): array
    {
        $rules = [];

        $rules['G6.29'] = static function (Report $report): ?string {
            $hasTransactions = !empty($report->getInvoices()) || !empty($report->getTransactions());
            $hasPayments = !empty($report->getInvoicePayments()) || !empty($report->getTransactionPayments());
            if ($hasTransactions && $hasPayments) {
                return 'A transmission carries either aggregated transactions or aggregated payments, never both';
            }
            return null;
        };

        $rules['G1.104'] = static function (Report $report): ?string {
            $id = $report->getReportId();
            if ($id === null || $id === '') {
                return 'The transmission identifier (TT-1) is required';
            }
            return self::checkIdentifierCharset($id, 50, 'The transmission identifier (TT-1)');
        };

        $rules['G6.22'] = static function (Report $report): ?string {
            $sender = $report->getSender();
            if ($sender === null) {
                return 'The emitting accredited platform (TG-3) is required';
            }
            $matricule = $sender->getMatricule();
            if ($matricule === null || strlen($matricule) !== 4) {
                return sprintf(
                    'The platform matricule (TT-8) must be 4 characters, got "%s"',
                    $matricule ?? ''
                );
            }
            return null;
        };

        $rules['G6.26'] = static function (Report $report): ?string {
            $issuer = $report->getIssuer();
            if ($issuer === null) {
                return 'The declarant (TG-5) is required';
            }
            if (preg_match('/^\d{9}$/', (string) $issuer->getSiren()) !== 1) {
                return sprintf(
                    'The declarant identifier (TT-13) must be a 9-digit SIREN, got "%s"',
                    $issuer->getSiren() ?? ''
                );
            }
            return null;
        };

        $rules['G7.52'] = static function (Report $report): ?string {
            if ($report->getIssuer()?->getRoleCode() === null) {
                return 'The declarant must declare its role (TT-15): seller or buyer';
            }
            return null;
        };

        $rules['G6.25'] = static function (Report $report): ?string {
            $period = $report->getPeriod();
            if ($period === null) {
                return 'The transmission period (TG-7/TG-33) is required';
            }

            $start = self::normalizeDate($period->getStartDate());
            $end = self::normalizeDate($period->getEndDate());
            if ($start === null || $end === null) {
                return 'The transmission period must define both a start and an end date';
            }
            if ($end <= $start) {
                return sprintf('The period end (%s) must be after its start (%s)', $end, $start);
            }
            return null;
        };

        $rules['G1.36'] = static function (Report $report): ?string {
            foreach (self::collectDates($report) as $label => $date) {
                $year = (int) substr($date, 0, 4);
                if ($year < 2000 || $year > 2099) {
                    return sprintf('%s (%s) falls outside the years 2000-2099', $label, $date);
                }
            }
            return null;
        };

        $rules['G1.05'] = static function (Report $report): ?string {
            foreach (self::invoicesOf($report) as $invoice) {
                $error = self::checkIdentifierCharset(
                    (string) $invoice->getInvoiceId(),
                    35,
                    sprintf('The invoice identifier (TT-19) "%s"', $invoice->getInvoiceId() ?? '')
                );
                if ($error !== null) {
                    return $error;
                }
            }
            foreach ($report->getInvoicePayments() as $payment) {
                $error = self::checkIdentifierCharset(
                    (string) $payment->getInvoiceId(),
                    35,
                    sprintf('The invoice identifier (TT-91) "%s"', $payment->getInvoiceId() ?? '')
                );
                if ($error !== null) {
                    return $error;
                }
            }
            return null;
        };

        $rules['G1.01'] = static function (Report $report): ?string {
            foreach (self::invoicesOf($report) as $invoice) {
                if (!InvoiceTypeCode::isAllowed($invoice->getTypeCode())) {
                    return sprintf(
                        'Invoice "%s" has type %s, which is not allowed in Flux 10',
                        $invoice->getInvoiceId() ?? '',
                        $invoice->getTypeCode() ?? ''
                    );
                }
            }
            return null;
        };

        $rules['G1.60'] = static function (Report $report): ?string {
            foreach (self::invoicesOf($report) as $invoice) {
                $framework = $invoice->getBusinessProcessId();
                if ($framework === null || !$framework->isFinalAfterDeposit()) {
                    continue;
                }
                if (InvoiceTypeCode::isDepositRelated($invoice->getTypeCode())) {
                    return sprintf(
                        'Invoice "%s" declares framework %s (final after deposit) with deposit type %s',
                        $invoice->getInvoiceId() ?? '',
                        $framework->value,
                        $invoice->getTypeCode() ?? ''
                    );
                }
            }
            return null;
        };

        $rules['G6.28'] = static function (Report $report): ?string {
            foreach (self::invoicesOf($report) as $invoice) {
                if ($invoice->getBuyerId() === null || $invoice->getBuyerId() === '') {
                    return sprintf(
                        'Invoice "%s" has no buyer identifier (TT-36); B2C invoice reporting is not allowed',
                        $invoice->getInvoiceId() ?? ''
                    );
                }
            }
            return null;
        };

        $rules['G2.19'] = static function (Report $report): ?string {
            foreach (self::invoicesOf($report) as $invoice) {
                foreach ([
                    ['seller', $invoice->getSellerSchemeId(), $invoice->getSellerId()],
                    ['buyer', $invoice->getBuyerSchemeId(), $invoice->getBuyerId()],
                ] as [$role, $scheme, $identifier]) {
                    if ($scheme === null || $identifier === null || $identifier === '') {
                        continue;
                    }
                    if (!$scheme->accepts($identifier)) {
                        [$min, $max] = $scheme->expectedLength();
                        return sprintf(
                            'Invoice "%s": the %s identifier "%s" does not match scheme %s (%s), expecting %d to %d characters',
                            $invoice->getInvoiceId() ?? '',
                            $role,
                            $identifier,
                            $scheme->value,
                            $scheme->label(),
                            $min,
                            $max
                        );
                    }
                }
            }
            return null;
        };

        $rules['G2.33'] = static function (Report $report): ?string {
            foreach (self::invoicesOf($report) as $invoice) {
                foreach ([
                    ['seller', $invoice->getSellerSchemeId(), $invoice->getSellerVatId()],
                    ['buyer', $invoice->getBuyerSchemeId(), $invoice->getBuyerVatId()],
                ] as [$role, $scheme, $vatId]) {
                    if ($scheme === null || !$scheme->requiresVatIdentifier()) {
                        continue;
                    }
                    if ($vatId === null || $vatId === '') {
                        return sprintf(
                            'Invoice "%s": scheme %s requires the %s VAT identifier to be supplied',
                            $invoice->getInvoiceId() ?? '',
                            $scheme->value,
                            $role
                        );
                    }
                }
            }
            return null;
        };

        $rules['G2.01'] = static function (Report $report): ?string {
            foreach (self::invoicesOf($report) as $invoice) {
                foreach (['seller' => $invoice->getSellerCountry(), 'buyer' => $invoice->getBuyerCountry()] as $role => $country) {
                    if ($country !== null && $country !== '' && preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                        return sprintf(
                            'Invoice "%s": the %s country code "%s" is not an ISO 3166 alpha-2 code',
                            $invoice->getInvoiceId() ?? '',
                            $role,
                            $country
                        );
                    }
                }
            }
            return null;
        };

        $rules['G1.24'] = static function (Report $report): ?string {
            foreach (self::collectRates($report) as $label => $rate) {
                if (!VatRate::isAllowed($rate)) {
                    return sprintf(
                        '%s carries the rate %s, which is not in the accepted list (%s)',
                        $label,
                        (string) $rate,
                        VatRate::allowedAsString()
                    );
                }
            }
            return null;
        };

        $rules['G1.40'] = static function (Report $report): ?string {
            foreach (self::invoicesOf($report) as $invoice) {
                foreach ($invoice->getTaxBreakdown() as $item) {
                    if (!$item instanceof TaxBreakdown || !$item->getCategoryCode()?->requiresExemptionReason()) {
                        continue;
                    }
                    if (empty($item->getExemptionReason()) || empty($item->getExemptionReasonCode())) {
                        return sprintf(
                            'Invoice "%s": a VAT breakdown with category E requires both an exemption reason ' .
                            '(TT-58) and its code (TT-59)',
                            $invoice->getInvoiceId() ?? ''
                        );
                    }
                }
            }
            return null;
        };

        $rules['G1.102'] = static function (Report $report): ?string {
            foreach (self::invoicesOf($report) as $invoice) {
                foreach ($invoice->getTaxBreakdown() as $item) {
                    if (!$item instanceof TaxBreakdown || !$item->getCategoryCode()?->requiresExemptionReason()) {
                        continue;
                    }
                    if (empty($invoice->getSellerVatId())) {
                        return sprintf(
                            'Invoice "%s": an exempt VAT breakdown requires the seller VAT identifier (TT-34)',
                            $invoice->getInvoiceId() ?? ''
                        );
                    }
                }
            }
            return null;
        };

        $rules['G1.53'] = static function (Report $report): ?string {
            foreach (self::invoicesOf($report) as $invoice) {
                $error = self::checkTotalsCoherence(
                    sprintf('Invoice "%s"', $invoice->getInvoiceId() ?? ''),
                    $invoice->getCurrencyCode(),
                    $invoice->getTaxExclusiveAmount(),
                    $invoice->getTaxAmount(),
                    $invoice->getTaxBreakdown()
                );
                if ($error !== null) {
                    return $error;
                }
            }

            foreach (self::transactionsOf($report) as $transaction) {
                $error = self::checkTotalsCoherence(
                    'Transactions entry',
                    $transaction->getCurrencyCode(),
                    $transaction->getTaxExclusiveAmount(),
                    $transaction->getTaxAmount(),
                    $transaction->getTaxBreakdown()
                );
                if ($error !== null) {
                    return $error;
                }
            }

            return null;
        };

        return $rules;
    }

    /**
     * Totals must match the sum of their breakdown, within the one-cent tolerance G1.53
     * allows for rounding.
     *
     * Only applicable when the document is in euros: otherwise the totals and the
     * breakdown may legitimately be expressed on different bases.
     *
     * @param TaxBreakdown[] $breakdown
     */
    private static function checkTotalsCoherence(
        string $label,
        ?string $currencyCode,
        float|string|null $taxExclusiveAmount,
        float|string|null $taxAmount,
        array $breakdown
    ): ?string {
        if ($currencyCode !== 'EUR' || empty($breakdown)) {
            return null;
        }

        $taxableSum = 0.0;
        $taxSum = 0.0;
        foreach ($breakdown as $item) {
            if (!$item instanceof TaxBreakdown) {
                continue;
            }
            $taxableSum += (float) $item->getTaxableAmount();
            $taxSum += (float) $item->getTaxAmount();
        }

        if ($taxExclusiveAmount !== null && abs((float) $taxExclusiveAmount - $taxableSum) > 0.01) {
            return sprintf(
                '%s: the total excluding VAT (%s) does not match the sum of the taxable bases (%s)',
                $label,
                (string) $taxExclusiveAmount,
                (string) round($taxableSum, 2)
            );
        }

        if ($taxAmount !== null && abs((float) $taxAmount - $taxSum) > 0.01) {
            return sprintf(
                '%s: the total VAT (%s) does not match the sum of the VAT breakdown (%s)',
                $label,
                (string) $taxAmount,
                (string) round($taxSum, 2)
            );
        }

        return null;
    }

    /**
     * Identifier charset shared by TT-1 and TT-19 — G1.05, G1.104.
     */
    private static function checkIdentifierCharset(string $value, int $maxLength, string $label): ?string
    {
        if ($value === '') {
            return sprintf('%s is required', $label);
        }
        if (strlen($value) > $maxLength) {
            return sprintf('%s exceeds %d characters', $label, $maxLength);
        }
        if (preg_match('#^[A-Za-z0-9 +/_-]+$#', $value) !== 1) {
            return sprintf('%s contains characters outside [A-Za-z0-9], space, "-", "+", "_" and "/"', $label);
        }
        if (trim($value) !== $value || preg_match('/  /', $value) === 1) {
            return sprintf('%s must not start, end with or contain consecutive spaces', $label);
        }
        return null;
    }

    /**
     * Entries of the expected type only: a wrongly typed one is reported by the writer,
     * with the index and the type it received.
     *
     * @return Invoice[]
     */
    private static function invoicesOf(Report $report): array
    {
        return array_filter($report->getInvoices(), static fn($i) => $i instanceof Invoice);
    }

    /**
     * @return Transaction[]
     */
    private static function transactionsOf(Report $report): array
    {
        return array_filter($report->getTransactions(), static fn($t) => $t instanceof Transaction);
    }

    /**
     * Every date carried by the report, keyed by a human-readable label.
     *
     * @return array<string,string> `AAAAMMJJ` values
     */
    private static function collectDates(Report $report): array
    {
        $dates = [];

        $period = $report->getPeriod();
        if ($period !== null) {
            $start = self::normalizeDate($period->getStartDate());
            $end = self::normalizeDate($period->getEndDate());
            if ($start !== null) {
                $dates['The period start (TT-17/TT-89)'] = $start;
            }
            if ($end !== null) {
                $dates['The period end (TT-18/TT-90)'] = $end;
            }
        }

        foreach (self::invoicesOf($report) as $invoice) {
            $issueDate = self::normalizeDate($invoice->getIssueDate());
            if ($issueDate !== null) {
                $dates[sprintf('The issue date of invoice "%s" (TT-20)', $invoice->getInvoiceId() ?? '')] = $issueDate;
            }
        }

        foreach (self::transactionsOf($report) as $transaction) {
            $date = self::normalizeDate($transaction->getDate());
            if ($date !== null) {
                $dates['The transactions date (TT-77)'] = $date;
            }
        }

        return $dates;
    }

    /**
     * Every VAT rate carried by the report, keyed by a human-readable label.
     *
     * @return array<string,float|string|null>
     */
    private static function collectRates(Report $report): array
    {
        $rates = [];

        foreach (self::invoicesOf($report) as $invoice) {
            foreach ($invoice->getTaxBreakdown() as $index => $item) {
                if ($item instanceof TaxBreakdown) {
                    $rates[sprintf('Invoice "%s" VAT breakdown #%d (TT-57)', $invoice->getInvoiceId() ?? '', $index)] = $item->getRate();
                }
            }
        }

        foreach (self::transactionsOf($report) as $transaction) {
            foreach ($transaction->getTaxBreakdown() as $index => $item) {
                if ($item instanceof TaxBreakdown) {
                    $rates[sprintf('Transactions VAT breakdown #%d (TT-86)', $index)] = $item->getRate();
                }
            }
        }

        foreach ($report->getInvoicePayments() as $payment) {
            foreach ($payment->getAmountsByRate() as $index => $item) {
                $rates[sprintf('Payment for invoice "%s", subtotal #%d (TT-93)', $payment->getInvoiceId() ?? '', $index)] = $item->getRate();
            }
        }

        foreach ($report->getTransactionPayments() as $paymentIndex => $payment) {
            foreach ($payment->getAmountsByRate() as $index => $item) {
                $rates[sprintf('Transaction payment #%d, subtotal #%d (TT-97)', $paymentIndex, $index)] = $item->getRate();
            }
        }

        return $rates;
    }

    /**
     * Reduce a date to its `AAAAMMJJ` form so values can be compared as strings.
     */
    private static function normalizeDate(mixed $date): ?string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('Ymd');
        }

        if (is_string($date) && $date !== '') {
            if (preg_match('/^\d{8}$/', $date) === 1) {
                return $date;
            }
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) === 1) {
                return $matches[1] . $matches[2] . $matches[3];
            }
        }

        return null;
    }
}
