<?php
namespace Einvoicing\Cdar\Enums;

/**
 * CDAR process condition codes for lifecycle updates.
 */
enum ProcessConditionCode: int
{
    case SUBMITTED = 200;
    case EMITTED_BY_PLATFORM = 201;
    case RECEIVED = 202;
    case MADE_AVAILABLE = 203;
    case TAKEN_IN_CHARGE = 204;
    case APPROVED = 205;
    case PARTIALLY_APPROVED = 206;
    case IN_DISPUTE = 207;
    case SUSPENDED = 208;
    case COMPLETED = 209;
    case REFUSED = 210;
    case PAYMENT_TRANSMITTED = 211;
    case PAID = 212;
    case REJECTED = 213;

    // Statuses of the non-invoice objects, from Annexe 2 "Statuts"
    case FLUX1_SUBMITTED = 250;
    case FLUX1_REJECTED = 251;
    case EREPORTING_SUBMITTED = 300;
    case EREPORTING_REJECTED = 301;
    case DIRECTORY_ACCEPTED = 400;
    case DIRECTORY_REJECTED = 401;
    case FLOW_ADMISSIBLE = 500;
    case FLOW_INADMISSIBLE = 501;
    case ACKNOWLEDGEMENT_REJECTED = 601;

    /**
     * Get an English label for UI/logs.
     * Business meaning: lifecycle status label.
     */
    public function label(): string
    {
        return match ($this) {
            self::SUBMITTED => 'Submitted',
            self::EMITTED_BY_PLATFORM => 'Emitted by platform',
            self::RECEIVED => 'Received',
            self::MADE_AVAILABLE => 'Made available',
            self::TAKEN_IN_CHARGE => 'Taken in charge',
            self::APPROVED => 'Approved',
            self::PARTIALLY_APPROVED => 'Partially approved',
            self::IN_DISPUTE => 'In dispute',
            self::SUSPENDED => 'Suspended',
            self::COMPLETED => 'Completed',
            self::REFUSED => 'Refused',
            self::PAYMENT_TRANSMITTED => 'Payment transmitted',
            self::PAID => 'Paid',
            self::REJECTED => 'Rejected',
            self::FLUX1_SUBMITTED => 'Submitted',
            self::FLUX1_REJECTED => 'Rejected',
            self::EREPORTING_SUBMITTED => 'Submitted',
            self::EREPORTING_REJECTED => 'Rejected',
            self::DIRECTORY_ACCEPTED => 'Accepted',
            self::DIRECTORY_REJECTED => 'Rejected',
            self::FLOW_ADMISSIBLE => 'Admissible',
            self::FLOW_INADMISSIBLE => 'Inadmissible',
            self::ACKNOWLEDGEMENT_REJECTED => 'Rejected',
        };
    }

    /**
     * Get the CDAR XML label value.
     * Business meaning: exact label expected in CDAR XML.
     */
    public function xmlLabel(): string
    {
        return match ($this) {
            self::SUBMITTED => 'Deposee',
            self::EMITTED_BY_PLATFORM => 'Emise_par_la_plateforme',
            self::RECEIVED => 'Recue',
            self::MADE_AVAILABLE => 'Mise_a_disposition',
            self::TAKEN_IN_CHARGE => 'Prise_en_charge',
            self::APPROVED => 'Approuvee',
            self::PARTIALLY_APPROVED => 'Approuvee_partiellement',
            self::IN_DISPUTE => 'En_litige',
            self::SUSPENDED => 'Suspendue',
            self::COMPLETED => 'Completee',
            self::REFUSED => 'Refusee',
            self::PAYMENT_TRANSMITTED => 'Paiement_transmis',
            self::PAID => 'Encaissee',
            self::REJECTED => 'Rejetee',
            // Labels of Annexe 2 "Statuts", transliterated like the ones above
            self::FLUX1_SUBMITTED => 'Deposee',
            self::FLUX1_REJECTED => 'Rejetee',
            self::EREPORTING_SUBMITTED => 'Deposee',
            self::EREPORTING_REJECTED => 'Rejetee',
            self::DIRECTORY_ACCEPTED => 'Acceptee',
            self::DIRECTORY_REJECTED => 'Rejetee',
            self::FLOW_ADMISSIBLE => 'Recevable',
            self::FLOW_INADMISSIBLE => 'Irrecevable',
            self::ACKNOWLEDGEMENT_REJECTED => 'Rejete',
        };
    }

    /**
     * Get the matching status code.
     * Business meaning: CDAR status category for the lifecycle step.
     */
    public function statusCode(): StatusCode
    {
        return match ($this) {
            self::SUBMITTED => StatusCode::SUBMITTED,
            self::EMITTED_BY_PLATFORM => StatusCode::SUBMITTED,
            self::RECEIVED => StatusCode::RECEIVED,
            self::MADE_AVAILABLE => StatusCode::MADE_AVAILABLE,
            self::TAKEN_IN_CHARGE => StatusCode::TAKEN_IN_CHARGE,
            self::APPROVED => StatusCode::APPROVED,
            self::PARTIALLY_APPROVED => StatusCode::APPROVED,
            self::IN_DISPUTE => StatusCode::IN_DISPUTE,
            self::SUSPENDED => StatusCode::IN_DISPUTE,
            self::COMPLETED => StatusCode::APPROVED,
            self::REFUSED => StatusCode::REJECTED,
            self::PAYMENT_TRANSMITTED => StatusCode::PAYMENT_EVENT,
            self::PAID => StatusCode::PAYMENT_EVENT,
            self::REJECTED => StatusCode::REJECTED,
            self::FLUX1_SUBMITTED => StatusCode::SUBMITTED,
            self::FLUX1_REJECTED => StatusCode::REJECTED,
            self::EREPORTING_SUBMITTED => StatusCode::SUBMITTED,
            self::EREPORTING_REJECTED => StatusCode::REJECTED,
            self::DIRECTORY_ACCEPTED => StatusCode::APPROVED,
            self::DIRECTORY_REJECTED => StatusCode::REJECTED,
            self::FLOW_ADMISSIBLE => StatusCode::APPROVED,
            self::FLOW_INADMISSIBLE => StatusCode::REJECTED,
            self::ACKNOWLEDGEMENT_REJECTED => StatusCode::REJECTED,
        };
    }
}
