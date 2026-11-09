<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Client\DTO;

use UnexpectedValueException;

readonly class AgentStatusDTO
{
    public function __construct(public string $id, public bool $active)
    {
    }

    public static function from(array $record): self
    {
        if (!is_string(value: $record['agent_id'] ?? null) || !is_bool(value: $record['active'] ?? null)) {
            throw new UnexpectedValueException(message: 'Invalid agent status response.');
        }
        return new self(id: $record['agent_id'], active: $record['active']);
    }
}
