<?php
namespace Einvoicing;

use Einvoicing\Cdar\AcknowledgementDocument;
use Einvoicing\Cdar\DocumentContext;
use Einvoicing\Cdar\ExchangedDocument;

/**
 * Root CDAR container.
 */
class CrossDomainAcknowledgementAndResponse
{
    private ?DocumentContext $documentContext = null;
    private ?ExchangedDocument $exchangedDocument = null;
    private ?AcknowledgementDocument $acknowledgementDocument = null;

    /**
     * Get the document context section.
     * Business meaning: CDAR profile and business process identifiers.
     */
    public function getDocumentContext(): ?DocumentContext
    {
        return $this->documentContext;
    }

    /**
     * Set the document context section.
     * Business meaning: CDAR profile and business process identifiers.
     */
    public function setDocumentContext(?DocumentContext $documentContext): self
    {
        $this->documentContext = $documentContext;
        return $this;
    }

    /**
     * Get the exchanged document section.
     * Business meaning: CDAR message metadata and parties.
     */
    public function getExchangedDocument(): ?ExchangedDocument
    {
        return $this->exchangedDocument;
    }

    /**
     * Set the exchanged document section.
     * Business meaning: CDAR message metadata and parties.
     */
    public function setExchangedDocument(?ExchangedDocument $exchangedDocument): self
    {
        $this->exchangedDocument = $exchangedDocument;
        return $this;
    }

    /**
     * Get the acknowledgement document section.
     * Business meaning: status update payload for the referenced invoice.
     */
    public function getAcknowledgementDocument(): ?AcknowledgementDocument
    {
        return $this->acknowledgementDocument;
    }

    /**
     * Set the acknowledgement document section.
     * Business meaning: status update payload for the referenced invoice.
     */
    public function setAcknowledgementDocument(?AcknowledgementDocument $acknowledgementDocument): self
    {
        $this->acknowledgementDocument = $acknowledgementDocument;
        return $this;
    }
}
