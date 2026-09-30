# Sprint 11 – RC2 release hardening

**Status:** IMPLEMENTED locally on `release/rc2`; production gates remain pending where provider/owner evidence is required.

Implemented in this sprint: configurable SMTP timeout (`MAIL_TIMEOUT_SECONDS`), explicit `ext-pdo_mysql` platform requirement, session strict mode enforcement, iCal edit-form secret URL masking with blank-to-preserve update semantics, cPanel cron/path guidance, owner decision register, and RC2 regression/release documentation.

The RC2 branch was created from `release/rc1` and merged with `feature/automatic-ical-and-person-pricing` using `--no-ff`. The requested `git pull` could not run because `release/rc1` has no upstream tracking configuration; local feature and origin verification were retained and the deviation is recorded in the final report.

Automated PHPUnit, static contract checks and Docker checks are release evidence. Real provider SMTP, HTTPS/cPanel, browser 2FA and measured backup restore remain deployment gates and are not claimed as completed by repository tests.

See [owner decisions pending](OWNER_DECISIONS_PENDING.md), [deployment](15_DEPLOYMENT.md), [monitoring and cron](14_MONITORING_AND_CRON.md), and [release notes](../RELEASE_NOTES.md).
