<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Client\DTO;

use UnexpectedValueException;

class AgentListDTO
{
    /**
     * @param array $agents
     * @param array $selectedIds
     */
    public function __construct(public readonly array $agents, public readonly array $selectedIds)
    {
    }

    /**
     * Create the DTO from an API record.
     *
     * @param array $record
     */
    public static function from(array $record): self
    {
        if (!is_array(value: $record['agents'] ?? null) || !is_array(value: $record['selected_ids'] ?? [])) {
            throw new UnexpectedValueException(message: 'Invalid agents response.');
        }
        return new self(
            agents: AgentDTO::fromArray(records: $record['agents']),
            selectedIds: $record['selected_ids'] ?? [],
        );
    }
}
