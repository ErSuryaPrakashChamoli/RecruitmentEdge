---
paths:
  - 'app/Providers/AppServiceProvider.php,bootstrap/app.php,config/app.php,app/Services/CandidatePortalService.php'
---

# Providers Services

## Generated links take their host from APP_URL, never the request
P810-SEC-001: AppServiceProvider::configureTrustedOrigin() calls URL::forceRootUrl(config('app.url')) outside local, so emailed signed links (portal set-password, Filament staff reset) cannot be pointed at a forged Host / X-Forwarded-Host. The scheme still follows the request (the `signed` middleware validates against the request URL). Do not remove it or generate absolute links from request()->root()/getHost(). APP_URL must be the public origin (with any base path). Optional APP_TRUSTED_HOSTS (config app.trusted_hosts, exact names, anchored in bootstrap/app.php) refuses other Hosts with 400; it must include `localhost` for the compose health check. Tests resetting trusted hosts must call Symfony Request::setTrustedHosts([]) afterwards (static state).
