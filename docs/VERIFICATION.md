# Verification record

Prepared: 14 November 2026.

## Executed

The workspace did not contain a native PHP binary. PHP was executed using a temporary WebAssembly PHP 8.4 CLI/CGI runtime with PDO SQLite, ZipArchive and SimpleXML. This test runtime is not a project dependency and is not included in the source archive.

- **30 backend integration checks passed** (`tests/run.php`): unpaid/exhausted/expired membership rejection; duplicate payment handling; credit reservations; cancellation; duplicate active bike jobs; fleet access; quality approval and rewash; exact ₹720 revenue allocation across 14 washes; location restrictions; atomic rollback; date handling; plate validation; CSV validation/duplicates; XLSX inline string parsing.
- **62 request-level checks passed**, including all 12 administrator screens, five non-administrator role sessions, restricted screen access, customer-only history, CSRF rejection, incomplete checklist rejection, employee/customer/bike creation, package purchase, simulated payment, intake, washing, quality, completion, displayed remaining balance, shift assignment and expense entry. These were executed through PHP's CGI request interface with session cookies and form bodies. They were not browser visual checks.
- All ten distributed PHP files passed compiler parsing, including the bulk catalogue importer. `tests/run.php` was then re-executed successfully after its final XLSX assertion was added.
- The catalogue CLI importer was exercised with the supplied template.
- JavaScript syntax checked with `node --check public/assets/app.js`.

## Not executed

- Native PHP 8.5, actual MySQL/MariaDB, Hostinger or cPanel deployment.
- A browser-based layout/accessibility inspection at desktop/mobile sizes.
- Merchant API requests, webhook delivery through a public HTTPS endpoint, bank settlement or refunds. Payment lifecycle tests used clearly labelled simulated payments; signature verification and live QR code paths still require merchant sandbox/live validation.
- Concurrent load testing at 75 locations. Database transactions and row locks protect credit writes by design, but concurrent MySQL throughput/deadlock testing must occur on the target server.
- Automatic retrieval of a complete manufacturer catalogue or licensed photo collection.

## Before live use

Run the included suite with native PHP, validate the full lifecycle on the intended MySQL server, configure HTTPS and the merchant webhook, verify a real payment, inspect mobile/desktop screens, restore-test backups, and enter actual locations/staff. Keep demo and production databases separate.

Reference documentation used for the implementation:
- https://www.php.net/supported-versions.php
- https://razorpay.com/docs/webhooks/qr-codes
- https://razorpay.com/docs/api/payments/qr-codes/
