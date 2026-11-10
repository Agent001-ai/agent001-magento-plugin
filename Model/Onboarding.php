<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model;

use Magento\Framework\App\ResourceConnection;

class Onboarding
{
    public const STEPS = 4;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Return the stored setup wizard progress.
     */
    public function get(): array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->table())
            ->where('store_id = ?', Settings::INSTALLATION));
        if (!$row) {
            return ['status' => 'active', 'step' => 1, 'completed_at' => null];
        }
        return [
            'status' => (string) $row['status'],
            'step' => (int) $row['step'],
            'completed_at' => $row['completed_at'],
        ];
    }

    /**
     * Store and return the computed wizard step.
     *
     * @param bool $connected
     * @param array<int,array<string,mixed>> $channels
     * @return array<string, mixed>
     */
    public function sync(bool $connected, array $channels): array
    {
        $row = $this->get();
        $step = $this->step(connected: $connected, channels: $channels);
        if ($step !== $row['step']) {
            $this->write(data: ['step' => $step]);
            $row['step'] = $step;
        }
        return $row;
    }

    /**
     * Compute the wizard step from connection and store view state.
     *
     * @param bool $connected
     * @param array<int,array<string,mixed>> $channels
     */
    public function step(bool $connected, array $channels): int
    {
        if (!$connected) {
            return 1;
        }
        $assigned = array_filter(
            array: $channels,
            callback: static fn (array $setting): bool => !empty($setting['agent_id'])
                && !empty($setting['embed_token']),
        );
        if (empty($assigned)) {
            return 2;
        }
        foreach ($assigned as $setting) {
            if (empty($setting['setup_completed'])) {
                return 3;
            }
        }
        return 4;
    }

    /**
     * Check whether the setup wizard is still in progress.
     */
    public function active(): bool
    {
        return $this->get()['status'] !== 'complete';
    }

    /**
     * Mark the setup wizard as complete.
     */
    public function finish(): void
    {
        $this->write(data: ['status' => 'complete', 'completed_at' => gmdate(format: 'Y-m-d H:i:s')]);
    }

    /**
     * Remove setup wizard progress.
     */
    public function reset(): void
    {
        $this->resource->getConnection()->delete($this->table());
    }

    /**
     * Write setup wizard fields.
     *
     * @param array $data
     */
    private function write(array $data): void
    {
        $this->resource->getConnection()->insertOnDuplicate(
            $this->table(),
            ['store_id' => Settings::INSTALLATION] + $data,
            array_keys(array: $data),
        );
    }

    /**
     * Return the onboarding table name.
     */
    private function table(): string
    {
        return $this->resource->getTableName('agent001_onboarding');
    }
}
