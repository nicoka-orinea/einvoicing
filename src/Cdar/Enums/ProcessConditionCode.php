<?php
namespace Einvoicing\Cdar\Enums;

enum ProcessConditionCode: int
{
    case SUBMITTED = 200;
    case RECEIVED = 202;
    case MADE_AVAILABLE = 203;
    case TAKEN_IN_CHARGE = 204;
    case APPROVED = 205;
    case IN_DISPUTE = 207;
    case PAYMENT_TRANSMITTED = 211;
    case PAID = 212;

    public function label(): string
    {
        return match ($this) {
            self::SUBMITTED => 'Submitted',
            self::RECEIVED => 'Received',
            self::MADE_AVAILABLE => 'Made available',
            self::TAKEN_IN_CHARGE => 'Taken in charge',
            self::APPROVED => 'Approved',
            self::IN_DISPUTE => 'In dispute',
            self::PAYMENT_TRANSMITTED => 'Payment transmitted',
            self::PAID => 'Paid',
        };
    }

    public function xmlLabel(): string
    {
        return match ($this) {
            self::SUBMITTED => 'Deposee',
            self::RECEIVED => 'Recue',
            self::MADE_AVAILABLE => 'Mise_a_disposition',
            self::TAKEN_IN_CHARGE => 'Prise_en_charge',
            self::APPROVED => 'Approuvee',
            self::IN_DISPUTE => 'En_litige',
            self::PAYMENT_TRANSMITTED => 'Paiement_transmis',
            self::PAID => 'Encaissee',
        };
    }

    public function statusCode(): StatusCode
    {
        return match ($this) {
            self::SUBMITTED => StatusCode::SUBMITTED,
            self::RECEIVED => StatusCode::RECEIVED,
            self::MADE_AVAILABLE => StatusCode::MADE_AVAILABLE,
            self::TAKEN_IN_CHARGE => StatusCode::TAKEN_IN_CHARGE,
            self::APPROVED => StatusCode::APPROVED,
            self::IN_DISPUTE => StatusCode::IN_DISPUTE,
            self::PAYMENT_TRANSMITTED => StatusCode::PAYMENT_EVENT,
            self::PAID => StatusCode::PAYMENT_EVENT,
        };
    }
}
