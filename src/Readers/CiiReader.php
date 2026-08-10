<?php

namespace Einvoicing\Readers;

use DateTime;
use Einvoicing\AllowanceOrCharge;
use Einvoicing\Delivery;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\InvoiceReference;
use Einvoicing\Party;
use Einvoicing\Payments\Card;
use Einvoicing\Payments\Mandate;
use Einvoicing\Payments\Payment;
use Einvoicing\Payments\Transfer;
use InvalidArgumentException;
use UXML\UXML;

class CiiReader extends AbstractReader
{
    /**
     * @inheritdoc
     *
     * The header VAT breakdown has no dedicated receptacle in the model, so its
     * exemption reason and reason code (BT-120/BT-121) are copied onto every
     * invoice line whose category and rate match the breakdown and which does
     * not carry one already.
     * @throws InvalidArgumentException if failed to parse XML
     */
    public function import(string $document): Invoice
    {
        // A DOCTYPE serves no purpose here and is an entity expansion vector
        if (preg_match('/<!DOCTYPE/i', $document) === 1) {
            throw new InvalidArgumentException("XML documents with a DOCTYPE declaration are not accepted");
        }

        // Load XML document
        $xml = UXML::fromString($document);

        // BT-23 and BT-24: business process and specification identifier
        $businessProcess = $xml->get("rsm:ExchangedDocumentContext/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID")?->asText();
        $specification = $xml->get("rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID")?->asText();

        // Try to create from preset
        $presetClassname = ($specification !== null) ? $this->getPresetFromSpecification($specification) : null;
        $invoice = ($presetClassname !== null) ? new Invoice($presetClassname) : new Invoice();

        // Document values win over the preset defaults
        if ($specification !== null) {
            $invoice->setSpecification($specification);
        }
        if ($businessProcess !== null) {
            $invoice->setBusinessProcess($businessProcess);
        }

        $exchangedDoc = $xml->get("rsm:ExchangedDocument");
        if ($exchangedDoc !== null) {
            // BT-1: Invoice number
            $numberNode = $exchangedDoc->get("ram:ID");
            if ($numberNode !== null) {
                $invoice->setNumber($numberNode->asText());
            }

            // BT-3: Invoice type code
            $typeNode = $exchangedDoc->get("ram:TypeCode");
            if ($typeNode !== null) {
                $invoice->setType((int)$typeNode->asText());
            }

            // BT-2: Issue date
            $issueDateNode = $exchangedDoc->get("ram:IssueDateTime/udt:DateTimeString");
            if ($issueDateNode !== null) {
                $invoice->setIssueDate($this->parseDateTime($issueDateNode));
            }

            // BT-22: Notes
            foreach ($exchangedDoc->getAll("ram:IncludedNote") as $noteNode) {
                $invoice->addNote(
                    $noteNode->get("ram:Content")?->asText() ?? '',
                    $noteNode->get("ram:SubjectCode")?->asText()
                );
            }
        }

        $transaction = $xml->get("rsm:SupplyChainTradeTransaction");
        if ($transaction !== null) {
            // Process Header Agreement
            $agreement = $transaction->get("ram:ApplicableHeaderTradeAgreement");
            if ($agreement !== null) {
                // BT-10: Buyer reference
                $buyerReferenceNode = $agreement->get("ram:BuyerReference");
                if ($buyerReferenceNode !== null) {
                    $invoice->setBuyerReference($buyerReferenceNode->asText());
                }

                // Seller
                $sellerNode = $agreement->get("ram:SellerTradeParty");
                if ($sellerNode !== null) {
                    $invoice->setSeller($this->parsePartyNode($sellerNode));
                }

                // Buyer
                $buyerNode = $agreement->get("ram:BuyerTradeParty");
                if ($buyerNode !== null) {
                    $invoice->setBuyer($this->parsePartyNode($buyerNode));
                }

                // BT-13: Purchase order reference
                $poNode = $agreement->get("ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID");
                if ($poNode !== null) {
                    $invoice->setPurchaseOrderReference($poNode->asText());
                }

                // BT-14: Sales order reference
                $soNode = $agreement->get("ram:SellerOrderReferencedDocument/ram:IssuerAssignedID");
                if ($soNode !== null) {
                    $invoice->setSalesOrderReference($soNode->asText());
                }

                // BT-12: Contract reference
                $contractNode = $agreement->get("ram:ContractReferencedDocument/ram:IssuerAssignedID");
                if ($contractNode !== null) {
                    $invoice->setContractReference($contractNode->asText());
                }

                // BG-11: Seller tax representative
                $taxRepresentativeNode = $agreement->get("ram:SellerTaxRepresentativeTradeParty");
                if ($taxRepresentativeNode !== null) {
                    $invoice->setTaxRepresentative($this->parsePartyNode($taxRepresentativeNode));
                }

                // BT-18: Invoiced object identifier
                foreach ($agreement->getAll("ram:AdditionalReferencedDocument") as $referenceNode) {
                    if ($referenceNode->get("ram:TypeCode")?->asText() !== '130') {
                        continue;
                    }
                    $value = $referenceNode->get("ram:IssuerAssignedID")?->asText();
                    if ($value === null) {
                        continue;
                    }
                    $invoice->setInvoicedObjectIdentifier(new Identifier(
                        $value,
                        $referenceNode->get("ram:ReferenceTypeCode")?->asText()
                    ));
                    break;
                }
            }

            // Process Header Delivery
            $delivery = $transaction->get("ram:ApplicableHeaderTradeDelivery");
            if ($delivery !== null) {
                $invoice->setDelivery($this->parseDeliveryNode($delivery));

                // BT-16: Despatch advice reference
                $despatchAdviceNode = $delivery->get("ram:DespatchAdviceReferencedDocument/ram:IssuerAssignedID");
                if ($despatchAdviceNode !== null) {
                    $invoice->setDespatchAdviceReference($despatchAdviceNode->asText());
                }
            }

            // Process Header Settlement
            $settlement = $transaction->get("ram:ApplicableHeaderTradeSettlement");
            if ($settlement !== null) {
                // BT-5: Invoice currency code
                $currencyNode = $settlement->get("ram:InvoiceCurrencyCode");
                if ($currencyNode !== null) {
                    $invoice->setCurrency($currencyNode->asText());
                }

                // BT-6: VAT accounting currency code
                $vatCurrencyNode = $settlement->get("ram:TaxCurrencyCode");
                if ($vatCurrencyNode !== null) {
                    $invoice->setVatCurrency($vatCurrencyNode->asText());
                }

                // BG-10: Payee
                $payeeNode = $settlement->get("ram:PayeeTradeParty");
                if ($payeeNode !== null) {
                    $invoice->setPayee($this->parsePartyNode($payeeNode));
                }

                $this->parsePaymentMeans($invoice, $settlement);

                // BT-7 and BT-8: tax point date and VAT point date code, carried
                // by the first VAT breakdown entry
                $firstTax = $settlement->get("ram:ApplicableTradeTax");
                if ($firstTax !== null) {
                    $taxPointDateNode = $firstTax->get("ram:TaxPointDate/udt:DateString")
                        ?? $firstTax->get("ram:TaxPointDate/udt:DateTimeString");
                    if ($taxPointDateNode !== null) {
                        $invoice->setTaxPointDate($this->parseDateTime($taxPointDateNode));
                    }
                    $vatPointDateCodeNode = $firstTax->get("ram:DueDateTypeCode");
                    if ($vatPointDateCodeNode !== null) {
                        $invoice->setVatPointDateCode($vatPointDateCodeNode->asText());
                    }
                }

                // BG-14: Invoicing period
                $periodNode = $settlement->get("ram:BillingSpecifiedPeriod");
                if ($periodNode !== null) {
                    $start = $periodNode->get("ram:StartDateTime/udt:DateTimeString");
                    $end = $periodNode->get("ram:EndDateTime/udt:DateTimeString");
                    if ($start !== null) {
                        $invoice->setPeriodStartDate($this->parseDateTime($start));
                    }
                    if ($end !== null) {
                        $invoice->setPeriodEndDate($this->parseDateTime($end));
                    }
                }

                // Allowances and Charges (Header)
                foreach ($settlement->getAll("ram:SpecifiedTradeAllowanceCharge") as $acNode) {
                    $this->addAllowanceOrCharge($invoice, $acNode);
                }

                // BT-20: Payment terms
                $termsNode = $settlement->get("ram:SpecifiedTradePaymentTerms/ram:Description");
                if ($termsNode !== null) {
                    $invoice->setPaymentTerms($termsNode->asText());
                }

                // BT-9: Due date
                $dueDateNode = $settlement->get("ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString");
                if ($dueDateNode !== null) {
                    $invoice->setDueDate($this->parseDateTime($dueDateNode));
                }

                // BT-113: Paid amount
                $paidAmountNode = $settlement->get("ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TotalPrepaidAmount");
                if ($paidAmountNode !== null) {
                    $invoice->setPaidAmount((float)$paidAmountNode->asText());
                }

                // BT-114: Rounding amount
                $roundingAmountNode = $settlement->get("ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:RoundingAmount");
                if ($roundingAmountNode !== null) {
                    $invoice->setRoundingAmount((float)$roundingAmountNode->asText());
                }

                // BT-19: Buyer accounting reference
                $buyerAccountNode = $settlement->get("ram:ReceivableSpecifiedTradeAccountingAccount/ram:ID");
                if ($buyerAccountNode !== null) {
                    $invoice->setBuyerAccountingReference($buyerAccountNode->asText());
                }

                // BT-25 and BT-26: preceding invoice references
                foreach ($settlement->getAll("ram:InvoiceReferencedDocument") as $refNode) {
                    $value = $refNode->get("ram:IssuerAssignedID")?->asText();
                    if ($value === null) {
                        continue;
                    }
                    $dateNode = $refNode->get("ram:FormattedIssueDateTime/qdt:DateTimeString")
                        ?? $refNode->get("ram:FormattedIssueDateTime/udt:DateTimeString");
                    $invoice->addPrecedingInvoiceReference(new InvoiceReference(
                        $value,
                        ($dateNode !== null) ? $this->parseDateTime($dateNode) : null
                    ));
                }
            }

            // Invoice lines
            foreach ($transaction->getAll("ram:IncludedSupplyChainTradeLineItem") as $lineNode) {
                $invoice->addLine($this->parseInvoiceLine($lineNode));
            }

            if ($settlement !== null) {
                $this->applyExemptionReasonsToLines($invoice, $settlement);
            }
        }

        return $invoice;
    }

