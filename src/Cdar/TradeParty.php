<?php
namespace Einvoicing\Cdar;

/**
 * CDAR trade party (sender, issuer, recipient).
 */
class TradeParty
{
    private ?string $globalId = null;
    private ?string $globalIdScheme = null;
    private ?string $name = null;
    private ?string $roleCode = null;
    private ?string $uri = null;
    private ?string $uriScheme = null;

    /**
     * Get the global identifier value.
     * Business meaning: party identifier in a given scheme (e.g. SIRET, GLN).
     */
    public function getGlobalId(): ?string
    {
        return $this->globalId;
    }

    /**
     * Set the global identifier value.
     * Business meaning: party identifier in a given scheme (e.g. SIRET, GLN).
     */
    public function setGlobalId(?string $globalId): self
    {
        $this->globalId = $globalId;
        return $this;
    }

    /**
     * Get the global identifier scheme.
     * Business meaning: identifier scheme code (e.g. 0002, 0238).
     */
    public function getGlobalIdScheme(): ?string
    {
        return $this->globalIdScheme;
    }

    /**
     * Set the global identifier scheme.
     * Business meaning: identifier scheme code (e.g. 0002, 0238).
     */
    public function setGlobalIdScheme(?string $globalIdScheme): self
    {
        $this->globalIdScheme = $globalIdScheme;
        return $this;
    }

    /**
     * Get the party name.
     * Business meaning: legal or trading name.
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Set the party name.
     * Business meaning: legal or trading name.
     */
    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get the role code.
     * Business meaning: party role in the CDAR exchange (e.g. SE, BY, WK).
     */
    public function getRoleCode(): ?string
    {
        return $this->roleCode;
    }

    /**
     * Set the role code.
     * Business meaning: party role in the CDAR exchange (e.g. SE, BY, WK).
     */
    public function setRoleCode(?string $roleCode): self
    {
        $this->roleCode = $roleCode;
        return $this;
    }

    /**
     * Get the URI (electronic address).
     * Business meaning: electronic address used for status exchange.
     */
    public function getUri(): ?string
    {
        return $this->uri;
    }

    /**
     * Set the URI (electronic address).
     * Business meaning: electronic address used for status exchange.
     */
    public function setUri(?string $uri): self
    {
        $this->uri = $uri;
        return $this;
    }

    /**
     * Get the URI scheme.
     * Business meaning: URI identifier scheme code.
     */
    public function getUriScheme(): ?string
    {
        return $this->uriScheme;
    }

    /**
     * Set the URI scheme.
     * Business meaning: URI identifier scheme code.
     */
    public function setUriScheme(?string $uriScheme): self
    {
        $this->uriScheme = $uriScheme;
        return $this;
    }
}
