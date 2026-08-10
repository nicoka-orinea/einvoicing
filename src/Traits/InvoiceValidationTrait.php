<?php
namespace Einvoicing\Traits;

use Einvoicing\Exceptions\ValidationException;
use Einvoicing\Invoice;
use function abs;
use function array_merge;
use function count;
use function in_array;
use function is_array;
use function round;

// @phan-file-suppress PhanPluginInconsistentReturnFunction, PhanPossiblyNonClassMethodCall

trait InvoiceValidationTrait {
    /**
     * Monetary comparison tolerance, in currency units.
     * A method rather than a constant: trait constants need PHP 8.2.
     */
    private static function amountTolerance(): float {
        return 0.005;
    }

    /**
     * EN 16931 VAT category rules, keyed by VAT category code (BT-151/95/102).
     *
     * Each entry is [rule id prefix, rate rule, exemption reason rule,
     * seller VAT identifier required, buyer VAT identifier required,
     * VAT identifiers forbidden].
     *
     * The rate rule is one of "positive" (BR-*-5/6/7 for standard rated),
     * "zero" (BR-*-5/6/7) or "null" (BR-O-5/6/7). The exemption reason rule is
     * "required" (BR-*-10) or "forbidden" (BR-S-10, BR-Z-10).
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool, 4: bool, 5: bool}>
     */
    private static function getVatCategoryRuleTable(): array {
        return [
            'S'  => ['BR-S',  'positive', 'forbidden', true,  false, false],
            'Z'  => ['BR-Z',  'zero',     'forbidden', true,  false, false],
            'E'  => ['BR-E',  'zero',     'required',  true,  false, false],
            'AE' => ['BR-AE', 'zero',     'required',  true,  true,  false],
            'K'  => ['BR-IC', 'zero',     'required',  true,  true,  false],
            'G'  => ['BR-G',  'zero',     'required',  true,  false, false],
            'O'  => ['BR-O',  'null',     'required',  false, false, true],
        ];
    }


    /**
     * Validate invoice
     * @throws ValidationException if failed to pass validation
     */
    public function validate(): void {
        $rules = $this->getRules();
        foreach ($rules as $ruleId=>$rule) {
            $error = $rule($this);
            if (empty($error)) {
                continue;
            }
            // A rule covering a family of EN 16931 identifiers reports the one it
            // actually broke, as [rule id, message]
            if (is_array($error)) {
                throw new ValidationException($error[1], $error[0]);
            }
            throw new ValidationException($error, $ruleId);
        }
    }


    /**
     * Get effective validation rules
     * @return array<string,callable> Map of rules
     * @suppress PhanUndeclaredProperty
     */
    private function getRules(): array {
        $rules = $this->getDefaultRules();
        if ($this->preset !== null) {
            $rules = array_merge($rules, $this->preset->getRules());
        }
        return $rules;
    }