    /**
     * BG-16: payment instructions. One Payment is created per
     * ram:SpecifiedTradeSettlementPaymentMeans; BT-83 lives at settlement level
     * and is carried by the first payment.
     */
    private function parsePaymentMeans(Invoice $invoice, UXML $settlement): void
    {
        $paymentReference = $settlement->get("ram:PaymentReference")?->asText();
        $creditorIdentifier = $settlement->get("ram:CreditorReferenceID")?->asText();
        $isFirst = true;

        foreach ($settlement->getAll("ram:SpecifiedTradeSettlementPaymentMeans") as $meansNode) {
            $payment = new Payment();
            $payment->setMeansCode($meansNode->get("ram:TypeCode")?->asText());
            $payment->setMeansText($meansNode->get("ram:Information")?->asText());
            if ($isFirst && $paymentReference !== null) {
                $payment->setId($paymentReference);
            }

            // BG-17: credit transfers
            foreach ($meansNode->getAll("ram:PayeePartyCreditorFinancialAccount") as $accountNode) {
                $payment->addTransfer((new Transfer())
                    ->setAccountId($accountNode->get("ram:IBANID")?->asText())
                    ->setAccountName($accountNode->get("ram:AccountName")?->asText())
                    ->setProvider($meansNode->get("ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID")?->asText()));
            }

            // BG-18: payment card information
            $cardNode = $meansNode->get("ram:ApplicableTradeSettlementFinancialCard");
            if ($cardNode !== null) {
                $payment->setCard((new Card())
                    ->setPan($cardNode->get("ram:ID")?->asText())
                    ->setHolder($cardNode->get("ram:CardholderName")?->asText()));
            }

            // BG-19: direct debit
            $debtorAccount = $meansNode->get("ram:PayerPartyDebtorFinancialAccount/ram:IBANID")?->asText();
            if ($debtorAccount !== null || ($isFirst && $creditorIdentifier !== null)) {
                $mandate = new Mandate();
                $mandate->setAccount($debtorAccount);
                if ($isFirst && $creditorIdentifier !== null) {
                    $mandate->setCreditorIdentifier($creditorIdentifier);
                }
                $payment->setMandate($mandate);
            }

            $invoice->addPayment($payment);
            $isFirst = false;
        }
    }

