<?php

namespace Einvoicing\Flux10\Enums;

// A code list declares every value the referential defines, used or not
// @phan-file-suppress PhanUnreferencedPublicClassConstant

/**
 * Transmission type — TT-4, G8.01.
 */
enum TransmissionTypeCode: string
{
    case INITIAL = 'IN';
    case RECTIFICATIVE = 'RE';

    /**
     * Get an English label for UI and logs.
     */
    public function label(): string
    {
        return match ($this) {
            self::INITIAL => 'Initial',
            self::RECTIFICATIVE => 'Rectificative',
        };
    }
}
