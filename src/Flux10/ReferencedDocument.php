<?php

namespace Einvoicing\Flux10;

use DateTimeInterface;

/**
 * Reference to an earlier invoice — TG-11 in the header, TG-40 on a line.
 *
 * Required by G1.32: a corrective invoice references exactly one earlier invoice with
 * its date, a credit note at least one, either in the header or on every line.
 */
class ReferencedDocument
{
    /**
     * Identifier of the earlier invoice (TT-30, TT-300).
     * @var string|null
     */
    protected ?string $id = null;

    /**
     * Issue date of the earlier invoice (TT-31, TT-301).
     * @var DateTimeInterface|string|null
     */
    protected DateTimeInterface|string|null $issueDate = null;

    /**
     * @param DateTimeInterface|string|null $issueDate
     */
    public function __construct(?string $id = null, DateTimeInterface|string|null $issueDate = null)
    {
        $this->id = $id;
        $this->issueDate = $issueDate;
    }

    /**
     * Get the earlier invoice identifier.
     */
    public function getId(): ?string
    {
        return $this->id;
    }

    /**
     * Set the earlier invoice identifier.
     */
    public function setId(?string $id): self
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Get the earlier invoice issue date.
     */
    public function getIssueDate(): DateTimeInterface|string|null
    {
        return $this->issueDate;
    }

    /**
     * Set the earlier invoice issue date.
     *
     * @param DateTimeInterface|string|null $issueDate
     */
    public function setIssueDate(DateTimeInterface|string|null $issueDate): self
    {
        $this->issueDate = $issueDate;
        return $this;
    }
}
