<?php
class GoCardlessException extends RuntimeException {
    private $status;
    public function __construct(string $message, int $status = 0) { parent::__construct($message); $this->status = $status; }
    public function status(): int { return $this->status; }
    public function isConsentError(): bool { return $this->status === 401; }
}
