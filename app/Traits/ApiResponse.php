<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

trait ApiResponse
{
    protected function success(mixed $data = null, string $message = 'Operation completed successfully', int $statusCode = 200, array $extra = []): JsonResponse
    {
        $response = array_merge([
            'success' => true,
            'statusCode' => $statusCode,
            'message' => $message,
            'data' => $data,
        ], $extra);

        return response()->json($response, $statusCode);
    }

    protected function paginated(LengthAwarePaginator $paginator, string $message = 'Operation completed successfully'): JsonResponse
    {
        $response = [
            'success' => true,
            'statusCode' => 200,
            'message' => $message,
            'data' => $paginator->items(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'limit' => $paginator->perPage(),
                'totalRecords' => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
                'timestamp' => now()->toIso8601String(),
            ],
        ];

        return response()->json($response, 200);
    }

    protected function error(string $code, string $message, int $statusCode = 400, array $details = []): JsonResponse
    {
        $response = [
            'success' => false,
            'statusCode' => $statusCode,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'timestamp' => now()->toIso8601String(),
        ];

        return response()->json($response, $statusCode);
    }
}
