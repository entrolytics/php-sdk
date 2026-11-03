# Changelog

## Unreleased

### Fixed

- The package could not be autoloaded: `composer.json` mapped the namespace `EntrolyticsNG\` while
  every class declares `Entrolytics\`, and Laravel package discovery registered a service provider
  and facade that did not exist. The autoload map and discovery entries now use `Entrolytics\`
  (facade alias `Entrolytics`), and each exception class has its own file so PSR-4 can find it.
- `track` and `pageView` generated a new session id per call, so every server-side event counted
  as a new visit. When `ip_address` or `user_agent` is given, as the Laravel middleware does, the
  session id is now derived from them per 30-minute UTC window, cookieless like the visitor id. An
  explicit `session_id` still wins; without visitor context a new id per call is kept.
