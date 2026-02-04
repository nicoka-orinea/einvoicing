<?php
namespace Einvoicing\Cdar\Enums;

/**
 * CDAR status codes from acknowledgement document.
 */
enum StatusCode: int
{
    case SUBMITTED = 10;
    case RECEIVED = 43;
    case TAKEN_IN_CHARGE = 45;
    case IN_DISPUTE = 46;
    case PAYMENT_EVENT = 47;
    case MADE_AVAILABLE = 48;
    case APPROVED = 1;
    case REJECTED = 8;

    /**
     * Get an English label for UI/logs.
     * Business meaning: human-readable status category.
     */
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
            self::REJECTED => 'Rejected',
        };
    }
}
