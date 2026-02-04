<?php

namespace Einvoicing\Flux10;

class Issuer extends Party
{
    /**
     * Issuer role code (SE or BY).
     * @var IssuerRoleCode|null
     */
    public ?IssuerRoleCode $roleCode = null;
}
