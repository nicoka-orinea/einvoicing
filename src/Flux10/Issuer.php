<?php

namespace Einvoicing\Flux10;

use Einvoicing\Flux10\Enums\IssuerRoleCode;

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
     * Set issuer role code (TT-15, G7.52).
     *
     * @param IssuerRoleCode|string|null $roleCode `SE` or `BY` when a string
     */
    public function setRoleCode(IssuerRoleCode|string|null $roleCode): self
    {
        $this->roleCode = is_string($roleCode) ? IssuerRoleCode::from($roleCode) : $roleCode;
        return $this;
    }
}
