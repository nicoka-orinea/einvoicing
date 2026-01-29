<?php
namespace Einvoicing\Cdar;

use DateTime;

class ExchangedDocument
{
    private ?string $id = null;
    private ?string $name = null;
    private ?DateTime $issueDateTime = null;
    private ?TradeParty $sender = null;
    private ?TradeParty $issuer = null;
    /** @var TradeParty[] */
    private array $recipients = [];

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setId(?string $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getIssueDateTime(): ?DateTime
    {
        return $this->issueDateTime;
    }

    public function setIssueDateTime(?DateTime $issueDateTime): self
    {
        $this->issueDateTime = $issueDateTime;
        return $this;
    }

    public function getSender(): ?TradeParty
    {
        return $this->sender;
    }

    public function setSender(?TradeParty $sender): self
    {
        $this->sender = $sender;
        return $this;
    }

    public function getIssuer(): ?TradeParty
    {
        return $this->issuer;
    }

    public function setIssuer(?TradeParty $issuer): self
    {
        $this->issuer = $issuer;
        return $this;
    }

    /**
     * @return TradeParty[]
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    /**
     * @param TradeParty[] $recipients
     */
    public function setRecipients(array $recipients): self
    {
        $this->recipients = $recipients;
        return $this;
    }

    public function addRecipient(TradeParty $recipient): self
    {
        $this->recipients[] = $recipient;
        return $this;
    }
}
