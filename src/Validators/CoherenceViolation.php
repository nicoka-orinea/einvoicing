<?php
namespace Einvoicing\Validators;

final class CoherenceViolation {
    public function __construct(
        public readonly string $rule,
        public readonly string $message,
    ) {
    }

    public function __toString(): string {
        return $this->rule . ': ' . $this->message;
    }
}
