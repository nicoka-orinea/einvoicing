<?php

namespace Einvoicing\Flux10;

/**
 * Delivery address — TG-19 on the invoice, TG-42 on a line.
 *
 * G6.30 makes line one, city, postal code and country mandatory from 01/09/2027 when
 * the delivery address differs from the billing one, except for services.
 */
class Location
{
    /** @var string|null Address line 1 (TT-103, TT-303) */
    protected ?string $lineOne = null;

    /** @var string|null Address line 2 (TT-104) */
    protected ?string $lineTwo = null;

    /** @var string|null Address line 3 (TT-105) */
    protected ?string $lineThree = null;

    /** @var string|null City (TT-106, TT-304) */
    protected ?string $cityName = null;

    /** @var string|null Postal code (TT-107, TT-305) */
    protected ?string $postalZone = null;

    /** @var string|null Country subdivision (TT-108, TT-306) */
    protected ?string $countrySubentity = null;

    /** @var string|null ISO 3166 alpha-2 country code (TT-44, TT-307) */
    protected ?string $countryId = null;

    /**
     * Get the main address line (TT-103, TT-303).
     */
    public function getLineOne(): ?string
    {
        return $this->lineOne;
    }

    /**
     * Set the main address line (TT-103, TT-303).
     */
    public function setLineOne(?string $lineOne): self
    {
        $this->lineOne = $lineOne;
        return $this;
    }

    /**
     * Get the second address line (TT-104).
     */
    public function getLineTwo(): ?string
    {
        return $this->lineTwo;
    }

    /**
     * Set the second address line (TT-104).
     */
    public function setLineTwo(?string $lineTwo): self
    {
        $this->lineTwo = $lineTwo;
        return $this;
    }

    /**
     * Get the third address line (TT-105).
     */
    public function getLineThree(): ?string
    {
        return $this->lineThree;
    }

    /**
     * Set the third address line (TT-105).
     */
    public function setLineThree(?string $lineThree): self
    {
        $this->lineThree = $lineThree;
        return $this;
    }

    /**
     * Get the city (TT-106, TT-304).
     */
    public function getCityName(): ?string
    {
        return $this->cityName;
    }

    /**
     * Set the city (TT-106, TT-304).
     */
    public function setCityName(?string $cityName): self
    {
        $this->cityName = $cityName;
        return $this;
    }

    /**
     * Get the postal code (TT-107, TT-305).
     */
    public function getPostalZone(): ?string
    {
        return $this->postalZone;
    }

    /**
     * Set the postal code (TT-107, TT-305).
     */
    public function setPostalZone(?string $postalZone): self
    {
        $this->postalZone = $postalZone;
        return $this;
    }

    /**
     * Get the country subdivision (TT-108, TT-306).
     */
    public function getCountrySubentity(): ?string
    {
        return $this->countrySubentity;
    }

    /**
     * Set the country subdivision (TT-108, TT-306).
     */
    public function setCountrySubentity(?string $countrySubentity): self
    {
        $this->countrySubentity = $countrySubentity;
        return $this;
    }

    /**
     * Get the ISO 3166 alpha-2 country code (TT-44, TT-307).
     */
    public function getCountryId(): ?string
    {
        return $this->countryId;
    }

    /**
     * Set the ISO 3166 alpha-2 country code (G2.01).
     */
    public function setCountryId(?string $countryId): self
    {
        $this->countryId = $countryId;
        return $this;
    }

    /**
     * Whether any address element is filled in.
     */
    public function isEmpty(): bool
    {
        return $this->lineOne === null && $this->lineTwo === null && $this->lineThree === null
            && $this->cityName === null && $this->postalZone === null
            && $this->countrySubentity === null && $this->countryId === null;
    }
}
