<?php

namespace Einvoicing\Readers;

use DateTime;
use Einvoicing\AllowanceOrCharge;
use Einvoicing\Delivery;
use Einvoicing\Identifier;
use Einvoicing\Invoice;
use Einvoicing\InvoiceLine;
use Einvoicing\Party;
use Einvoicing\Payments\Payment;
use Einvoicing\Payments\Transfer;
use Einvoicing\Writers\CiiWriter;
use InvalidArgumentException;
use UXML\UXML;
use function floatval;
use function implode;

class CiiReader extends AbstractReader
{
    /**
     * @inheritdoc
     * @throws InvalidArgumentException if failed to parse XML
     */
    public function import(string $document): Invoice
    {
        $invoice = new Invoice();

        // Load XML document
        $xml = UXML::fromString($document);
        $ram = CiiWriter::NS_RAM;
        $rsm = CiiWriter::NS_INVOICE;
        $udt = CiiWriter::NS_UDT;

        // BT-24: Specification identifier
        $specificationNode = $xml->get("rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID");
        if ($specificationNode !== null) {
            $specification = $specificationNode->asText();
            $invoice->setSpecification($specification);

            // Try to create from preset
            $presetClassname = $this->getPresetFromSpecification($specification);
            if ($presetClassname !== null) {
                $invoice = new Invoice($presetClassname);
            }
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
            foreach ($exchangedDoc->getAll("ram:IncludedNote/ram:Content") as $noteNode) {
                $invoice->addNote($noteNode->asText());
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
            }

            // Process Header Delivery
            $delivery = $transaction->get("ram:ApplicableHeaderTradeDelivery");
            if ($delivery !== null) {
                $invoice->setDelivery($this->parseDeliveryNode($delivery));
            }

            // Process Header Settlement
            $settlement = $transaction->get("ram:ApplicableHeaderTradeSettlement");
            if ($settlement !== null) {
                // BT-5: Invoice currency code
                $paymentRef = $settlement->get("ram:PaymentReference");
                $currencyNode = $settlement->get("ram:InvoiceCurrencyCode");
                if ($currencyNode !== null) {
                    $invoice->setCurrency($currencyNode->asText());
                }

                $paymentMeans = $settlement->get("ram:SpecifiedTradeSettlementPaymentMeans");
                $paymentMethodType = $paymentMeans->get("ram:TypeCode");
                $paymentMethod = $paymentMeans->get("ram:Information");
                $finAccount = $paymentMeans->get("ram:PayeePartyCreditorFinancialAccount");
                $iban = $finAccount->get("ram:IBANID");
                $accountName = $finAccount->get("ram:AccountName");

                $bank = $paymentMeans->get("ram:PayeeSpecifiedCreditorFinancialInstitution")->get("ram:BICID");

                $invoice->addPayment((new Payment())
                    ->setId($paymentRef)
                    ->setMeansCode($paymentMethodType)
                    ->setMeansText($paymentMethod)
                    ->addTransfer(
                        (new Transfer())
                            ->setAccountId($iban)
                            ->setAccountName($accountName)
                            ->setProvider($bank)
                    )
                );
                // BT-6: VAT accounting currency code
                $vatCurrencyNode = $settlement->get("ram:TaxCurrencyCode");
                if ($vatCurrencyNode !== null) {
                    $invoice->setVatCurrency($vatCurrencyNode->asText());
                }

                // BT-19: Buyer accounting reference
                $buyerAccountNode = $settlement->get("ram:ReceivableSpecifiedTradeAccountingAccount/ram:ID");
                if ($buyerAccountNode !== null) {
                    $invoice->setBuyerAccountingReference($buyerAccountNode->asText());
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

                // BT-7: Tax point date
                $taxPointDateNode = $settlement->get("ram:TaxApplicableTradeCurrencyExchange/ram:DateString");
                if ($taxPointDateNode !== null) {
                    $invoice->setTaxPointDate($this->parseDateTime($taxPointDateNode));
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
            }

            // Invoice lines
            foreach ($transaction->getAll("ram:IncludedSupplyChainTradeLineItem") as $lineNode) {
                $invoice->addLine($this->parseInvoiceLine($lineNode));
            }
        }

        return $invoice;
    }

    private function parseDateTime(UXML $node): DateTime
    {
        $format = $node->element()->getAttribute('format');
        $value = $node->asText();
        if ($format === '102') {
            return DateTime::createFromFormat('Ymd', $value)->setTime(0, 0, 0);
        }
        return new DateTime($value);
    }

    private function parsePartyNode(UXML $xml): Party
    {
        $party = new Party();

        // BT-29: Global ID
        $globalIdNode = $xml->get("ram:GlobalID");
        if ($globalIdNode !== null) {
            $party->setCompanyId($this->parseIdentifierNode($globalIdNode));
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

        // BT-30: Legal organization
        $legalOrgNode = $xml->get("ram:SpecifiedLegalOrganization/ram:ID");
        if ($legalOrgNode !== null) {
            $party->addIdentifier($this->parseIdentifierNode($legalOrgNode));
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
