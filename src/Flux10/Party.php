<?php

namespace Einvoicing\Flux10;

class Party
{
    /**
     * SIREN or SIRET identifier.
     * @var string|null
     */
    protected $siren = null;

    /**
     * Identifier scheme (XSD attribute `schemeId`).
     * @var string|null
     */
    protected $schemeId = null;

    /**
     * Party name.
     * @var string|null
     */
    protected $name = null;

    /**
     * VAT identifier.
     * @var string|null
     */
    protected $vatId = null;

	    /**
	     * Universal communication URI (XSD ReportDocument/{Sender|Issuer}/URIUniversalCommunication/URIID).
	     * @var string|null
	     */
	    protected $uriUniversalCommunication = null;

    /**
     * Get SIREN/SIRET identifier.
     */
    public function getSiren(): ?string
    {
        return $this->siren;
    }

    /**
     * Set SIREN/SIRET identifier.
     */
    public function setSiren(?string $siren): self
    {
        $this->siren = $siren;
        return $this;
    }

    /**
     * Get identifier scheme.
     */
    public function getSchemeId(): ?string
    {
        return $this->schemeId;
    }

    /**
     * Set identifier scheme.
     */
    public function setSchemeId(?string $schemeId): self
    {
        $this->schemeId = $schemeId;
        return $this;
    }

    /**
     * Get party name.
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Set party name.
     */
    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get VAT ID.
     */
    public function getVatId(): ?string
    {
        return $this->vatId;
    }

    /**
     * Set VAT ID.
     */
    public function setVatId(?string $vatId): self
    {
        $this->vatId = $vatId;
        return $this;
    }

    /**
     * Get universal communication URI.
     */
    public function getUriUniversalCommunication(): ?string
    {
        return $this->uriUniversalCommunication;
    }

    /**
     * Set universal communication URI.
     */
    public function setUriUniversalCommunication(?string $uriUniversalCommunication): self
    {
        $this->uriUniversalCommunication = $uriUniversalCommunication;
        return $this;
    }
}
