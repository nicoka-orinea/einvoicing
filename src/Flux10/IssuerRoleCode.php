<?php

namespace Einvoicing\Flux10;

enum IssuerRoleCode: string
{
    case SELLER = 'SE';
    case BUYER = 'BY';
}
