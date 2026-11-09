<?php

use App\Helpers\ResponseStatusCodes;
use Symfony\Component\HttpFoundation\Response;

if (!function_exists('successResponse')) {
    function successResponse($message = "Successful.", $data = [])
    {
        return response()->json([
            "status" => true,
            "statusCode" => ResponseStatusCodes::OK,
            "message" => $message,
            "data" => $data,
        ], Response::HTTP_OK);
    }
}

if (!function_exists('errorResponse')) {
    function errorResponse(int $statusCode = ResponseStatusCodes::BAD_REQUEST, string $message = "An error occurred.", $data = [], $code = Response::HTTP_UNPROCESSABLE_ENTITY)
    {
        return response()->json([
            "status" => false,
            "statusCode" => $statusCode,
            "message" => $message,
            "data" => $data,
        ], $code);
    }
}

if (!function_exists('frontendUrl')) {
    /** Absolute link into the Vue app, for emails and notifications. */
    function frontendUrl(string $path = '/'): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('signedFileUrl')) {
    /** A link to a private file that works without a bearer token and expires after an hour. */
    function signedFileUrl(string $route, $params): string
    {
        $params = is_array($params) ? array_values($params) : [$params];
        $names = ['documents.show' => ['document'], 'bids.evidence' => ['bid'], 'fees.evidence' => ['invoice'], 'institutions.file' => ['institution', 'index']];
        $named = array_combine($names[$route], $params);
        $named['uid'] = auth('api')->id();

        return \Illuminate\Support\Facades\URL::temporarySignedRoute($route, now()->addHour(), $named);
    }
}

if (!function_exists('screenPath')) {
    /** The React screen address for a named API route. */
    function screenPath(string $apiUrl): string
    {
        return preg_replace('#^https?://[^/]+/api#', '', $apiUrl) ?: '/';
    }
}
