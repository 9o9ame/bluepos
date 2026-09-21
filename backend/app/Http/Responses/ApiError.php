<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

class ApiError
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public static function response(string $key, string $message, int $status, array $extra = []): JsonResponse
    {
        $error = array_merge([
            'key' => $key,
            'message' => $message,
        ], $extra);

        return response()->json(['error' => $error], $status);
    }
}
