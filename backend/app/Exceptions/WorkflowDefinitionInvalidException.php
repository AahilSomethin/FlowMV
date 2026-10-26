<?php

namespace App\Exceptions;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class WorkflowDefinitionInvalidException extends RuntimeException implements Responsable
{
    /**
     * @param  list<array{code: string, message: string, node_id: ?string, edge_id: ?string, field_id: ?string}>  $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('The workflow definition is invalid.');
    }

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse([
            'message' => 'The workflow definition is invalid.',
            'errors' => [
                'definition' => ['The workflow definition is invalid.'],
            ],
            'violations' => $this->violations,
        ], 422);
    }
}
