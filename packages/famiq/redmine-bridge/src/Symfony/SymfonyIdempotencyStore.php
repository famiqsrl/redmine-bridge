<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Symfony;

use Doctrine\DBAL\Connection;
use Famiq\RedmineBridge\DTO\IdempotencyRecord;
use Famiq\RedmineBridge\Idempotency\IdempotencyStoreInterface;

final class SymfonyIdempotencyStore implements IdempotencyStoreInterface
{
    private string $table = 'integration_idempotency';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(string $operation, string $key): ?IdempotencyRecord
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE operation = :operation AND key = :key', $this->table),
            ['operation' => $operation, 'key' => $key],
        );

        if ($row === false) {
            return null;
        }

        return new IdempotencyRecord(
            $row['operation'],
            $row['key'],
            $row['request_hash'],
            $row['response_payload'],
            $row['created_at'],
        );
    }

    public function save(IdempotencyRecord $record): void
    {
        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO %s (operation, key, request_hash, response_payload, created_at) VALUES (:operation, :key, :request_hash, :response_payload, :created_at) ON CONFLICT (operation, key) DO NOTHING',
                $this->table
            ),
            [
                'operation' => $record->operation,
                'key' => $record->key,
                'request_hash' => $record->requestHash,
                'response_payload' => $record->responsePayload,
                'created_at' => $record->createdAt,
            ],
        );
    }
}
