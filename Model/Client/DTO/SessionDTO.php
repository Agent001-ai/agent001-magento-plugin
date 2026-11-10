<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Client\DTO;

use UnexpectedValueException;

class SessionDTO
{
    /**
     * @param string $status
     * @param string $error
     */
    public function __construct(public readonly string $status, public readonly string $error)
    {
    }

    /**
     * Create the DTO from an API record.
     *
     * @param array $record
     */
    public static function from(array $record): self
    {
        if (!is_string(value: $record['status'] ?? null) || empty($record['status'])) {
            throw new UnexpectedValueException(message: 'Invalid indexing response.');
        }
        $error = is_string(value: $record['error'] ?? null)
            ? mb_substr(string: $record['error'], start: 0, length: 500)
            : '';
        return new self(status: $record['status'], error: $error);
    }

    /**
     * Check whether indexing finished successfully.
     */
    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }

    /**
     * Check whether indexing failed.
     */
    public function failed(): bool
    {
        return $this->status === 'failed';
    }
}
