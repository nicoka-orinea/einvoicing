<?php
namespace Einvoicing\Cdar;

use DateTime;

/**
 * CDAR exchanged document metadata and parties.
 */
class ExchangedDocument
{
    private ?string $id = null;
    private ?string $name = null;
    private ?DateTime $issueDateTime = null;
    private ?TradeParty $sender = null;
    private ?TradeParty $issuer = null;
    /** @var TradeParty[] */
    private array $recipients = [];

    /**
     * Get the document identifier.
     * Business meaning: CDAR message identifier.
     */
    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * Set the document identifier.
     * Business meaning: CDAR message identifier.
     */
    public function setId(?string $id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Get the document name.
     * Business meaning: CDAR message name or filename hint.
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Set the document name.
     * Business meaning: CDAR message name or filename hint.
     */
    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get the document issue date-time.
     * Business meaning: CDAR creation timestamp.
     */
    public function getIssueDateTime(): ?DateTime
    {
        return $this->issueDateTime;
    }

    /**
     * Set the document issue date-time.
     * Business meaning: CDAR creation timestamp.
     */
    public function setIssueDateTime(?DateTime $issueDateTime): self
    {
        $this->issueDateTime = $issueDateTime;
        return $this;
    }

    /**
     * Get the sender party.
     * Business meaning: transport sender of the CDAR message.
     */
    public function getSender(): ?TradeParty
    {
        return $this->sender;
    }

    /**
     * Set the sender party.
     * Business meaning: transport sender of the CDAR message.
     */
    public function setSender(?TradeParty $sender): self
    {
        $this->sender = $sender;
        return $this;
    }

    /**
     * Get the issuer party.
     * Business meaning: business issuer of the CDAR message.
     */
    public function getIssuer(): ?TradeParty
    {
        return $this->issuer;
    }

    /**
     * Set the issuer party.
     * Business meaning: business issuer of the CDAR message.
     */
    public function setIssuer(?TradeParty $issuer): self
    {
        $this->issuer = $issuer;
        return $this;
    }

    /**
     * @return TradeParty[]
     * Business meaning: recipients for the CDAR status message.
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    /**
     * @param TradeParty[] $recipients
     * Business meaning: recipients for the CDAR status message.
     */
    public function setRecipients(array $recipients): self
    {
        $this->recipients = $recipients;
        return $this;
    }

    /**
     * Add a recipient party.
     * Business meaning: append a recipient for the CDAR status message.
     */
    public function addRecipient(TradeParty $recipient): self
    {
        $this->recipients[] = $recipient;
        return $this;
    }
}
