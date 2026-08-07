<?php

namespace Einvoicing\Flux10;

use DateTimeInterface;

/**
 * Delivery information — TG-17 on the invoice, TG-41 on a line.
 */
class Delivery
{
    /**
     * Delivery name, on a line only (TT-302).
     * @var string|null
     */
    protected ?string $name = null;

    /**
     * Actual delivery date (TT-41).
     * @var DateTimeInterface|string|null
     */
    protected DateTimeInterface|string|null $date = null;

    /**
     * Delivery address (TG-19, TG-42).
     * @var Location|null
     */
    protected ?Location $location = null;

    /**
     * Get the delivery location name (TT-302).
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Set the delivery location name (TT-302).
     */
    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get the actual delivery date (TT-41).
     */
    public function getDate(): DateTimeInterface|string|null
    {
        return $this->date;
    }

    /**
     * @param DateTimeInterface|string|null $date
     */
    public function setDate(DateTimeInterface|string|null $date): self
    {
        $this->date = $date;
        return $this;
    }

    /**
     * Get the delivery address (TG-19, TG-42).
     */
    public function getLocation(): ?Location
    {
        return $this->location;
    }

    /**
     * Set the delivery address (TG-19, TG-42).
     */
    public function setLocation(?Location $location): self
    {
        $this->location = $location;
        return $this;
    }
}
