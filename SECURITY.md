# Security

## Reporting a vulnerability

Please don't open a public issue. Report it privately through GitHub: the repository's **Security** tab > **Report a vulnerability**. We reply within two working days and keep you updated until it's fixed.

Include what you found, how to reproduce it, and the version you used. If it affects the KetaPay API itself rather than this library, report it the same way and we'll route it.

## Supported versions

The latest release receives security fixes. While the library is in beta (0.x), fixes ship in the next beta.

## Handling secrets

Never paste secret keys (`sk_live_`, `sk_test_`), customer tokens or webhook secrets into issues, pull requests or logs. If you've exposed a key, roll it in the KetaPay dashboard straight away.
