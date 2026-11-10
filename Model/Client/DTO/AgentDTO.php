<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Client\DTO;

use UnexpectedValueException;

class AgentDTO
{
    public const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    /**
     * @param string $id
     * @param string $name
     * @param string $embedToken
     * @param bool $active
     * @param list<string> $channels
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $embedToken,
        public readonly bool $active,
        public readonly array $channels,
    ) {
    }

    /**
     * Create the DTO from an API record.
     *
     * @param array $record
     */
    public static function from(array $record): self
    {
        if (!is_string(value: $record['id'] ?? null) || !is_string(value: $record['name'] ?? null)
            || !preg_match(pattern: self::UUID, subject: $record['id'])
            || !is_string(value: $record['embed_token'] ?? null) || !is_bool(value: $record['active'] ?? null)) {
            throw new UnexpectedValueException(message: 'Invalid agent response.');
        }
        $channels = is_array(value: $record['channels'] ?? null) ? $record['channels'] : [];
        return new self(
            id: $record['id'],
            name: $record['name'],
            embedToken: $record['embed_token'],
            active: $record['active'],
            channels: array_values(array: array_filter(array: $channels, callback: 'is_string')),
        );
    }

    /**
     * Create DTOs from API records.
     *
     * @param array $records
     */
    public static function fromArray(array $records): array
    {
        $agents = [];
        foreach ($records as $record) {
            if (!is_array(value: $record)) {
                throw new UnexpectedValueException(message: 'Invalid agent record.');
            }
            $agents[] = self::from(record: $record);
        }
        return $agents;
    }
}
