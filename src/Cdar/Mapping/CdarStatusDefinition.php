<?php
namespace Einvoicing\Cdar\Mapping;

use Einvoicing\Cdar\Enums\ProcessConditionCode;
use Einvoicing\Cdar\Enums\StatusCode;

/**
 * Immutable CDAR mapping definition.
 */
class CdarStatusDefinition
{
    private ProcessConditionCode $processConditionCode;
    private StatusCode $statusCode;
    private string $label;
    private string $xmlLabel;

    /**
     * Create a status definition.
     */
    public function __construct(
        ProcessConditionCode $processConditionCode,
        StatusCode $statusCode,
        string $label,
        string $xmlLabel
    ) {
        $this->processConditionCode = $processConditionCode;
        $this->statusCode = $statusCode;
        $this->label = $label;
        $this->xmlLabel = $xmlLabel;
    }

    /**
     * Get the process condition code.
     * Business meaning: lifecycle step for the invoice.
     */
    public function getProcessConditionCode(): ProcessConditionCode
    {
        return $this->processConditionCode;
    }

    /**
     * Get the status code.
     * Business meaning: CDAR status category.
     */
    public function getStatusCode(): StatusCode
    {
        return $this->statusCode;
    }

    /**
     * Get the English label.
     * Business meaning: developer-friendly label for UI/logs.
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * Get the XML label.
     * Business meaning: CDAR XML label for the process condition.
     */
    public function getXmlLabel(): string
    {
        return $this->xmlLabel;
    }
}
