<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Laravel;

use Famiq\RedmineBridge\DTO\IdempotencyRecord;
use Famiq\RedmineBridge\Idempotency\IdempotencyStoreInterface;
use Illuminate\Database\ConnectionInterface;

final class LaravelIdempotencyStore implements IdempotencyStoreInterface
{
    private string $table = 'integration_idempotency';

    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function find(string $operation, string $key): ?IdempotencyRecord
    {
        $row = $this->connection->table($this->table)
            ->where('operation', $operation)
            ->where('key', $key)
            ->first();

        if ($row === null) {
            return null;
        }

        return new IdempotencyRecord(
            $row->operation,
            $row->key,
            $row->request_hash,
            $row->response_payload,
            $row->created_at,
        );
    }

    public function save(IdempotencyRecord $record): void
    {
        $this->connection->table($this->table)->updateOrInsert([
            'operation' => $record->operation,
            'key' => $record->key,
        ], [
            'request_hash' => $record->requestHash,
            'response_payload' => $record->responsePayload,
            'created_at' => $record->createdAt,
        ]);
    }
}
