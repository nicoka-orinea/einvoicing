<?php

namespace Einvoicing\Flux10;

/**
 * Emitter of a Flux 10 transmission — TG-3.
 *
 * The PPF only accepts transmissions emitted by an accredited platform (plateforme
 * agréée), identified by its 4-character matricule under scheme 0238 (G6.22) and
 * declaring the role WK (G7.51). Both are properties of the platform, not of the
 * declared invoices, so they are fixed here rather than derived.
 *
 * This is distinct from the {@see Issuer}, which identifies the declarant (the taxable
 * person) by its SIREN.
 */
class Sender extends Party
{
    /** Identifier scheme for an accredited platform — TT-7, G6.22 */
    public const SCHEME_ID = '0238';

    /** Role code for an accredited platform, from UNCL 3035 — TT-10, G7.51 */
    public const ROLE_CODE = 'WK';

    /**
     * Get the platform matricule — TT-8.
     */
    public function getMatricule(): ?string
    {
        return $this->getSiren();
    }

    /**
     * Set the platform matricule (4 characters) — TT-8, G6.22.
     */
    public function setMatricule(?string $matricule): self
    {
        return $this->setSiren($matricule);
    }

    /**
     * Always 0238 — TT-7, G6.22. Any value set through {@see Party::setSchemeId()} is
     * ignored: an accredited platform has no other scheme.
     */
    public function getSchemeId(): ?string
    {
        return self::SCHEME_ID;
    }

    /**
     * Always WK — TT-10, G7.51.
     */
    public function getRoleCode(): string
    {
        return self::ROLE_CODE;
    }
}
