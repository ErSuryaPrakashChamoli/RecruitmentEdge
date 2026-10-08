<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Api\AuthenticateApiCredential;
use App\Services\Api\ApiPrincipal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * SaaS-6 API v1 base: lists are cursor-paginated by id (no counts over large tables), with an
 * explicit allow-list of query parameters — anything else is a validation error, never silently
 * ignored. Every list's query is already limited to the tenant (TenantScope) and to what the
 * credential's owner may see (the caller passes the hierarchy-scoped query).
 */
abstract class ApiController extends Controller
{
    protected function principal(Request $request): ApiPrincipal
    {
        return AuthenticateApiCredential::principal($request);
    }

    /**
     * @param  Builder<Model>  $query
     * @param  class-string<JsonResource>  $resource
     * @param  array<string, array<int, mixed>>  $filters  query parameter => validation rules
     * @param  array<string, callable(Builder<Model>, mixed): void>  $apply  query parameter => how it filters
     */
    protected function list(Request $request, Builder $query, string $resource, array $filters = [], array $apply = []): JsonResponse
    {
        $rules = [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('api.limits.max_per_page', 100)],
            'cursor' => ['sometimes', 'string', 'max:512'],
            'sort' => ['sometimes', 'in:id,-id'],
            'updated_since' => ['sometimes', 'date'],
            ...$filters,
        ];

        $unknown = array_diff(array_keys($request->query()), array_keys($rules));

        if ($unknown !== []) {
            throw ValidationException::withMessages(collect($unknown)->mapWithKeys(fn (string $name): array => [$name => ["Unknown query parameter \"{$name}\"."]])->all());
        }

        $input = Validator::make($request->query(), $rules)->validate();

        if (isset($input['updated_since'])) {
            $query->where($query->qualifyColumn('updated_at'), '>=', Carbon::parse($input['updated_since']));
        }

        foreach ($apply as $name => $filter) {
            if (array_key_exists($name, $input)) {
                $filter($query, $input[$name]);
            }
        }

        $page = $query->orderBy($query->qualifyColumn('id'), ($input['sort'] ?? 'id') === '-id' ? 'desc' : 'asc')
            ->cursorPaginate((int) ($input['per_page'] ?? config('api.limits.per_page', 25)));

        return response()->json([
            'data' => $resource::collection($page->items())->resolve($request),
            'meta' => [
                'per_page' => $page->perPage(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
            ],
        ]);
    }

    protected function one(Request $request, JsonResource $resource): JsonResponse
    {
        return response()->json(['data' => $resource->resolve($request)]);
    }
}