    /**
     * The model stores exemption reasons on the items, not on the breakdown, so
     * BT-120/BT-121 are pushed down to the matching lines that lack them.
     */
    private function applyExemptionReasonsToLines(Invoice $invoice, UXML $settlement): void
    {
        foreach ($settlement->getAll("ram:ApplicableTradeTax") as $taxNode) {
            $reason = $taxNode->get("ram:ExemptionReason")?->asText();
            $reasonCode = $taxNode->get("ram:ExemptionReasonCode")?->asText();
            if ($reason === null && $reasonCode === null) {
                continue;
            }

            $category = $taxNode->get("ram:CategoryCode")?->asText();
            $rateNode = $taxNode->get("ram:RateApplicablePercent");
            $rate = ($rateNode !== null) ? (float) $rateNode->asText() : null;

            foreach ($invoice->getLines() as $line) {
                if ($line->getVatCategory() !== $category) {
                    continue;
                }
                $lineRate = $line->getVatRate();
                if ($rate === null xor $lineRate === null) {
                    continue;
                }
                if ($rate !== null && $lineRate !== null && abs($rate - $lineRate) > 0.005) {
                    continue;
                }
                if ($reason !== null && $line->getVatExemptionReason() === null) {
                    $line->setVatExemptionReason($reason);
                }
                if ($reasonCode !== null && $line->getVatExemptionReasonCode() === null) {
                    $line->setVatExemptionReasonCode($reasonCode);
                }
            }
        }
    }

