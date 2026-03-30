<?php
namespace Einvoicing\Models;

class DocumentNote {
    /**
     * Note content
     * @var string
     */
    protected $content;

    /**
     * Note subject code
     * @var string|null
     */
    protected $subjectCode = null;

    public function __construct(string $content, ?string $subjectCode=null) {
        $this->content = $content;
        $this->subjectCode = $subjectCode;
    }

    public function getContent(): string {
        return $this->content;
    }

    public function setContent(string $content): self {
        $this->content = $content;
        return $this;
    }

    public function getSubjectCode(): ?string {
        return $this->subjectCode;
    }

    public function setSubjectCode(?string $subjectCode): self {
        $this->subjectCode = $subjectCode;
        return $this;
    }
}
