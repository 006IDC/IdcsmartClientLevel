# Database contract

All table names below use the runtime database prefix. The plugin does not add
an invite-code field to the core `client` table and does not modify core product
or order schemas.

## Official-compatible level tables

| Table | Purpose |
|---|---|
| `addon_idcsmart_client_level` | Level name, own-spend threshold, status and discount. |
| `addon_idcsmart_client_level_client_link` | Current level per user and the single-value compatibility projection in `cumulative_amount`. |
| `addon_idcsmart_client_level_product_link` | Per-product discount used by compatible Server modules. |
| `addon_idcsmart_client_level_product_group` | Per-product-group discount fallback. |

The current user level for templates is:

```text
addon_idcsmart_client_level_client_link.addon_idcsmart_client_level_id
```

Join it to `addon_idcsmart_client_level.id` for the level name, color and
discount. `cumulative_amount` is a compatibility projection and must not be
treated as the sum of own spend and referral contribution.

## Plugin-owned business tables

| Table suffix | Purpose |
|---|---|
| `_order` | Idempotent paid/refunded order ledger for own spend. |
| `_setting` | Global settings and per-product referral eligibility. |
| `_log` | Level-change history. |
| `_referrer` | Invite code per user. |
| `_referral_bind` | Single current referrer plus historical bindings. |
| `_referral_accrual` | Per-order referral entitlement and product-policy snapshot. |
| `_benefit_account` | Cached balance buckets. |
| `_benefit_allocation` / `_item` | Cash-or-contribution allocation and source batches. |
| `_benefit_flow` | Append-only balance deltas with idempotency keys. |
| `_withdraw_method` | Encrypted payee data and masks. |
| `_withdraw` | Private freeze state and link to V10 withdrawal management. |
| `_level_policy` | Referral threshold and rate overrides attached to a level. |
| `_manual_override` | Administrator-assigned level, reason and expiry. |
| `_metric` | Rebuildable dual-axis metrics. |
| `_audit` | Security and administrative action history. |

## Lifecycle

`install()` and `upgrade()` create missing tables/columns/indexes idempotently.
`uninstall()` intentionally preserves business and audit data. Removing data is
an explicit database-administration decision and is not performed by the
plugin lifecycle.
