<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Connect;

use Magento\Framework\App\ResourceConnection;
use Throwable;

class NonceStore
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function create(array $data): string
    {
        $token = bin2hex(string: random_bytes(length: 32));
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(modelEntity: 'agent001_nonce');
        $db->delete(table: $table, where: ['expires_at < ?' => time()]);
        $db->insert(table: $table, bind: [
            'token_hash' => hash(algo: 'sha256', data: $token),
            'data' => json_encode(value: $data, flags: JSON_THROW_ON_ERROR),
            'expires_at' => time() + 900,
        ]);
        return $token;
    }

    public function consume(string $token): array
    {
        if (!preg_match(pattern: '/\A[a-f0-9]{64}\z/', subject: $token)) {
            return [];
        }
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(modelEntity: 'agent001_nonce');
        $hash = hash(algo: 'sha256', data: $token);
        $db->beginTransaction();
        try {
            $row = $db->fetchRow(sql: $db->select()->from(name: $table)
                ->where(cond: 'token_hash = ?', value: $hash)->forUpdate());
            $db->delete(table: $table, where: ['token_hash = ?' => $hash]);
            $db->commit();
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
        if (!$row || (int) $row['expires_at'] < time()) {
            return [];
        }
        return json_decode(json: $row['data'], associative: true, flags: JSON_THROW_ON_ERROR);
    }
}
