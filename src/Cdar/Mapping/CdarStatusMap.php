<?php
namespace Einvoicing\Cdar\Mapping;

use Einvoicing\Cdar\Enums\ProcessConditionCode;
use Einvoicing\Cdar\Enums\StatusCode;

/**
 * CDAR mapping helper for process conditions and statuses.
 */
class CdarStatusMap
{
    /**
     * Get all CDAR status definitions.
     * @return array<int, CdarStatusDefinition>
     */
    public static function all(): array
    {
        return [
            ProcessConditionCode::SUBMITTED->value => self::define(ProcessConditionCode::SUBMITTED),
            ProcessConditionCode::EMITTED_BY_PLATFORM->value => self::define(ProcessConditionCode::EMITTED_BY_PLATFORM),
            ProcessConditionCode::RECEIVED->value => self::define(ProcessConditionCode::RECEIVED),
            ProcessConditionCode::MADE_AVAILABLE->value => self::define(ProcessConditionCode::MADE_AVAILABLE),
            ProcessConditionCode::TAKEN_IN_CHARGE->value => self::define(ProcessConditionCode::TAKEN_IN_CHARGE),
            ProcessConditionCode::APPROVED->value => self::define(ProcessConditionCode::APPROVED),
            ProcessConditionCode::IN_DISPUTE->value => self::define(ProcessConditionCode::IN_DISPUTE),
            ProcessConditionCode::PAYMENT_TRANSMITTED->value => self::define(ProcessConditionCode::PAYMENT_TRANSMITTED),
            ProcessConditionCode::PAID->value => self::define(ProcessConditionCode::PAID),
            ProcessConditionCode::REJECTED->value => self::define(ProcessConditionCode::REJECTED),
        ];
    }

    /**
     * Get the mapping definition for a process condition code.
     */
    public static function forProcessConditionCode(ProcessConditionCode|int $code): ?CdarStatusDefinition
    {
        $code = $code instanceof ProcessConditionCode ? $code->value : $code;
        return self::all()[$code] ?? null;
    }

    /**
     * Get all definitions matching a status code.
     * @return CdarStatusDefinition[]
     */
    public static function forStatusCode(StatusCode|int $statusCode): array
    {
        $statusCode = $statusCode instanceof StatusCode ? $statusCode->value : $statusCode;
        $matches = [];
        foreach (self::all() as $definition) {
            if ($definition->getStatusCode()->value === $statusCode) {
                $matches[] = $definition;
            }
        }
        return $matches;
    }

    private static function define(ProcessConditionCode $code): CdarStatusDefinition
    {
        return new CdarStatusDefinition(
            $code,
            $code->statusCode(),
            $code->label(),
            $code->xmlLabel()
        );
    }
}
