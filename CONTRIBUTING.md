# Contributing

Thank you for improving IdcsmartClientLevel.

## Compatibility contract

- Keep the plugin identity `IdcsmartClientLevel` / `idcsmart_client_level` stable.
- Do not modify ZJMF-CBAP core files for plugin features.
- Keep `upgrade()` idempotent; an upgrade must not require uninstalling the plugin.
- Preserve the existing official-compatible level, client-level and product-discount table contracts.
- Use decimal strings and `lib/Money.php` for business money. Do not calculate money with PHP or JavaScript floating-point values on the server.
- Paid orders and refunds must be synchronized from authoritative order fields and remain idempotent under repeated or concurrent Hooks.
- A business action that changes balances, state and audit data must commit in one database transaction.
- User-facing messages must use business language. Do not expose SQL, JSON, HTTP status internals, table names, framework details or stack traces.
- Every active action needs idle, loading, success and error behavior. A successful write followed by a failed refresh must remain a successful write in the message.
- Keep the three client templates byte-identical unless a documented platform requirement needs a difference.

## Tests

Run before opening a pull request:

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/money_test.php
php tests/contract_test.php
node --check public/client-level-admin.js
node --check public/client-level-client.js
php tools/open_source_audit.php
```

Runtime integration tests require a disposable ZJMF-CBAP database. They create
fixtures inside a transaction and roll them back, but should still never be run
against production.

## Pull requests

Describe the business invariant being changed and include tests for success,
rejection, duplicate submission, interruption and refund behavior where
applicable. Do not commit generated ZIP files, private keys, credentials,
database dumps, runtime caches or third-party plugin archives.