    /**
     * Parse a date value, rejecting anything the declared format cannot express
     * @throws InvalidArgumentException if the value is not a valid date
     */
    private function parseDateTime(UXML $node): DateTime
    {
        $element = $node->element();
        $format = $element->hasAttribute('format') ? $element->getAttribute('format') : null;
        $value = trim($node->asText());

        // The leading "!" resets the time fields to 00:00:00
        $result = match ($format) {
            '102' => DateTime::createFromFormat('!Ymd', $value),
            default => null,
        };
        if ($result === null) {
            try {
                return new DateTime($value);
            } catch (\Exception $e) {
                throw new InvalidArgumentException("Invalid date value: '$value'", 0, $e);
            }
        }
        // createFromFormat is lenient and rolls a month 13 or a day 99 over, so
        // the result has to render back to the value it came from
        if ($result === false || $result->format('Ymd') !== $value) {
            throw new InvalidArgumentException("Invalid date value: '$value' for format $format");
        }
        return $result;
    }

    private function parsePartyNode(UXML $xml): Party
    {
        $party = new Party();

        // BT-29 and BT-46: party identifiers
        foreach ($xml->getAll("ram:ID") as $idNode) {
            $party->addIdentifier($this->parseIdentifierNode($idNode));
        }
        foreach ($xml->getAll("ram:GlobalID") as $globalIdNode) {
            $party->addIdentifier($this->parseIdentifierNode($globalIdNode));
        }

        // BT-27: Name
        $nameNode = $xml->get("ram:Name");
        if ($nameNode !== null) {
            $party->setName($nameNode->asText());
        }

        // BT-28: Trading name
        $tradingNameNode = $xml->get("ram:SpecifiedLegalOrganization/ram:TradingBusinessName");
        if ($tradingNameNode !== null) {
            $party->setTradingName($tradingNameNode->asText());
        }

        // BT-30 and BT-47: legal registration identifier
        $legalOrgNode = $xml->get("ram:SpecifiedLegalOrganization/ram:ID");
        if ($legalOrgNode !== null) {
            $party->setCompanyId($this->parseIdentifierNode($legalOrgNode));
        }

        // Postal address
        $addressNode = $xml->get("ram:PostalTradeAddress");
        if ($addressNode !== null) {
            $party->setPostalCode($addressNode->get("ram:PostcodeCode")?->asText());
            $party->setCity($addressNode->get("ram:CityName")?->asText());
            $party->setCountry($addressNode->get("ram:CountryID")?->asText());

            $lines = [];
            if (($line = $addressNode->get("ram:LineOne")?->asText()) !== null) $lines[] = $line;
            if (($line = $addressNode->get("ram:LineTwo")?->asText()) !== null) $lines[] = $line;
            if (($line = $addressNode->get("ram:LineThree")?->asText()) !== null) $lines[] = $line;
            $party->setAddress($lines);
        }

        // BT-34: Electronic address
        $eaNode = $xml->get("ram:URIUniversalCommunication/ram:URIID");
        if ($eaNode !== null) {
            $party->setElectronicAddress($this->parseIdentifierNode($eaNode));
        }

        // BT-31: VAT identifier
        $vatNode = $xml->get("ram:SpecifiedTaxRegistration[ram:ID/@schemeID='VA']/ram:ID");
        if ($vatNode === null) {
            // Fallback if schemeID is missing but it's the only registration
            $vatNode = $xml->get("ram:SpecifiedTaxRegistration/ram:ID");
        }
        if ($vatNode !== null) {
            $party->setVatNumber($vatNode->asText());
        }

        // Contact information
        $contactNode = $xml->get("ram:DefinedTradeContact");
        if ($contactNode !== null) {
            $party->setContactName($contactNode->get("ram:PersonName")?->asText());
            $party->setContactPhone($contactNode->get("ram:TelephoneUniversalCommunication/ram:CompleteNumber")?->asText());
            $party->setContactEmail($contactNode->get("ram:EmailURIUniversalCommunication/ram:URIID")?->asText());
        }

        return $party;
    }

