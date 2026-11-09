<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Client\DTO;

use UnexpectedValueException;

readonly class SessionDTO
{
    public function __construct(public string $status)
    {
    }

    public static function from(array $record): self
    {
        if (!is_string(value: $record['status'] ?? null) || empty($record['status'])) {
            throw new UnexpectedValueException(message: 'Invalid indexing response.');
        }
        return new self(status: $record['status']);
    }
}
