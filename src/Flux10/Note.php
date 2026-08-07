<?php

namespace Einvoicing\Flux10;

/**
 * Free-text note — TG-9 on the invoice, TT-61 on a line.
 *
 * The subject is a UNTDID 4451 code; G1.52 singles out AAB (settlement discount), TXD
 * (VAT group member) and BLU (eco-participation).
 */
class Note
{
    /**
     * Subject code (TT-26, TT-61-0).
     * @var string|null
     */
    protected ?string $subject = null;

    /**
     * Note text (TT-27, TT-61-1).
     * @var string|null
     */
    protected ?string $content = null;

    /**
     * @param string|null $content Note text
     * @param string|null $subject Subject code, from UNTDID 4451
     */
    public function __construct(?string $content = null, ?string $subject = null)
    {
        $this->content = $content;
        $this->subject = $subject;
    }

    /**
     * Get the subject code.
     */
    public function getSubject(): ?string
    {
        return $this->subject;
    }

    /**
     * Set the subject code (UNTDID 4451).
     */
    public function setSubject(?string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Get the note text.
     */
    public function getContent(): ?string
    {
        return $this->content;
    }

    /**
     * Set the note text.
     */
    public function setContent(?string $content): self
    {
        $this->content = $content;
        return $this;
    }
}
