<?php

namespace App\Http\Middleware\Api;

use App\Services\Api\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SaaS-6: the API's request envelope — a body no larger than the route allows (api / hook), JSON
 * for anything with a body, and a JSON body that parses (depth-limited). Runs before
 * authentication, so oversized or malformed bodies are refused before any lookup.
 */
class EnsureApiRequest
{
    public function handle(Request $request, Closure $next, string $kind = 'api'): Response
    {
        $limit = (int) config($kind === 'hook' ? 'api.limits.inbound_webhook_body_bytes' : 'api.limits.api_body_bytes');
        $declared = (int) $request->headers->get('Content-Length', '0');

        if ($declared > $limit || strlen($request->getContent()) > $limit) {
            throw new ApiException('payload_too_large', "The request body is larger than {$limit} bytes.", 413);
        }

        if (! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            if (! str_starts_with(strtolower((string) $request->headers->get('Content-Type', '')), 'application/json')) {
                throw new ApiException('unsupported_media_type', 'Send the body as application/json.', 415);
            }

            if ($request->getContent() !== '' && ! is_array(json_decode($request->getContent(), true, 32))) {
                throw new ApiException('malformed_json', 'The body is not a valid JSON object.', 400);
            }
        }

        return $next($request);
    }
}