    /**
     * Get EN16931 validation rules
     * @return array<string,callable> Map of rules
     */
    private function getDefaultRules(): array {
        $res = [];

        $res['BR-01'] = static function(Invoice $inv) {
            if ($inv->getSpecification() === null) return "An Invoice shall have a Specification identifier (BT-24)";
        };
        $res['BR-02'] = static function(Invoice $inv) {
            if ($inv->getNumber() === null) return "An Invoice shall have an Invoice number (BT-1)";
        };
        $res['BR-03'] = static function(Invoice $inv) {
            if ($inv->getIssueDate() === null) return "An Invoice shall have an Invoice issue date (BT-2)";
        };
        $res['BR-06'] = static function(Invoice $inv) {
            if ($inv->getSeller() === null) return "Missing Seller from Invoice";
            if ($inv->getSeller()->getName() === null) return "An Invoice shall contain the Seller name (BT-27)";
        };
        $res['BR-07'] = static function(Invoice $inv) {
            if ($inv->getBuyer() === null) return "Missing Buyer from Invoice";
            if ($inv->getBuyer()->getName() === null) return "An Invoice shall contain the Buyer name (BT-44)";
        };
        $res['BR-09'] = static function(Invoice $inv) {
            if ($inv->getSeller()->getCountry() === null) {
                return "The Seller postal address shall contain a Seller country code (BT-40)";
            }
        };
        $res['BR-11'] = static function(Invoice $inv) {
            if ($inv->getBuyer()->getCountry() === null) {
                return "The Buyer postal address shall contain a Buyer country code (BT-55)";
            }
        };
        $res['BR-16'] = static function(Invoice $inv) {
            if (empty($inv->getLines())) return "An Invoice shall have at least one Invoice line (BG-25)";
        };
        $res['BR-17'] = static function(Invoice $inv) {
            if ($inv->getPayee() === null) return;
            if ($inv->getSeller()->getName() === $inv->getPayee()->getName()) return;
            if ($inv->getPayee()->getName() === null) {
                return "The Payee name shall be provided in the Invoice, if the Payee is different from the Seller";
            }
        };
        $res['BR-25'] = static function(Invoice $inv) {
            foreach ($inv->getLines() as $line) {
                if ($line->getName() === null) return "Each Invoice line (BG-25) shall contain the Item name (BT-153)";
            }
        };
        $res['BR-26'] = static function(Invoice $inv) {
            foreach ($inv->getLines() as $line) {
                if ($line->getPrice() === null) {
                    return "Each Invoice line (BG-25) shall contain the Item net price (BT-146)";
                }
            }
        };
        $res['BR-27'] = static function(Invoice $inv) {
            foreach ($inv->getLines() as $line) {
                if ($line->getPrice() < 0) return "The Item net price (BT-146) shall NOT be negative";
            }
        };
        $res['BR-31'] = static function(Invoice $inv) {
            foreach ($inv->getAllowances() as $allowance) {
                if ($allowance->getAmount() === null) {
                    return "Each Document level allowance shall have a Document level allowance amount (BT-92)";
                }
            }
        };
        $res['BR-33'] = static function(Invoice $inv) {
            foreach ($inv->getAllowances() as $allowance) {
                if ($allowance->getReasonCode() === null && $allowance->getReason() === null) {
                    return "Each Document level allowance shall have a Document level allowance reason (BT-97) " .
                        "or a Document level allowance reason code (BT-98)";
                }
            }
        };
        $res['BR-36'] = static function(Invoice $inv) {
            foreach ($inv->getCharges() as $charge) {
                if ($charge->getAmount() === null) {
                    return "Each Document level charge shall have a Document level charge amount (BT-99)";
                }
            }
        };
        $res['BR-38'] = static function(Invoice $inv) {
            foreach ($inv->getCharges() as $charge) {
                if ($charge->getReasonCode() === null && $charge->getReason() === null) {
                    return "Each Document level charge shall have a Document level charge reason (BT-104) " .
                        "or a Document level charge reason code (BT-105)";
                }
            }
        };
        $res['BR-41'] = static function(Invoice $inv) {
            foreach ($inv->getLines() as $line) {
                foreach ($line->getAllowances() as $allowance) {
                    if ($allowance->getAmount() === null) {
                        return "Each Invoice line allowance shall have an Invoice line allowance amount (BT-136)";
                    }
                }
            }
        };
        $res['BR-42'] = static function(Invoice $inv) {
            foreach ($inv->getLines() as $line) {
                foreach ($line->getAllowances() as $allowance) {
                    if ($allowance->getReasonCode() === null && $allowance->getReason() === null) {
                        return "Each Invoice line allowance shall have an Invoice line allowance reason (BT-139) " .
                            "or an Invoice line allowance reason code (BT-140)";
                    }
                }
            }
        };
        $res['BR-43'] = static function(Invoice $inv) {
            foreach ($inv->getLines() as $line) {
                foreach ($line->getCharges() as $charge) {
                    if ($charge->getAmount() === null) {
                        return "Each Invoice line charge shall have an Invoice line charge amount (BT-141)";
                    }
                }
            }
        };
        $res['BR-44'] = static function(Invoice $inv) {
            foreach ($inv->getLines() as $line) {
                foreach ($line->getCharges() as $charge) {
                    if ($charge->getReasonCode() === null && $charge->getReason() === null) {
                        return "Each Invoice line charge shall have an Invoice line charge reason " .
                            "or an invoice line allowance reason code";
                    }
                }
            }
        };
        $res['BR-49'] = static function(Invoice $inv) {
            foreach ($inv->getPayments() as $payment) {
                if ($payment->getMeansCode() === null) {
                    return "A Payment instruction (BG-16) shall specify the Payment means type code (BT-81)";
                }
            }
        };
        $res['BR-50'] = static function(Invoice $inv) {
            foreach ($inv->getPayments() as $payment) {
                foreach ($payment->getTransfers() as $transfer) {
                    if ($transfer->getAccountId() === null) {
                        return "A Payment account identifier (BT-84) shall be present if Credit transfer (BG-17) " .
                            "information is provided in the Invoice";
                    }
                }
            }
        };
        $res['BR-51'] = static function(Invoice $inv) {
            foreach ($inv->getPayments() as $payment) {
                if ($payment->getCard() === null) continue;
                if ($payment->getCard()->getPan() === null) {
                    return "The last 4 to 6 digits of the Payment card primary account number (BT-87) " .
                        "shall be present if Payment card information (BG-18) is provided in the Invoice";
                }
            }
        };
        $res['BR-52'] = static function(Invoice $inv) {
            foreach ($inv->getAttachments() as $attachment) {
                if ($attachment->getId() === null) {
                    return "Each Additional supporting document shall contain a Supporting document reference (BT-122)";
                }
            }
        };
        $res['BR-61'] = static function(Invoice $inv) {
            foreach ($inv->getPayments() as $payment) {
                if (in_array($payment->getMeansCode(), ['30', '58']) && empty($payment->getTransfers())) {
                    return "If the Payment means type code (BT-81) means SEPA credit transfer, Local credit transfer or " .
                        "Non-SEPA international credit transfer, the Payment account identifier (BT-84) shall be present";
                }
            }
        };
        $res['BR-64'] = static function(Invoice $inv) {
            foreach ($inv->getLines() as $line) {
                if ($line->getStandardIdentifier() === null) continue;
                if ($line->getStandardIdentifier()->getScheme() === null) {
                    return "The Item standard identifier (BT-157) shall have a Scheme identifier";
                }
            }
        };
        $res['BR-65'] = static function(Invoice $inv) {
            foreach ($inv->getLines() as $line) {
                foreach ($line->getClassificationIdentifiers() as $identifier) {
                    if ($identifier->getScheme() === null) {
                        return "The Item classification identifier (BT-158) shall have a Scheme identifier";
                    }
                }
            }
        };

        $res['BR-18'] = static function(Invoice $inv) {
            $representative = $inv->getTaxRepresentative();
            if ($representative === null) return;
            if ($representative->getName() === null) {
                return "The Seller tax representative name (BT-62) shall be provided in the Invoice, " .
                    "if the Seller (BG-4) has a Seller tax representative party (BG-11)";
            }
        };
        $res['BR-20'] = static function(Invoice $inv) {
            $representative = $inv->getTaxRepresentative();
            if ($representative === null) return;
            if ($representative->getCountry() === null) {
                return "The Seller tax representative postal address (BG-12) shall contain a " .
                    "Tax representative country code (BT-69), if the Seller (BG-4) has a Seller " .
                    "tax representative party (BG-11)";
            }
        };
        $res['BR-28'] = static function(Invoice $inv) {
            foreach ($inv->getLines() as $line) {
                $grossPrice = $line->getGrossPrice();
                if ($grossPrice === null) continue;
                if ($grossPrice < 0) return "The Item gross price (BT-148) shall NOT be negative";
                if ($grossPrice < ((float) $line->getPrice()) - self::amountTolerance()) {
                    return "The Item gross price (BT-148) shall not be lower than the Item net price (BT-146)";
                }
            }
        };
        $res['BR-32'] = static function(Invoice $inv) {
            foreach ($inv->getAllowances() as $allowance) {
                if ($allowance->getVatCategory() === '') {
                    return "Each Document level allowance (BG-20) shall have a Document level " .
                        "allowance VAT category code (BT-95)";
                }
            }
        };
        $res['BR-37'] = static function(Invoice $inv) {
            foreach ($inv->getCharges() as $charge) {
                if ($charge->getVatCategory() === '') {
                    return "Each Document level charge (BG-21) shall have a Document level " .
                        "charge VAT category code (BT-102)";
                }
            }
        };
        $res['BR-57'] = static function(Invoice $inv) {
            $delivery = $inv->getDelivery();
            if ($delivery === null) return;
            // The address group is only present when at least one of its fields is
            if ($delivery->getAddress() === [] && $delivery->getCity() === null && $delivery->getPostalCode() === null) {
                return;
            }
            if ($delivery->getCountry() === null) {
                return "Each Deliver to address (BG-15) shall contain a Deliver to country code (BT-80)";
            }
        };
        $res['BR-62'] = static function(Invoice $inv) {
            $address = $inv->getSeller()->getElectronicAddress();
            if ($address !== null && $address->getScheme() === null) {
                return "The Seller electronic address (BT-34) shall have a Scheme identifier";
            }
        };
        $res['BR-63'] = static function(Invoice $inv) {
            $address = $inv->getBuyer()->getElectronicAddress();
            if ($address !== null && $address->getScheme() === null) {
                return "The Buyer electronic address (BT-49) shall have a Scheme identifier";
            }
        };

        $res = array_merge($res, self::getVatCategoryRules(), self::getCoherenceRules(), self::getDecimalRules());

        return $res;
    }


