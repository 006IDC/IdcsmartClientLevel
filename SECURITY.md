# Security Policy

## Supported version

Security fixes are applied to the latest release line. Before reporting a
problem, please reproduce it on the newest tag without using production
credentials or customer data.

## Reporting a vulnerability

Do not publish an issue containing an exploitable vulnerability, credential,
private key, customer record or production URL. Use GitHub's private security
advisory reporting feature on the repository Security page.

Please include:

- affected plugin and ZJMF-CBAP versions;
- the smallest safe reproduction;
- expected and actual result;
- whether money, withdrawal state, authorization or personal data is affected;
- suggested mitigation, if known.

The maintainer should acknowledge a complete report within seven days. A fix
and disclosure schedule will be coordinated according to impact.

## Deployment warning

This plugin processes referral benefits and withdrawal data. Test payment,
refund, repeated Hook delivery, frozen withdrawal and already-paid refund
scenarios in a non-production environment before deployment. Never attach a
database dump, JWT, server password, encryption key or Ed25519 signing key to
an issue.
