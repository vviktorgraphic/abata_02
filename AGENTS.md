# Repository instructions

- Never commit secrets, credentials, production data, or a real `.env` file.
- Every business rule must be covered by an automated test.
- Never change the database schema without a new, versioned migration.
- Do not use PHP's `mail()` function directly. Future mail delivery must use an abstraction and an authenticated transport.
- Handle every date and time in the `Europe/Budapest` timezone.
- Store booking calendar days in MySQL `DATE` columns; arrival is inclusive and departure is exclusive.
- Keep runtime code compatible with PHP 8.2+ and conventional cPanel hosting.
- Node.js must not be a production runtime dependency.
- Keep `public/` as the only web-accessible document root.
- Use PDO prepared statements for all queries containing values.
- Treat `docs/` as the primary specification for the 1.0 system and read the affected documents before each sprint.
- Report any difference between the specification and the code; never silently choose one interpretation.
- Every new business rule requires both automated tests and documentation in the same pull request.
- Keep IMPLEMENTED and PLANNED behavior explicitly separated in documentation.
- Document local development commands in PowerShell-compatible form.
