# Legacy WP Booking System CSV import

**Status:** IMPLEMENTED on `release/rc2`; production use requires an authenticated admin and a verified backup.

The admin menu entry **Korábbi import** accepts the WP Booking System UTF-8 CSV export. Preview is read-only and shows row totals, source statuses, calendars and validation errors. Confirmation is explicit and protected by the existing admin session, CSRF and action guard.

Defaults select only `A Bata - naptár` and source statuses `accepted` and `pending`. Test-calendar and `trash` rows are shown but excluded. Mapping is `accepted → confirmed`, `pending → pending`, and optional `trash → invalidated`; only confirmed bookings block availability.

The importer preserves half-open `[arrival, departure)` dates, guest/child data, source acceptance evidence and a durable WPBS provenance record. It does not create pricing snapshots, policy-version snapshots or notification outbox messages. Imported bookings display that historical pricing is unavailable instead of presenting `0 Ft` as a real amount.

Re-importing the same `(wpbs, source booking ID)` is idempotent. A materially changed duplicate is rejected for review. The source CSV is processed transiently and is not retained in the repository, audit metadata or release package.

Before production use: export a backup, perform a preview, inspect conflicts and warnings, then confirm only the intended rows. Rollback is a normal database restore/forward-fix decision; do not delete migration 021 or provenance history manually.
