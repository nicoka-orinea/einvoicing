<?php
namespace Einvoicing\Cdar;

class TradeParty
{
    private ?string $globalId = null;
    private ?string $globalIdScheme = null;
    private ?string $name = null;
    private ?string $roleCode = null;
    private ?string $uri = null;
    private ?string $uriScheme = null;

    public function getGlobalId(): ?string
    {
        return $this->globalId;
    }

    public function setGlobalId(?string $globalId): self
    {
        $this->globalId = $globalId;
        return $this;
    }

    public function getGlobalIdScheme(): ?string
    {
        return $this->globalIdScheme;
    }

    public function setGlobalIdScheme(?string $globalIdScheme): self
    {
        $this->globalIdScheme = $globalIdScheme;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getRoleCode(): ?string
    {
        return $this->roleCode;
    }

    public function setRoleCode(?string $roleCode): self
    {
        $this->roleCode = $roleCode;
        return $this;
    }

    public function getUri(): ?string
    {
        return $this->uri;
    }

    public function setUri(?string $uri): self
    {
        $this->uri = $uri;
        return $this;
    }

    public function getUriScheme(): ?string
    {
        return $this->uriScheme;
    }

    public function setUriScheme(?string $uriScheme): self
    {
        $this->uriScheme = $uriScheme;
        return $this;
    }
}
