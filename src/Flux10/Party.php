<?php

namespace Einvoicing\Flux10;

class Party
{
    /**
     * SIREN or SIRET identifier.
     * @var string|null
     */
    public $siren = null;

    /**
     * Party name.
     * @var string|null
     */
    public $name = null;

    /**
     * VAT identifier.
     * @var string|null
     */
    public $vatId = null;
}
