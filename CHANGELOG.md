# Changelog

## Unreleased

### Breaking

- PHP 8.2 or newer is required; PHP 8.1 is end-of-life. The Laravel integration supports Laravel
  12 and 13; Laravel 10 and 11 are end-of-life.
- `trackVital` no longer requires `rating`, which it never sent: the API rates the value itself.
  It now requires a numeric `value` instead of sending 0 when it is missing. The documented
  `delta`, `id`, `navigation_type` and `attribution` parameters were never sent and the vitals
  endpoint does not accept them, so they are no longer documented.

### Changed

- Guzzle 8 is supported alongside Guzzle 7.16+ (Laravel 12 still requires Guzzle 7).

### Fixed

- The package could not be autoloaded: `composer.json` mapped the namespace `EntrolyticsNG\` while
  every class declares `Entrolytics\`, and Laravel package discovery registered a service provider
  and facade that did not exist. The autoload map and discovery entries now use `Entrolytics\`
  (facade alias `Entrolytics`), and each exception class has its own file so PSR-4 can find it.
- `track` and `pageView` generated a new session id per call, so every server-side event counted
  as a new visit. When `ip_address` or `user_agent` is given, as the Laravel middleware does, the
  session id is now derived from them per 30-minute UTC window, cookieless like the visitor id. An
  explicit `session_id` still wins; without visitor context a new id per call is kept.
- The SDK's `User-Agent` reported version 1.1.0; it now carries the package version.
