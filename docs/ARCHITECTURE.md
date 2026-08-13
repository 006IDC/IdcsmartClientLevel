# Architecture and invariants

## Level evaluation

The official-compatible level tables remain the only discount-level source.
Own net spend and locked referral contribution are independent axes. Each axis
matches its own threshold and the higher matching level wins; amounts are never
added to cross a threshold.

Administrators may disable either upgrade path. Disabling a path preserves the
current level and historical facts, while refunds always force the necessary
rollback. A timed administrator override takes precedence until it expires.

## Referral benefits

Only direct referrals are counted. A paid non-recharge order creates or updates
one accrual identified by `source_order_id`. Product eligibility and the amount
eligible for referral rewards are snapshotted at payment time. Repeated payment
or refund Hooks synchronize from the current order values rather than adding a
callback amount.

After the mandatory observation period, a user can allocate an entitlement to
exactly one destination:

- withdrawable balance; or
- permanently locked level contribution.

Allocation rows, source items, balance changes and flow records are committed
in one transaction with unique business keys.

## Withdrawal and refunds

A withdrawal first moves money from withdrawable to frozen. Only after the
mandatory private freeze period is it published to V10 withdrawal management.
Before real payment, a refund cancels affected in-flight withdrawals and uses
the released amount to cover the refund. A refund after real payment becomes
debt, and future referral benefits offset that debt first.

Payee account and name are encrypted through the host AES helper; lists expose
only masks. The plugin does not send telemetry or business data to an external
service.

## Trust boundaries

- Admin APIs require the V10 admin middleware and declared permissions.
- Client APIs derive the user ID from the authenticated V10 session, never from request parameters.
- The public invite route only accepts an existing 4-32 character alphanumeric code and redirects to a validated same-site path.
- Invite cookies are HttpOnly, SameSite=Lax and Secure on HTTPS.
- DOM rendering uses `textContent`; untrusted values are not injected as HTML.
- No dynamic code execution, shell command, remote include or runtime CDN is used.
