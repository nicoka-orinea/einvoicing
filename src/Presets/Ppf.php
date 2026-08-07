<?php
namespace Einvoicing\Presets;

use Einvoicing\Invoice;
use function abs;
use function count;
use function in_array;
use function preg_match;
use function trim;

// @phan-file-suppress PhanPluginInconsistentReturnFunction

/**
 * French PPF (Portail Public de Facturation) / Factur-X EN 16931 profile
 *
 * Rules G x.xx come from "Spécifications externes FE v3.2", Annexe 7
 * "Règles de gestion" v1.9. The annexes do not pin a value for BT-24, so the
 * Factur-X EN 16931 guideline identifier is used.
 *
 * This preset produces the CII XML only. Assembling a Factur-X PDF/A-3 is out
 * of scope and needs an external PDF tool.
 *
 * @link https://www.impots.gouv.fr/facturation-electronique
 */
class Ppf extends AbstractPreset {
    /** Invoicing frameworks allowed by rule G1.02 (BT-23) */
    private const ALLOWED_BUSINESS_PROCESSES = [
        'B1', 'S1', 'M1', 'B2', 'S2', 'M2', 'B4', 'S4', 'M4', 'S5', 'S6', 'B7', 'S7'
    ];

    /** VAT rates allowed by rule G1.24 */
    private const ALLOWED_VAT_RATES = [
        0, 0.9, 1.05, 1.75, 2.1, 5.5, 7, 8.5, 9.2, 9.6, 10, 13, 19.6, 20, 20.6
    ];

    /** VAT category codes allowed by rule G2.31 */
    private const ALLOWED_VAT_CATEGORIES = ['S', 'E', 'AE', 'K', 'G', 'O', 'Z'];

    /** Credit note types, which need at least one preceding invoice reference (G1.31) */
    private const CREDIT_NOTE_TYPES = [261, 381, 396, 502, 503];

    /** Corrective invoice types, which need exactly one preceding invoice reference (G1.32) */
    private const CORRECTIVE_TYPES = [384, 471, 472, 473];

    /** Company identifier schemes allowed by rule G1.63: SIREN and SIRET */
    private const SIREN_SCHEME = '0002';
    private const SIRET_SCHEME = '0009';

    /**
     * @inheritdoc
     */
    public function getSpecification(): string {
        return "urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:en16931";
    }


    /**
     * @inheritdoc
     */
    public function setupInvoice(Invoice $invoice) {
        parent::setupInvoice($invoice);
        $invoice->setCurrency('EUR');
    }


