# Design history

The project originally explored adding personal spend and referral contribution
into one accumulated amount. That design was retired before the current release
because unlike quantities are easy to combine into unintended upgrades.

Since 1.6.0, level evaluation has two independent axes:

- personal net spend is compared only with the level's spend threshold;
- locked referral contribution is compared only with the level's referral threshold;
- the higher of the two independently qualified levels wins;
- disabling either path preserves the current level, while refunds still force
  the required rollback.

This history is informational. `README.md`, `docs/ARCHITECTURE.md`, the tests,
and the current implementation define the supported contract.
