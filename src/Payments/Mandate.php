<?php
namespace Einvoicing\Payments;

class Mandate {
    protected $reference = null;
    protected $account = null;
    protected $creditorIdentifier = null;

    /**
     * Get mandate reference ID
     * @return string|null Mandate reference ID
     */
    public function getReference(): ?string {
        return $this->reference;
    }


    /**
     * Set mandate reference ID
     * @param  string|null $reference Mandate reference ID
     * @return self                   Mandate instance
     */
    public function setReference(?string $reference): self {
        $this->reference = $reference;
        return $this;
    }


    /**
     * Get debited account ID
     * @return string|null Debited account ID
     */
    public function getAccount(): ?string {
        return $this->account;
    }


    /**
     * Set debited account ID
     * @param  string|null $account Debited account ID
     * @return self                 Mandate instance
     */
    public function setAccount(?string $account): self {
        $this->account = $account;
        return $this;
    }


    /**
     * Get bank assigned creditor identifier (BT-90)
     *
     * The SEPA creditor identifier ("Identifiant Créancier SEPA"). Only written
     * to CII documents, as ram:CreditorReferenceID: EN 16931 maps BT-90 to
     * cac:PaymentMeans/cac:PayeeFinancialAccount in UBL, which the payment
     * mandate does not carry.
     * @return string|null Bank assigned creditor identifier
     */
    public function getCreditorIdentifier(): ?string {
        return $this->creditorIdentifier;
    }


    /**
     * Set bank assigned creditor identifier (BT-90)
     * @param  string|null $creditorIdentifier Bank assigned creditor identifier
     * @return self                            Mandate instance
     */
    public function setCreditorIdentifier(?string $creditorIdentifier): self {
        $this->creditorIdentifier = $creditorIdentifier;
        return $this;
    }
}
