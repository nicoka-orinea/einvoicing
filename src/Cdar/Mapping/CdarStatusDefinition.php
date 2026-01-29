<?php
namespace Einvoicing\Cdar\Mapping;

use Einvoicing\Cdar\Enums\ProcessConditionCode;
use Einvoicing\Cdar\Enums\StatusCode;

class CdarStatusDefinition
{
    private ProcessConditionCode $processConditionCode;
    private StatusCode $statusCode;
    private string $label;
    private string $xmlLabel;

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

    public function getProcessConditionCode(): ProcessConditionCode
    {
        return $this->processConditionCode;
    }

    public function getStatusCode(): StatusCode
    {
        return $this->statusCode;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getXmlLabel(): string
    {
        return $this->xmlLabel;
    }
}
