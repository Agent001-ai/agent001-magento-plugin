<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Client\DTO;

use UnexpectedValueException;

readonly class AgentDTO
{
    public const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    /**
     * @param list<string> $channels
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $embedToken,
        public bool $active,
        public array $channels,
    ) {
    }

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
