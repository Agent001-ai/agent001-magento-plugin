<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Client\DTO;

use UnexpectedValueException;

class AgentStatusDTO
{
    /**
     * @param string $id
     * @param bool $active
     */
    public function __construct(public readonly string $id, public readonly bool $active)
    {
    }

    /**
     * Create the DTO from an API record.
     *
     * @param array $record
     */
    public static function from(array $record): self
    {
        if (!is_string(value: $record['agent_id'] ?? null) || !is_bool(value: $record['active'] ?? null)) {
            throw new UnexpectedValueException(message: 'Invalid agent status response.');
        }
        return new self(id: $record['agent_id'], active: $record['active']);
    }
}
