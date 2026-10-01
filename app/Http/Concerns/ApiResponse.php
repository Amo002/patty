<?php

namespace App\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The only way an API controller builds a response (D-019).
 *
 * Success is { success, message, data, meta? }. Failures are built by the
 * exception renderer in bootstrap/app.php through error(), so a controller
 * never formats an error itself.
 */
trait ApiResponse
{
    /**
     * Wrap data in the success envelope.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function success(mixed $data = null, string $message = '', int $status = 200, array $meta = []): JsonResponse
    {
        $body = ['success' => true, 'message' => $message, 'data' => $data];

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return response()->json($body, $status);
    }

    /**
     * 201 variant of success() for endpoints that create a record.
     */
    protected function created(mixed $data, string $message): JsonResponse
    {
        return $this->success($data, $message, 201);
    }

    /**
     * Wrap a paginator in the success envelope with meta.pagination (D-032).
     *
     * @param  class-string<JsonResource>  $resourceClass
     * @param  array<string, mixed>  $meta  extra meta placed beside pagination (for example generated_at)
     */
    protected function paginated(LengthAwarePaginator $paginator, string $resourceClass, string $message = '', array $meta = []): JsonResponse
    {
        $data = $resourceClass::collection(collect($paginator->items()))->resolve(request());

        return $this->success($data, $message, 200, [
            ...$meta,
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * Build the failure envelope. Called by the exception renderer, not by controllers.
     *
     * @param  array<string, mixed>  $errors
     */
    public static function error(string $message, string $code, int $status, array $errors = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code,
            // An empty PHP array would encode as [] and break clients that expect an object.
            'errors' => $errors === [] ? (object) [] : $errors,
        ], $status);
    }
}
