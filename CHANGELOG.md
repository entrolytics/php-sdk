# Changelog

## Unreleased

### Fixed

- The package could not be autoloaded: `composer.json` mapped the namespace `EntrolyticsNG\` while
  every class declares `Entrolytics\`, and Laravel package discovery registered a service provider
  and facade that did not exist. The autoload map and discovery entries now use `Entrolytics\`
  (facade alias `Entrolytics`), and each exception class has its own file so PSR-4 can find it.
