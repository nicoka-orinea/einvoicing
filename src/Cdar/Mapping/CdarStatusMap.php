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
        $definitions = [];
        foreach (ProcessConditionCode::cases() as $case) {
            $definitions[$case->value] = self::define($case);
        }
        return $definitions;
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