    private function parseIdentifierNode(UXML $xml): Identifier
    {
        $value = $xml->asText();
        $scheme = $xml->element()->hasAttribute('schemeID') ? $xml->element()->getAttribute('schemeID') : null;
        return new Identifier($value, $scheme);
    }

    private function parseDeliveryNode(UXML $xml): Delivery
    {
        $delivery = new Delivery();

        // BT-72: Actual delivery date
        $dateNode = $xml->get("ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString");
        if ($dateNode !== null) {
            $delivery->setDate($this->parseDateTime($dateNode));
        }

        return $delivery;
    }

    private function addAllowanceOrCharge($target, UXML $xml)
    {
        $ac = new AllowanceOrCharge();

        $indicatorNode = $xml->get("ram:ChargeIndicator/udt:Indicator");
        $isCharge = ($indicatorNode !== null && $indicatorNode->asText() === 'true');

        if ($isCharge) {
            $target->addCharge($ac);
        } else {
            $target->addAllowance($ac);
        }

        $ac->setAmount((float)($xml->get("ram:ActualAmount")?->asText() ?? 0));

        $percentNode = $xml->get("ram:CalculationPercent");
        if ($percentNode !== null) {
            $ac->markAsPercentage()->setAmount((float)$percentNode->asText());
        }

        $ac->setReasonCode($xml->get("ram:ReasonCode")?->asText());
        $ac->setReason($xml->get("ram:Reason")?->asText());

        // VAT
        $taxNode = $xml->get("ram:CategoryTradeTax");
        if ($taxNode !== null) {
            $ac->setVatCategory($taxNode->get("ram:CategoryCode")?->asText());
            $rateNode = $taxNode->get("ram:RateApplicablePercent");
            if ($rateNode !== null) {
                $ac->setVatRate((float)$rateNode->asText());
            }
        }
    }

