---
paths:
  - 'app/Http/Resources/Api/**'
---

# Resources Api

## API resources list explicit fields only
Every /api/v1 response is an explicit resource listing its fields: never parent::toArray, ->toArray(), attributesToArray, only/except, and never tenant_id or any secret/hash/token/encrypted column (tests/Unit/Api/ApiArchitectureTest.php). A new route must also be added to docs/api/openapi-v1.json with its x-scope (ApiRouteContractTest compares them) and carry EnsureApiRequest:api, AuthenticateApiCredential, throttle:api, an api.scope, and RequireIdempotencyKey on mutations.