    /**
     * Get the VAT category rules (BR-S-*, BR-Z-*, BR-E-*, BR-AE-*, BR-IC-*,
     * BR-G-* and BR-O-*), driven by the VAT_CATEGORY_RULES table
     * @return array<string,callable> Map of rules
     */
    private static function getVatCategoryRules(): array {
        $res = [];

        // BR-*-5/6/7: the VAT rate allowed for a category
        $res['VAT-RATE'] = static function(Invoice $inv) {
            $table = self::getVatCategoryRuleTable();
            foreach (self::getVatItems($inv) as $entry) {
                $category = $entry['item']->getVatCategory();
                if (!isset($table[$category])) continue;
                [$prefix, $rateRule] = $table[$category];
                $rate = $entry['item']->getVatRate();
                $ruleId = $prefix . '-' . $entry['rateSuffix'];
                $label = $entry['label'];
                $rateTerm = $entry['rateTerm'];

                if ($rateRule === 'positive' && ($rate === null || $rate <= 0)) {
                    return [
                        $ruleId,
                        "In a $label where the VAT category code is \"Standard rated\" " .
                            "the VAT rate ($rateTerm) shall be greater than zero"
                    ];
                }
                if ($rateRule === 'zero' && ($rate === null || abs($rate) > 0.0001)) {
                    return [
                        $ruleId,
                        "In a $label where the VAT category code is \"$category\" " .
                            "the VAT rate ($rateTerm) shall be 0 (zero)"
                    ];
                }
                if ($rateRule === 'null' && $rate !== null) {
                    return [
                        $ruleId,
                        "A $label where the VAT category code is \"Not subject to VAT\" " .
                            "shall not contain a VAT rate ($rateTerm)"
                    ];
                }
            }
        };

        // BR-*-10: the VAT exemption reason expected on a VAT breakdown
        $res['VAT-EXEMPTION'] = static function(Invoice $inv) {
            $table = self::getVatCategoryRuleTable();
            foreach ($inv->getTotals()->vatBreakdown as $breakdown) {
                if (!isset($table[$breakdown->category])) continue;
                [$prefix, , $exemptionRule] = $table[$breakdown->category];
                $hasReason = ($breakdown->exemptionReasonCode !== null || $breakdown->exemptionReason !== null);

                if ($exemptionRule === 'required' && !$hasReason) {
                    return [
                        "$prefix-10",
                        "A VAT Breakdown (BG-23) with VAT Category code (BT-118) \"$breakdown->category\" " .
                            "shall have a VAT exemption reason code (BT-121) or a VAT exemption reason text (BT-120)"
                    ];
                }
                if ($exemptionRule === 'forbidden' && $hasReason) {
                    return [
                        "$prefix-10",
                        "A VAT Breakdown (BG-23) with VAT Category code (BT-118) \"$breakdown->category\" " .
                            "shall not have a VAT exemption reason code (BT-121) or " .
                            "VAT exemption reason text (BT-120)"
                    ];
                }
            }
        };

        // BR-*-2/3/4: the VAT identifiers a category requires or forbids
        $res['VAT-IDENTIFIERS'] = static function(Invoice $inv) {
            $table = self::getVatCategoryRuleTable();
            $sellerVat = $inv->getSeller()->getVatNumber();
            $sellerTaxRegistration = $inv->getSeller()->getTaxRegistrationId();
            $representativeVat = $inv->getTaxRepresentative()?->getVatNumber();
            $buyerVat = $inv->getBuyer()->getVatNumber();
            $buyerCompanyId = $inv->getBuyer()->getCompanyId();

            foreach (self::getVatItems($inv) as $entry) {
                $category = $entry['item']->getVatCategory();
                if (!isset($table[$category])) continue;
                [$prefix, , , $sellerRequired, $buyerRequired, $forbidden] = $table[$category];
                $ruleId = $prefix . '-' . $entry['identifierSuffix'];
                $label = $entry['label'];

                if ($forbidden) {
                    if ($sellerVat !== null || $representativeVat !== null || $buyerVat !== null) {
                        return [
                            $ruleId,
                            "An Invoice that contains a $label where the VAT category code is " .
                                "\"Not subject to VAT\" shall not contain the Seller VAT identifier (BT-31), " .
                                "the Seller tax representative VAT identifier (BT-63) or " .
                                "the Buyer VAT identifier (BT-48)"
                        ];
                    }
                    continue;
                }

                if ($sellerRequired) {
                    // BR-IC-2 only accepts BT-31 or BT-63, the other rules also accept BT-32
                    $hasSellerIdentifier = ($category === 'K')
                        ? ($sellerVat !== null || $representativeVat !== null)
                        : ($sellerVat !== null || $sellerTaxRegistration !== null || $representativeVat !== null);
                    if (!$hasSellerIdentifier) {
                        return [
                            $ruleId,
                            "An Invoice that contains a $label where the VAT category code is \"$category\" " .
                                "shall contain the Seller VAT Identifier (BT-31), the Seller tax registration " .
                                "identifier (BT-32) and/or the Seller tax representative VAT identifier (BT-63)"
                        ];
                    }
                }

                if ($buyerRequired) {
                    // BR-AE-2 also accepts BT-47, BR-IC-2 requires BT-48
                    $hasBuyerIdentifier = ($category === 'K')
                        ? ($buyerVat !== null)
                        : ($buyerVat !== null || $buyerCompanyId !== null);
                    if (!$hasBuyerIdentifier) {
                        return [
                            $ruleId,
                            "An Invoice that contains a $label where the VAT category code is \"$category\" " .
                                "shall contain the Buyer VAT identifier (BT-48)"
                        ];
                    }
                }
            }
        };

        // BR-*-9: the VAT amount of a rateless or exempt breakdown is zero
        $res['VAT-AMOUNT-ZERO'] = static function(Invoice $inv) {
            $table = self::getVatCategoryRuleTable();
            foreach ($inv->getTotals()->vatBreakdown as $breakdown) {
                if (!isset($table[$breakdown->category])) continue;
                [$prefix, $rateRule] = $table[$breakdown->category];
                if ($rateRule === 'positive') continue;
                if (abs((float) $breakdown->taxAmount) > self::amountTolerance()) {
                    return [
                        "$prefix-9",
                        "The VAT category tax amount (BT-117) in a VAT breakdown (BG-23) where the " .
                            "VAT category code (BT-118) is \"$breakdown->category\" shall be 0 (zero)"
                    ];
                }
            }
        };

        // BR-O-11 to BR-O-14: category O does not mix with any other category
        $res['BR-O-11'] = static function(Invoice $inv) {
            $breakdown = $inv->getTotals()->vatBreakdown;
            $hasNotSubject = false;
            foreach ($breakdown as $item) {
                if ($item->category === 'O') $hasNotSubject = true;
            }
            if (!$hasNotSubject) return;
            if (count($breakdown) > 1) {
                return "An Invoice that contains a VAT breakdown group (BG-23) with a VAT category code " .
                    "(BT-118) \"Not subject to VAT\" shall not contain other VAT breakdown groups (BG-23)";
            }
        };

        return $res;
    }


