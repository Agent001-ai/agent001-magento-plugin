<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Client\DTO;

use UnexpectedValueException;

class ConnectionDTO
{
    public function __construct(public readonly string $apiKey, public readonly string $accountName)
    {
    }

    public static function from(array $record): self
    {
        if (!is_string(value: $record['api_key'] ?? null) || empty($record['api_key'])
            || preg_match(pattern: '/[\r\n]/', subject: $record['api_key'])) {
            throw new UnexpectedValueException(message: 'Invalid connection response.');
        }
        return new self(
            apiKey: $record['api_key'],
            accountName: is_string(value: $record['account_name'] ?? null) ? $record['account_name'] : ''
        );
    }
}
