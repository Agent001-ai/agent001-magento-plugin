<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Connect;

use Magento\Framework\App\ResourceConnection;
use Throwable;

class NonceStore
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * Store data under a new single use nonce.
     *
     * @param array $data
     */
    public function create(array $data): string
    {
        $token = bin2hex(string: random_bytes(length: 32));
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('agent001_nonce');
        $db->delete($table, ['expires_at < ?' => time()]);
        $db->insert($table, [
            'token_hash' => hash(algo: 'sha256', data: $token),
            'data' => json_encode(value: $data, flags: JSON_THROW_ON_ERROR),
            'expires_at' => time() + 900,
        ]);
        return $token;
    }

    /**
     * Return and delete data stored under a valid nonce.
     *
     * @param string $token
     */
    public function consume(string $token): array
    {
        if (!preg_match(pattern: '/\A[a-f0-9]{64}\z/', subject: $token)) {
            return [];
        }
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('agent001_nonce');
        $hash = hash(algo: 'sha256', data: $token);
        $db->beginTransaction();
        try {
            $row = $db->fetchRow($db->select()->from($table)
                ->where('token_hash = ?', $hash)->forUpdate());
            $db->delete($table, ['token_hash = ?' => $hash]);
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