    /**
     * Get the coherence rules (BR-CO-*)
     * @return array<string,callable> Map of rules
     */
    private static function getCoherenceRules(): array {
        $res = [];

        $res['BR-CO-3'] = static function(Invoice $inv) {
            if ($inv->getTaxPointDate() !== null && $inv->getVatPointDateCode() !== null) {
                return "Value added tax point date (BT-7) and Value added tax point date code (BT-8) " .
                    "are mutually exclusive";
            }
        };
        $res['BR-CO-4'] = static function(Invoice $inv) {
            foreach ($inv->getLines() as $line) {
                if ($line->getVatCategory() === '') {
                    return "Each Invoice line (BG-25) shall be categorized with an " .
                        "Invoiced item VAT category code (BT-151)";
                }
            }
        };
        $res['BR-CO-10'] = static function(Invoice $inv) {
            $totals = $inv->getTotals();
            $sum = 0.0;
            foreach ($inv->getLines() as $line) {
                $sum += $inv->round($line->getNetAmount() ?? 0.0, 'line/netAmount');
            }
            if (abs((float) $totals->netAmount - $sum) > self::amountTolerance()) {
                return "Sum of Invoice line net amount (BT-106) = \u{2211} Invoice line net amount (BT-131)";
            }
        };
        $res['BR-CO-11'] = static function(Invoice $inv) {
            $totals = $inv->getTotals();
            $sum = 0.0;
            foreach ($inv->getAllowances() as $item) {
                $sum += $inv->round($item->getEffectiveAmount($totals->netAmount), 'invoice/allowancesChargesAmount');
            }
            if (abs((float) $totals->allowancesAmount - $sum) > self::amountTolerance()) {
                return "Sum of allowances on document level (BT-107) = " .
                    "\u{2211} Document level allowance amount (BT-92)";
            }
        };
        $res['BR-CO-12'] = static function(Invoice $inv) {
            $totals = $inv->getTotals();
            $sum = 0.0;
            foreach ($inv->getCharges() as $item) {
                $sum += $inv->round($item->getEffectiveAmount($totals->netAmount), 'invoice/allowancesChargesAmount');
            }
            if (abs((float) $totals->chargesAmount - $sum) > self::amountTolerance()) {
                return "Sum of charges on document level (BT-108) = \u{2211} Document level charge amount (BT-99)";
            }
        };
        $res['BR-CO-13'] = static function(Invoice $inv) {
            $totals = $inv->getTotals();
            $expected = (float) $totals->netAmount - (float) $totals->allowancesAmount + (float) $totals->chargesAmount;
            if (abs((float) $totals->taxExclusiveAmount - $expected) > self::amountTolerance()) {
                return "Invoice total amount without VAT (BT-109) = \u{2211} Invoice line net amount (BT-131) " .
                    "- Sum of allowances on document level (BT-107) " .
                    "+ Sum of charges on document level (BT-108)";
            }
        };
        $res['BR-CO-14'] = static function(Invoice $inv) {
            $totals = $inv->getTotals();
            $sum = 0.0;
            foreach ($totals->vatBreakdown as $breakdown) {
                $sum += (float) $breakdown->taxAmount;
            }
            if (abs((float) $totals->vatAmount - $sum) > self::amountTolerance()) {
                return "Invoice total VAT amount (BT-110) = \u{2211} VAT category tax amount (BT-117)";
            }
        };
        $res['BR-CO-15'] = static function(Invoice $inv) {
            $totals = $inv->getTotals();
            $expected = (float) $totals->taxExclusiveAmount + (float) $totals->vatAmount;
            if (abs((float) $totals->taxInclusiveAmount - $expected) > self::amountTolerance()) {
                return "Invoice total amount with VAT (BT-112) = Invoice total amount without VAT (BT-109) " .
                    "+ Invoice total VAT amount (BT-110)";
            }
        };
        $res['BR-CO-16'] = static function(Invoice $inv) {
            $totals = $inv->getTotals();
            $expected = (float) $totals->taxInclusiveAmount - (float) $totals->paidAmount + (float) $totals->roundingAmount;
            if (abs((float) $totals->payableAmount - $expected) > self::amountTolerance()) {
                return "Amount due for payment (BT-115) = Invoice total amount with VAT (BT-112) " .
                    "- Paid amount (BT-113) + Rounding amount (BT-114)";
            }
        };
        $res['BR-CO-17'] = static function(Invoice $inv) {
            foreach ($inv->getTotals()->vatBreakdown as $breakdown) {
                if ($breakdown->rate === null) continue;
                $expected = round(((float) $breakdown->taxableAmount) * ($breakdown->rate / 100), 2);
                if (abs(((float) $breakdown->taxAmount) - $expected) > self::amountTolerance()) {
                    return "VAT category tax amount (BT-117) = VAT category taxable amount (BT-116) " .
                        "x (VAT category rate (BT-119) / 100), rounded to two decimals";
                }
            }
        };
        $res['BR-CO-18'] = static function(Invoice $inv) {
            if (count($inv->getTotals()->vatBreakdown) < 1) {
                return "An Invoice shall at least have one VAT breakdown group (BG-23)";
            }
        };
        $res['BR-CO-19'] = static function(Invoice $inv) {
            $start = $inv->getPeriodStartDate();
            $end = $inv->getPeriodEndDate();
            if ($start !== null && $end !== null && $start > $end) {
                return "If Invoicing period (BG-14) is used, the Invoicing period start date (BT-73) " .
                    "shall not be later than the Invoicing period end date (BT-74)";
            }
        };
        $res['BR-CO-20'] = static function(Invoice $inv) {
            foreach ($inv->getLines() as $line) {
                $start = $line->getPeriodStartDate();
                $end = $line->getPeriodEndDate();
                if ($start !== null && $end !== null && $start > $end) {
                    return "If Invoice line period (BG-26) is used, the Invoice line period start date (BT-134) " .
                        "shall not be later than the Invoice line period end date (BT-135)";
                }
            }
        };
        $res['BR-CO-25'] = static function(Invoice $inv) {
            if ($inv->getTotals()->payableAmount <= 0) return;
            if ($inv->getDueDate() !== null || $inv->getPaymentTerms() !== null) return;
            foreach ($inv->getPayments() as $payment) {
                if ($payment->getTerms(true) !== null) return; // @phan-suppress-current-line PhanDeprecatedFunction
            }
            return "In case the Amount due for payment (BT-115) is positive, either the " .
                "Payment due date (BT-9) or the Payment terms (BT-20) shall be present";
        };
        $res['BR-CO-26'] = static function(Invoice $inv) {
            $seller = $inv->getSeller();
            if (!empty($seller->getIdentifiers())) return;
            if ($seller->getCompanyId() !== null) return;
            if ($seller->getVatNumber() !== null) return;
            return "In order for the buyer to automatically identify a supplier, the " .
                "Seller identifier (BT-29), the Seller legal registration identifier (BT-30) " .
                "and/or the Seller VAT identifier (BT-31) shall be present";
        };

        return $res;
    }