    private function parseInvoiceLine(UXML $xml): InvoiceLine
    {
        $line = new InvoiceLine();

        // BT-126: Line ID
        $line->setId($xml->get("ram:AssociatedDocumentLineDocument/ram:LineID")?->asText());

        // BT-127: Note
        $line->setNote($xml->get("ram:AssociatedDocumentLineDocument/ram:IncludedNote/ram:Content")?->asText());

        // Product details
        $product = $xml->get("ram:SpecifiedTradeProduct");
        if ($product !== null) {
            $line->setName($product->get("ram:Name")?->asText());
            $line->setDescription($product->get("ram:Description")?->asText());
            $line->setSellerIdentifier($product->get("ram:SellerAssignedID")?->asText());
            $line->setBuyerIdentifier($product->get("ram:BuyerAssignedID")?->asText());

            $standardIdNode = $product->get("ram:GlobalID");
            if ($standardIdNode !== null) {
                $line->setStandardIdentifier($this->parseIdentifierNode($standardIdNode));
            }

            $originCountryNode = $product->get("ram:OriginTradeCountry/ram:ID");
            if ($originCountryNode !== null) {
                $line->setOriginCountry($originCountryNode->asText());
            }
        }

        // Agreement (Prices)
        $agreement = $xml->get("ram:SpecifiedLineTradeAgreement");
        if ($agreement !== null) {
            $priceNode = $agreement->get("ram:NetPriceProductTradePrice/ram:ChargeAmount");
            if ($priceNode !== null) {
                $line->setPrice((float)$priceNode->asText());
            }

            $baseQtyNode = $agreement->get("ram:NetPriceProductTradePrice/ram:BasisQuantity");
            if ($baseQtyNode !== null) {
                $line->setBaseQuantity((float)$baseQtyNode->asText());
            }

            // BT-148: Item gross price
            $grossPriceNode = $agreement->get("ram:GrossPriceProductTradePrice/ram:ChargeAmount");
            if ($grossPriceNode !== null) {
                $line->setGrossPrice((float)$grossPriceNode->asText());
            }

            // BT-132: Order line reference
            $line->setOrderLineReference($agreement->get("ram:BuyerOrderReferencedDocument/ram:LineID")?->asText());
        }

        // Delivery (Quantity)
        $delivery = $xml->get("ram:SpecifiedLineTradeDelivery");
        if ($delivery !== null) {
            $qtyNode = $delivery->get("ram:BilledQuantity");
            if ($qtyNode !== null) {
                $line->setQuantity((float)$qtyNode->asText());
                $line->setUnit($qtyNode->element()->getAttribute('unitCode'));
            }
        }

        // Settlement
        $settlement = $xml->get("ram:SpecifiedLineTradeSettlement");
        if ($settlement !== null) {
            // VAT
            $taxNode = $settlement->get("ram:ApplicableTradeTax");
            if ($taxNode !== null) {
                $line->setVatCategory($taxNode->get("ram:CategoryCode")?->asText());
                $rateNode = $taxNode->get("ram:RateApplicablePercent");
                if ($rateNode !== null) {
                    $line->setVatRate((float)$rateNode->asText());
                }
            }

            // Allowances and Charges
            foreach ($settlement->getAll("ram:SpecifiedTradeAllowanceCharge") as $acNode) {
                $this->addAllowanceOrCharge($line, $acNode);
            }

            // BT-133: Buyer accounting reference
            $line->setBuyerAccountingReference($settlement->get("ram:ReceivableSpecifiedTradeAccountingAccount/ram:ID")?->asText());

            // Billing Period
            $periodNode = $settlement->get("ram:BillingSpecifiedPeriod");
            if ($periodNode !== null) {
                $start = $periodNode->get("ram:StartDateTime/udt:DateTimeString");
                $end = $periodNode->get("ram:EndDateTime/udt:DateTimeString");
                if ($start) $line->setPeriodStartDate($this->parseDateTime($start));
                if ($end) $line->setPeriodEndDate($this->parseDateTime($end));
            }
        }

        return $line;
    }
}
