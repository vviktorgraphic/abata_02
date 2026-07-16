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

