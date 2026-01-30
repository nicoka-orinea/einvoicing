<?php
namespace Einvoicing\Cdar\Enums;

enum StatusCode: int
{
    case SUBMITTED = 10;
    case RECEIVED = 43;
    case TAKEN_IN_CHARGE = 45;
    case IN_DISPUTE = 46;
    case PAYMENT_EVENT = 47;
    case MADE_AVAILABLE = 48;
    case APPROVED = 1;

    public function label(): string
    {
        return match ($this) {
            self::SUBMITTED => 'Submitted',
            self::RECEIVED => 'Received',
            self::TAKEN_IN_CHARGE => 'Taken in charge',
            self::IN_DISPUTE => 'In dispute',
            self::PAYMENT_EVENT => 'Payment event',
            self::MADE_AVAILABLE => 'Made available',
            self::APPROVED => 'Approved',
        };
    }
}
