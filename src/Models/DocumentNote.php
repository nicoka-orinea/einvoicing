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

    /**
     * Create a document note.
     *
     * @param string      $content     Note content
     * @param string|null $subjectCode Optional note subject code
     */
    public function __construct(string $content, ?string $subjectCode=null) {
        $this->content = $content;
        $this->subjectCode = $subjectCode;
    }

    /**
     * Get note content.
     */
    public function getContent(): string {
        return $this->content;
    }

    /**
     * Set note content.
     */
    public function setContent(string $content): self {
        $this->content = $content;
        return $this;
    }

    /**
     * Get note subject code.
     */
    public function getSubjectCode(): ?string {
        return $this->subjectCode;
    }

    /**
     * Set note subject code.
     */
    public function setSubjectCode(?string $subjectCode): self {
        $this->subjectCode = $subjectCode;
        return $this;
    }
}
