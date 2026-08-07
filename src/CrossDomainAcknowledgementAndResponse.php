<?php
namespace Einvoicing;

use Einvoicing\Cdar\AcknowledgementDocument;
use Einvoicing\Cdar\DocumentContext;
use Einvoicing\Cdar\ExchangedDocument;
use Einvoicing\Exceptions\ValidationException;
use function in_array;

/**
 * Root CDAR container.
 */
class CrossDomainAcknowledgementAndResponse
{
    /** Statuses that rule G7.08 requires a reason code for */
    private const REASON_CODE_REQUIRED_STATUSES = ['210', '213', '251', '301', '401', '501', '601'];

    private ?DocumentContext $documentContext = null;
    private ?ExchangedDocument $exchangedDocument = null;

    /** @var AcknowledgementDocument[] */
    private array $acknowledgementDocuments = [];

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
     * Get every acknowledgement document section.
     * Business meaning: status update payloads for the referenced documents.
     * @return AcknowledgementDocument[]
     */
    public function getAcknowledgementDocuments(): array
    {
        return $this->acknowledgementDocuments;
    }

    /**
     * Add an acknowledgement document section.
     * Business meaning: status update payload for a referenced document.
     */
    public function addAcknowledgementDocument(AcknowledgementDocument $document): self
    {
        $this->acknowledgementDocuments[] = $document;
        return $this;
    }

    /**
     * Get the first acknowledgement document section.
     * @deprecated Use getAcknowledgementDocuments(), a CDAR may carry several
     */
    public function getAcknowledgementDocument(): ?AcknowledgementDocument
    {
        return $this->acknowledgementDocuments[0] ?? null;
    }

    /**
     * Replace every acknowledgement document section with a single one.
     * @deprecated Use addAcknowledgementDocument(), a CDAR may carry several
     */
    public function setAcknowledgementDocument(?AcknowledgementDocument $acknowledgementDocument): self
    {
        $this->acknowledgementDocuments = ($acknowledgementDocument === null) ? [] : [$acknowledgementDocument];
        return $this;
    }

    /**
     * Validate the CDAR document
     *
     * Only the constraints the XSD cannot express are checked, and only the ones
     * the PPF specification states: this is not a full conformance check. Call it
     * explicitly, the writer never does.
     * @throws ValidationException if failed to pass validation
     */
    public function validate(): void
    {
        $exchanged = $this->exchangedDocument;
        if ($exchanged === null) {
            throw new ValidationException("A CDAR document shall have an ExchangedDocument", 'CDAR-EXCHANGED');
        }
        if ($exchanged->getId() === null) {
            throw new ValidationException("The ExchangedDocument shall have an ID", 'CDAR-EXCHANGED-ID');
        }
        if ($exchanged->getIssueDateTime() === null) {
            throw new ValidationException(
                "The ExchangedDocument shall have an IssueDateTime",
                'CDAR-EXCHANGED-DATE'
            );
        }
        if ($this->acknowledgementDocuments === []) {
            throw new ValidationException(
                "A CDAR document shall have at least one AcknowledgementDocument",
                'CDAR-ACK'
            );
        }

        foreach ($this->acknowledgementDocuments as $document) {
            // The type code identifies the acknowledged object (rule G7.15)
            if ($document->getTypeCode() === null) {
                throw new ValidationException(
                    "Each AcknowledgementDocument shall have a TypeCode",
                    'CDAR-ACK-TYPE'
                );
            }

            // G7.08: a negative status has to carry a reason code
            $reference = $document->getReference();
            if ($reference === null) {
                continue;
            }
            foreach ($reference->getSpecifiedDocumentStatuses() as $status) {
                $code = $status->getProcessConditionCode();
                if ($code === null || !in_array($code, self::REASON_CODE_REQUIRED_STATUSES, true)) {
                    continue;
                }
                if ($status->getReasonCode() === null) {
                    throw new ValidationException(
                        "Le statut $code doit comporter un code motif (ReasonCode)",
                        'G7.08'
                    );
                }
            }
        }
    }
}
