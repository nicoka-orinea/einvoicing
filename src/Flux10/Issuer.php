<?php

namespace Einvoicing\Flux10;

class Issuer extends Party
{
    /**
     * Issuer role code (SE or BY).
     * @var IssuerRoleCode|null
     */
    protected ?IssuerRoleCode $roleCode = null;

    /**
     * Get issuer role code.
     */
    public function getRoleCode(): ?IssuerRoleCode
    {
        return $this->roleCode;
    }

    /**
     * Set issuer role code.
     */
    public function setRoleCode(?IssuerRoleCode $roleCode): self
    {
        $this->roleCode = $roleCode;
        return $this;
    }
}
