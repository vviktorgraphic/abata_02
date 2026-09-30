# Owner decisions pending for RC2

This list contains only values that must be supplied by the owner or hosting provider before production GO. No value below is invented by the implementation.

- Approved legal text and version for the booking policy and privacy notice.
- Production SMTP port, TLS/SSL mode, account, verified sender and secret for `s54.tarhely.com`; timeout is configurable through `MAIL_TIMEOUT_SECONDS`.
- Production domain, HTTPS termination/proxy addresses, HSTS value and access-log redaction for `/calendar/export.ics` query tokens.
- cPanel PHP CLI path, environment loading mechanism, cron account and monitoring/escalation contacts.
- Backup retention, measured staging restore evidence and confirmation of RPO 4 hours / RTO 5 minutes targets.
- Production rate-limit values, admin session absolute lifetime and data/log retention period.
- Production pricing tables, child-age bands and tax/legal classification.

Until these decisions are recorded and smoke-tested, RC2 remains a release candidate and production status is **NO-GO**.
