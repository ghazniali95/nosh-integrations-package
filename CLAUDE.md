# nosh/omniconnect

Laravel package (Packagist `nosh/omniconnect`) connecting a POS to foodpanda via Delivery Hero's POS Integration Middleware. Consumed by `nosh-merchant`.

- Tests: `composer install && vendor/bin/phpunit`. New test files must be listed in `phpunit.xml` (it lists files, not a directory).
- Develop against `OMNICONNECT_TRANSPORT=mock`; there are no Delivery Hero credentials yet.
- Money is integer paisa everywhere.
- Platform-specific code lives only in `src/Drivers/*Driver.php`; clients, intake, routes and events stay platform-neutral.
- Releases: bump `CHANGELOG.md`, merge to `main`, push a `vX.Y.Z` tag — Packagist updates from the tag. Schema changes are NEW migrations (never edit a shipped one).
- Inbound handlers in the host app must be idempotent on the order token: the handoff is retried until it succeeds.
