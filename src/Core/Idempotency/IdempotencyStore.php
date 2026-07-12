<?php

namespace EzEcommerce\Core\Idempotency;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\IdempotencyStatus;
use EzEcommerce\Core\Exceptions\IdempotencyConflictException;
use EzEcommerce\Core\Exceptions\IdempotencyPayloadMismatchException;
use EzEcommerce\Core\Models\IdempotencyRecord;
use Illuminate\Support\Facades\DB;

final class IdempotencyStore
{
    public function __construct(
        private readonly Clock $clock,
    ) {}

    /**
     * @param  callable(): array{resource_type: string, resource_id: int, payload: array<string, mixed>}  $operation
     * @return array{record: IdempotencyRecord, result: array<string, mixed>|null}
     */
    public function execute(string $scope, string $key, string $requestHash, callable $operation): array
    {
        return DB::transaction(function () use ($scope, $key, $requestHash, $operation) {
            $record = IdempotencyRecord::query()
                ->where('scope', $scope)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                $record = IdempotencyRecord::query()->create([
                    'scope' => $scope,
                    'key' => $key,
                    'request_hash' => $requestHash,
                    'status' => IdempotencyStatus::Processing,
                    'attempts' => 1,
                    'locked_at' => $this->clock->now(),
                    'locked_until' => $this->clock->now()->modify('+'.config('ez-ecommerce.idempotency.lock_ttl_seconds', 60).' seconds'),
                    'expires_at' => $this->clock->now()->modify('+'.config('ez-ecommerce.idempotency.ttl_minutes', 1440).' minutes'),
                ]);
            } else {
                if ($record->request_hash !== $requestHash) {
                    throw IdempotencyPayloadMismatchException::for($scope, $key);
                }

                if ($record->status === IdempotencyStatus::Completed) {
                    return ['record' => $record, 'result' => $record->response_payload];
                }

                if ($record->status === IdempotencyStatus::FailedTerminal) {
                    return ['record' => $record, 'result' => $record->response_payload];
                }

                if ($record->status === IdempotencyStatus::Processing && $record->locked_until > $this->clock->now()) {
                    throw IdempotencyConflictException::for($scope, $key);
                }

                $record->update([
                    'status' => IdempotencyStatus::Processing,
                    'attempts' => $record->attempts + 1,
                    'locked_at' => $this->clock->now(),
                    'locked_until' => $this->clock->now()->modify('+'.config('ez-ecommerce.idempotency.lock_ttl_seconds', 60).' seconds'),
                ]);
            }

            try {
                $result = $operation();
                $record->update([
                    'status' => IdempotencyStatus::Completed,
                    'resource_type' => $result['resource_type'],
                    'resource_id' => $result['resource_id'],
                    'response_payload' => $result['payload'],
                    'completed_at' => $this->clock->now(),
                    'locked_until' => null,
                ]);

                return ['record' => $record->fresh(), 'result' => $result['payload']];
            } catch (\Throwable $e) {
                $record->update([
                    'status' => IdempotencyStatus::FailedRetryable,
                    'last_error' => $e->getMessage(),
                    'locked_until' => null,
                ]);

                throw $e;
            }
        });
    }
}