    /**
     * Get the decimal count rules (BR-DEC-*).
     *
     * BR-DEC constrains the amounts of the document, which the writers emit
     * through the invoice rounding matrix. A model value carrying more decimals
     * than the matrix keeps is therefore not a breach; only a value the invoice
     * would actually write with more than 2 decimals is.
     * @return array<string,callable> Map of rules
     */
    private static function getDecimalRules(): array {
        $res = [];

        $exceedsTwoDecimals = static function(Invoice $inv, ?float $value, string $field): bool {
            if ($value === null) return false;
            $emitted = $inv->round($value, $field);
            return abs($emitted - round($emitted, 2)) > 1e-9;
        };

        $res['BR-DEC-01'] = static function(Invoice $inv) use ($exceedsTwoDecimals) {
            foreach ($inv->getAllowances() as $item) {
                if ($item->isPercentage()) continue;
                if ($exceedsTwoDecimals($inv, $item->getAmount(), 'invoice/allowancesChargesAmount')) {
                    return "The allowed maximum number of decimals for the " .
                        "Document level allowance amount (BT-92) is 2";
                }
            }
        };
        $res['BR-DEC-05'] = static function(Invoice $inv) use ($exceedsTwoDecimals) {
            foreach ($inv->getCharges() as $item) {
                if ($item->isPercentage()) continue;
                if ($exceedsTwoDecimals($inv, $item->getAmount(), 'invoice/allowancesChargesAmount')) {
                    return "The allowed maximum number of decimals for the " .
                        "Document level charge amount (BT-99) is 2";
                }
            }
        };
        $res['BR-DEC-16'] = static function(Invoice $inv) use ($exceedsTwoDecimals) {
            if ($exceedsTwoDecimals($inv, $inv->getPaidAmount(), 'invoice/paidAmount')) {
                return "The allowed maximum number of decimals for the Paid amount (BT-113) is 2";
            }
        };
        $res['BR-DEC-17'] = static function(Invoice $inv) use ($exceedsTwoDecimals) {
            if ($exceedsTwoDecimals($inv, $inv->getRoundingAmount(), 'invoice/roundingAmount')) {
                return "The allowed maximum number of decimals for the Rounding amount (BT-114) is 2";
            }
        };
        $res['BR-DEC-24'] = static function(Invoice $inv) use ($exceedsTwoDecimals) {
            foreach ($inv->getLines() as $line) {
                foreach ($line->getAllowances() as $item) {
                    if ($item->isPercentage()) continue;
                    if ($exceedsTwoDecimals($inv, $item->getAmount(), 'line/allowanceChargeAmount')) {
                        return "The allowed maximum number of decimals for the " .
                            "Invoice line allowance amount (BT-136) is 2";
                    }
                }
            }
        };
        $res['BR-DEC-27'] = static function(Invoice $inv) use ($exceedsTwoDecimals) {
            foreach ($inv->getLines() as $line) {
                foreach ($line->getCharges() as $item) {
                    if ($item->isPercentage()) continue;
                    if ($exceedsTwoDecimals($inv, $item->getAmount(), 'line/allowanceChargeAmount')) {
                        return "The allowed maximum number of decimals for the " .
                            "Invoice line charge amount (BT-141) is 2";
                    }
                }
            }
        };

        return $res;
    }