    /**
     * @inheritdoc
     */
    public function getRules(): array {
        $res = [];

        $res['G1.01'] = static function(Invoice $inv) {
            if (!in_array($inv->getType(), Invoice::FR_ALLOWED_TYPES, true)) {
                return "Le type de facture (BT-3) n'est pas autorisé en France";
            }
        };

        $res['G1.02'] = static function(Invoice $inv) {
            $businessProcess = $inv->getBusinessProcess();
            if ($businessProcess === null) {
                return "Le cadre de facturation (BT-23) est obligatoire";
            }
            if (!in_array($businessProcess, self::ALLOWED_BUSINESS_PROCESSES, true)) {
                return "Le cadre de facturation (BT-23) n'est pas une valeur autorisée";
            }
        };

        $res['G1.05'] = static function(Invoice $inv) {
            $number = $inv->getNumber();
            if ($number === null) return;
            if (mb_strlen($number) > 35) {
                return "L'identifiant de la facture (BT-1) est limité à 35 caractères";
            }
            if (preg_match('/^[A-Za-z0-9 \-+_\/]+$/', $number) !== 1) {
                return "L'identifiant de la facture (BT-1) contient un caractère non autorisé";
            }
            if (trim($number) === '') {
                return "L'identifiant de la facture (BT-1) ne doit pas comporter uniquement des espaces";
            }
            if ($number !== trim($number)) {
                return "L'identifiant de la facture (BT-1) ne peut pas débuter ou terminer par un espace";
            }
            if (str_contains($number, '  ')) {
                return "L'identifiant de la facture (BT-1) ne peut pas comporter d'espaces consécutifs";
            }
        };

        $res['G1.24'] = static function(Invoice $inv) {
            foreach (self::getVatBearingItems($inv) as $item) {
                $rate = $item->getVatRate();
                if ($rate === null) continue;
                foreach (self::ALLOWED_VAT_RATES as $allowed) {
                    if (abs($rate - (float) $allowed) < 0.001) continue 2;
                }
                return "Le taux de TVA $rate n'est pas conforme à la liste des taux applicables en France";
            }
        };

        $res['G1.31'] = static function(Invoice $inv) {
            if (!in_array($inv->getType(), self::CREDIT_NOTE_TYPES, true)) return;
            if (count($inv->getPrecedingInvoiceReferences()) < 1) {
                return "Un avoir comporte obligatoirement au moins un numéro de facture antérieure (BT-25)";
            }
        };

        $res['G1.32'] = static function(Invoice $inv) {
            if (!in_array($inv->getType(), self::CORRECTIVE_TYPES, true)) return;
            if (count($inv->getPrecedingInvoiceReferences()) !== 1) {
                return "Une facture rectificative comporte une et une seule référence " .
                    "à une facture antérieure (BT-25)";
            }
        };

        $res['G1.41'] = static function(Invoice $inv) {
            // Not applicable to a corrective invoice, nor below 150 EUR
            if (in_array($inv->getType(), self::CORRECTIVE_TYPES, true)) return;
            $totals = $inv->getTotals();
            if ($totals->taxInclusiveAmount < 150.0) return;

            foreach ($totals->vatBreakdown as $breakdown) {
                if ($breakdown->category !== 'E') continue;
                if ($breakdown->exemptionReasonCode === null || $breakdown->exemptionReason === null) {
                    return "Une ventilation de la TVA (BG-23) exonérée (E) doit comprendre un code de motif " .
                        "d'exonération (BT-121) et un motif d'exonération (BT-120)";
                }
            }
        };

        $res['G1.53'] = static function(Invoice $inv) {
            $totals = $inv->getTotals();
            // Only applicable when the invoice currency is the euro
            if ($totals->currency !== 'EUR') return;

            $taxableSum = 0.0;
            $taxSum = 0.0;
            foreach ($totals->vatBreakdown as $breakdown) {
                $taxableSum += (float) $breakdown->taxableAmount;
                $taxSum += (float) $breakdown->taxAmount;
            }
            if (abs((float) $totals->taxExclusiveAmount - $taxableSum) > 0.01) {
                return "Le montant total hors TVA (BT-109) doit être égal à la somme des montants " .
                    "de base d'imposition par type de TVA (BT-116)";
            }
            if (abs((float) $totals->vatAmount - $taxSum) > 0.01) {
                return "Le montant total de TVA (BT-110) doit être égal à la somme des totaux " .
                    "de TVA par taux (BT-117)";
            }
        };

        $res['G1.63'] = static function(Invoice $inv) {
            $error = self::checkCompanyId($inv->getSeller()?->getCompanyId(), 'vendeur', 'BT-30');
            if ($error !== null) return $error;

            // Only a French buyer is registered in the PPF directory
            $buyer = $inv->getBuyer();
            if ($buyer !== null && $buyer->getCountry() === 'FR') {
                return self::checkCompanyId($buyer->getCompanyId(), 'acheteur', 'BT-47');
            }
        };

        $res['G2.31'] = static function(Invoice $inv) {
            foreach (self::getVatBearingItems($inv) as $item) {
                $category = $item->getVatCategory();
                if (!in_array($category, self::ALLOWED_VAT_CATEGORIES, true)) {
                    return "Le code de catégorie de TVA \"$category\" n'est pas accepté en France";
                }
            }
        };

        // TODO G2.32: rejecting an invoice made up only of category O, or only of
        // category E with a VATEX-FR-CGI261* exemption code, needs the VATEX code
        // list to be modelled first.

        return $res;
    }


    /**
     * Check a company identifier against rule G1.63
     * @param  \Einvoicing\Identifier|null $companyId Company identifier
     * @param  string                      $party     Party name, for the message
     * @param  string                      $term      Business term identifier
     * @return string|null                            Error message, if any
     */
    private static function checkCompanyId($companyId, string $party, string $term): ?string {
        if ($companyId === null) {
            return "Le SIREN du $party ($term) est obligatoire";
        }

        $scheme = $companyId->getScheme();
        if ($scheme === null) {
            return "L'identifiant de schéma du SIREN du $party ($term) est obligatoire";
        }
        if (!in_array($scheme, [self::SIREN_SCHEME, self::SIRET_SCHEME], true)) {
            return "L'identifiant de schéma du SIREN du $party ($term) doit être " .
                self::SIREN_SCHEME . " ou " . self::SIRET_SCHEME;
        }

        $pattern = ($scheme === self::SIREN_SCHEME) ? '/^\d{9}$/' : '/^\d{14}$/';
        if (preg_match($pattern, $companyId->getValue()) !== 1) {
            return ($scheme === self::SIREN_SCHEME)
                ? "Le SIREN du $party ($term) doit comporter 9 chiffres"
                : "Le SIRET du $party ($term) doit comporter 14 chiffres";
        }

        return null;
    }


    /**
     * List every item carrying a VAT category and rate
     * @param  Invoice $inv Invoice instance
     * @return array<int, object>
     */
    private static function getVatBearingItems(Invoice $inv): array {
        $items = [];
        foreach ($inv->getLines() as $line) {
            $items[] = $line;
        }
        foreach ($inv->getAllowances() as $allowance) {
            $items[] = $allowance;
        }
        foreach ($inv->getCharges() as $charge) {
            $items[] = $charge;
        }
        return $items;
    }
}
