<?php

namespace App\Services\Api;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * SaaS-6: every /api error as {"error": {"code", "message", "request_id"[, "details"]}} with a
 * stable code. Unexpected errors say nothing about their cause (it is in the log, under the same
 * request id).
 */
final class ApiErrorRenderer
{
    public static function render(Throwable $e): JsonResponse
    {
        [$status, $code, $message, $details, $headers] = match (true) {
            $e instanceof ApiException => [$e->status, $e->errorCode, $e->getMessage(), null, $e->headers],
            $e instanceof ValidationException => [422, 'validation_failed', 'The request is not valid.', $e->errors(), []],
            $e instanceof AuthenticationException => [401, 'unauthenticated', 'A valid API credential is required.', null, ['WWW-Authenticate' => 'Bearer']],
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => [403, 'forbidden', 'The credential\'s owner may not do this.', null, []],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'not_found', 'Not found.', null, []],
            $e instanceof MethodNotAllowedHttpException => [405, 'method_not_allowed', 'This method is not allowed here.', null, $e->getHeaders()],
            $e instanceof ThrottleRequestsException => [429, 'rate_limited', 'Too many requests. Retry after the time in Retry-After.', null, $e->getHeaders()],
            $e instanceof DomainException => [422, 'unprocessable', $e->getMessage(), null, []],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), 'http_error', 'The request could not be completed.', null, $e->getHeaders()],
            default => [500, 'server_error', 'Something went wrong. Quote the request id if you contact support.', null, []],
        };

        $error = ['code' => $code, 'message' => $message, 'request_id' => Context::get('request_id')];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status, $headers);
    }
}
