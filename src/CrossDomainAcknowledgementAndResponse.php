<?php
namespace Einvoicing;

use Einvoicing\Cdar\AcknowledgementDocument;
use Einvoicing\Cdar\DocumentContext;
use Einvoicing\Cdar\ExchangedDocument;

class CrossDomainAcknowledgementAndResponse
{
    private ?DocumentContext $documentContext = null;
    private ?ExchangedDocument $exchangedDocument = null;
    private ?AcknowledgementDocument $acknowledgementDocument = null;

    public function getDocumentContext(): ?DocumentContext
    {
        return $this->documentContext;
    }

    public function setDocumentContext(?DocumentContext $documentContext): self
    {
        $this->documentContext = $documentContext;
        return $this;
    }

    public function getExchangedDocument(): ?ExchangedDocument
    {
        return $this->exchangedDocument;
    }

    public function setExchangedDocument(?ExchangedDocument $exchangedDocument): self
    {
        $this->exchangedDocument = $exchangedDocument;
        return $this;
    }

    public function getAcknowledgementDocument(): ?AcknowledgementDocument
    {
        return $this->acknowledgementDocument;
    }

    public function setAcknowledgementDocument(?AcknowledgementDocument $acknowledgementDocument): self
    {
        $this->acknowledgementDocument = $acknowledgementDocument;
        return $this;
    }
}
