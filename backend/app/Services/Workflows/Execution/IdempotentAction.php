<?php

namespace App\Services\Workflows\Execution;

use App\Exceptions\WorkflowConflictException;
use App\Models\IdempotencyRecord;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

/**
 * Stores the idempotency record in the same transaction as the workflow
 * change. A rollback removes the record. A concurrent duplicate waits on the
 * unique key, then replays the committed response or reports a payload mismatch.
 */
class IdempotentAction
{
    /**
     * @param  callable(): array{status: int, body: array<string, mixed>}  $callback
     */
    public function execute(User $actor, Organization $organization, Request $request, callable $callback): JsonResponse
    {
        $key = $this->key($request);
        $fingerprint = $this->fingerprint($request);

        try {
            $payload = DB::transaction(function () use ($actor, $organization, $key, $fingerprint, $callback) {
                $record = IdempotencyRecord::query()->create([
                    'organization_id' => $organization->id,
                    'actor_id' => $actor->id,
                    'idempotency_key' => $key,
                    'request_fingerprint' => $fingerprint,
                    'response_status' => 0,
                    'response_body' => [],
                ]);
                $payload = $callback();
                $record->forceFill([
                    'response_status' => $payload['status'],
                    'response_body' => $payload['body'],
                ])->save();

                return $payload;
            });
        } catch (UniqueConstraintViolationException) {
            return $this->replay($actor, $organization, $key, $fingerprint);
        }

        return response()->json($payload['body'], $payload['status']);
    }

    private function key(Request $request): string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,254}$/', $key) !== 1) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'Provide an idempotency key of 8 to 255 letters, numbers, dots, underscores, colons, or hyphens.',
            ]);
        }

        return $key;
    }

    private function fingerprint(Request $request): string
    {
        $content = $request->getContent();
        $decoded = [];

        if ($content !== '') {
            try {
                $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw ValidationException::withMessages([
                    'body' => 'Send a JSON request body.',
                ]);
            }

            if (! is_array($decoded)) {
                throw ValidationException::withMessages([
                    'body' => 'Send a JSON object.',
                ]);
            }
        }

        $canonical = json_encode([
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'body' => $this->canonicalize($decoded),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return hash('sha256', $canonical);
    }

    private function replay(User $actor, Organization $organization, string $key, string $fingerprint): JsonResponse
    {
        $existing = IdempotencyRecord::query()
            ->where('organization_id', $organization->id)
            ->where('actor_id', $actor->id)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing === null || $existing->response_status < 100) {
            throw new WorkflowConflictException('This request is already being processed.');
        }

        if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
            throw new WorkflowConflictException('This idempotency key was already used for a different request.');
        }

        return response()->json($existing->response_body, $existing->response_status);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