    /**
     * List every item carrying a VAT category, together with the rule suffixes
     * and the wording that identify it in the EN 16931 rule texts.
     *
     * The identifier rules number their variants 2/3/4 (line, document level
     * allowance, document level charge) and the rate rules 5/6/7.
     * @param  Invoice $inv Invoice instance
     * @return array<int, array{item: object, identifierSuffix: string, rateSuffix: string, label: string, rateTerm: string}>
     */
    private static function getVatItems(Invoice $inv): array {
        $items = [];
        foreach ($inv->getLines() as $line) {
            $items[] = [
                'item' => $line, 'identifierSuffix' => '2', 'rateSuffix' => '5',
                'label' => 'Invoice line (BG-25)', 'rateTerm' => 'BT-152'
            ];
        }
        foreach ($inv->getAllowances() as $allowance) {
            $items[] = [
                'item' => $allowance, 'identifierSuffix' => '3', 'rateSuffix' => '6',
                'label' => 'Document level allowance (BG-20)', 'rateTerm' => 'BT-96'
            ];
        }
        foreach ($inv->getCharges() as $charge) {
            $items[] = [
                'item' => $charge, 'identifierSuffix' => '4', 'rateSuffix' => '7',
                'label' => 'Document level charge (BG-21)', 'rateTerm' => 'BT-103'
            ];
        }
        return $items;
    }
}
