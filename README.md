# Wash Free — PHP application, version 1.0

A mobile-friendly bike-wash operations application for a multi-location business. Server-rendered PHP, PDO prepared queries, MySQL/InnoDB, local CSS and small vanilla JavaScript. No Node.js process or Composer packages are needed on your server.

## Requirements

Use the latest patched **PHP 8.5** available from your host. The application syntax also supports PHP 8.3 and 8.4. Production: MySQL 8+ or MariaDB 10.6+, PDO MySQL, cURL, JSON, sessions. For Excel (.xlsx) imports enable ZipArchive and SimpleXML. SQLite/PDO SQLite is optional for local development and tests. Serve through HTTPS.

## Install on Hostinger / cPanel

1. Create **one MySQL database** and a database user in your hosting panel. The 75 washing locations are records within this database; they do not each require a database.
2. Extract the project. Keep `app/`, `bin/`, `storage/` and `config.php` **outside the public document root**.
3. Copy `config.example.php` to `config.php`. Enter the database host, name, username and password. Keep `mode` set to `production` for real business use.
4. In the hosting terminal, from the project directory, run:
   ```sh
   php bin/install.php your-email@example.com 'Choose-a-unique-strong-password'
   ```
   The command creates tables, the initial Super Admin, three packages, and eight clearly labelled starter bike model names. It creates no real locations or customer records. Run the command yourself; never send your database or merchant secrets in chat.
5. Point your domain/subdomain document root to the project's `public/` directory. If your host requires `public_html`, put the contents of `public/` in `public_html`, put the rest in an adjacent private `wash-free/` directory, and change the two public PHP files' bootstrap `require` paths to that private directory's `app/bootstrap.php`.
6. Open the domain and sign in using the email/password supplied to the install command.
7. Add locations; complete all seven opening checklist items before activating each one. Add employee logins and location assignments. Add customers, their bikes and package purchases.
8. Configure the merchant QR integration below before accepting money.

Installation uses a CLI command intentionally. There is no publicly exposed web installer or permanent default password. If your hosting plan has no terminal, ask hosting support to run the command, or use a plan with SSH/terminal access.

## Local demo

Create a private `config.php` containing:
```php
<?php
return [
    'mode' => 'demo',
    'dsn' => 'sqlite:' . __DIR__ . '/storage/demo.sqlite',
    'timezone' => 'Asia/Kolkata',
];
```
Then:
```sh
php bin/install.php admin@example.com 'Choose-a-strong-demo-password'
php bin/demo.php
php -S localhost:8080 -t public
```
Visit http://localhost:8080. `bin/demo.php` refuses production mode and adds three obviously fictitious demonstration locations plus test staff and customers. Their logins use the same password you set for the demo administrator. Simulated payments require demo mode and are labelled throughout the interface. Never reuse the demo database for live collections.

## Included modules

- Super Admin, Location Partner, Quality Supervisor, Wash Captain, Wash Specialist, Wash Assistant and customer accounts.
- Per-location employee permissions and customer-only package/history views.
- Locations, mandatory opening checklist, CSV/XLSX import preview and confirmation; no fabricated list of your 75 actual addresses.
- Customer/company registration, multiple bikes, unique normalised vehicle numbers.
- Package add/edit/delete-unused/deactivate; immutable price, wash allowance and validity snapshots on purchase.
- Individual memberships and shared fleet balances restricted to explicitly linked bikes owned by the same account.
- QR payment creation, signed payment webhook, duplicate-event protection, uncertain-QR reconciliation.
- Wash queue, assigned washer, quality check, rewash with reason, cancellation, transactional reservations and credit consumption.
- Shifts: 07:00–15:00 and 15:00–23:00; captain/assistant positions; attendance; handover; double-booking constraints.
- Collections, completed-service revenue, recorded expenses, location operating result, unused prepaid service balance.
- Bike company/model catalogue with photo URL, source and permission notes; audit events; password changes.

## Business defaults

| Package | Price | Total washes | Validity |
|---|---:|---:|---|
| Quick Wash | ₹60 | 1 | Purchase day |
| Wash Plus | ₹720 | 14 (12 paid + 2 free) | 12 months |
| Fleet Freedom | ₹2,160 | 46 (36 paid + 10 free) | 36 months |

- Validity begins on the verified payment date, expires at the end of the displayed expiry date, and handles month-end dates by clamping to the target month's final day.
- Packages can be redeemed at any active location. Corporate bikes share one balance; an individual package authorises exactly one bike.
- Reservation occurs when queuing. Completion deducts one credit; cancellation releases the reservation; a rewash retains the same reservation.
- Production intake is limited to 07:00–22:59 India time. In-progress jobs may finish later. Demo mode bypasses the intake hour limit for testing.
- Allocation of completed-service revenue uses proportional package value; cumulative rounding ensures all credits together equal the exact price paid. Collections belong to the selling location, earned service revenue belongs to the washing location. Cross-location internal settlements are not implemented.
- Staff monthly salary is a reference field, **not automatically posted as payroll**. Enter salary and other costs in the expense ledger to see operating results.
- Published packages and transactional history are preserved. Unused packages can be deleted; referenced records are deactivated rather than destructively deleted.

## QR payments: live configuration required

This version uses one central Razorpay merchant account. Your merchant account must be eligible for UPI QR Codes.

1. Set `razorpay_key_id`, `razorpay_key_secret` and a distinct `razorpay_webhook_secret` in private `config.php`.
2. In the merchant dashboard create an HTTPS webhook pointing to `https://YOUR-DOMAIN/webhook.php`. Subscribe to **qr_code.credited**.
3. Create a package purchase in Wash Free. In QR collections, click **Generate UPI QR**. The backend requests a fixed-amount, single-use QR using the merchant API.
4. Customer scans it using their UPI app. Wash Free validates the signature against the raw webhook body and checks registered QR, captured status, UPI method, INR and exact amount before activating the package.
5. Repeated identical payment events do not add credits again. If QR creation times out or is uncertain, the record stays `qr_creating`; Super Admin can restore the existing QR reference after a server-side merchant lookup. Replay a valid webhook after reconciliation if necessary.
6. Test the full flow in your merchant test environment where supported, then conduct a small live transaction and reconcile it with your bank before launch.

Do not mark a payment successful from a QR scan, screenshot, browser redirect or submitted UTR. Refunds, chargebacks, separate merchant accounts per shop, automatic payouts and settlement reconciliation are **not implemented** in this release. Handle refunds with your merchant provider and account for them outside this operational dashboard until refund workflows are added. API credentials and an eligible merchant account have not been supplied, so no live transaction has been tested here.

## Location Excel

Use `public/location-template.csv` as the column template: `name,address,contact`. `.xlsx` reads the first worksheet (`sheet1.xml`), supports shared and inline strings, and rejects oversized XML or entity declarations. Export to CSV if your workbook uses a different first-sheet path or complex formulas. Import creates inactive locations; review the preview first and activate after the opening checklist. Your actual Excel checklist can replace the generic seven items in `CHECKLIST` once provided.

## Bike catalogue and photos

The eight starter entries are **not a complete or automatically scraped Indian bike database**. They contain no copied manufacturer photographs. Add exact models and licensed photo URLs through Bike catalogue; record the source and image permission. Remote images remain remote URLs, so availability depends on their host. For permanent, owned assets, host your licensed photos on your own HTTPS media domain. The app does not look up customer identity from a vehicle number, call a government registration service, or silently copy images from the internet.

For a bulk catalogue, fill `templates/bike-catalogue.csv` and run `php bin/import-bikes.php /private/path/catalogue.csv`. The importer validates all rows before committing, updates matching company/model entries, and requires source and permission notes for photos. It stores URLs; it does not download or automatically obtain image rights.

## Verification

Run against a temporary SQLite database with PDO SQLite:
```sh
php tests/run.php
```
The test creates and removes its own database, verifies payment idempotency, package reservations, overbooking/duplicate wash prevention, corporate bike ownership, cancellation release, quality workflow, expiry, exact revenue allocation, location isolation and rollback. No real payment API is called. Lint with `php -l` for every `.php` file. See `docs/VERIFICATION.md` for the actual environment and results used to prepare this archive.

## Operational limits and next steps

This is an implemented first release, not a claim of a completed production rollout across 75 shops. No live site or real database was provisioned. Add backups, HTTPS, a privacy notice, log retention and monitoring at deployment. Host configuration must prevent access to private config/database files. Login has server-side throttling; behind a reverse proxy configure the web server to supply trustworthy client IPs. Customer logins are created by Super Admin and linked to a customer account; there is no SMS OTP, self-registration, password reset email or WhatsApp integration yet.

The current UI lists up to 200 recent washes/payments, 100 customer-history entries and 300 audit events. Full pagination/export, automated complete manufacturer catalogue ingestion, media upload storage, customer OTP, detailed checklist photographs, holiday/leave scheduling, payroll automation, tax invoices and accountant-grade statements are future extensions. All real operational data remains in the database beyond these display limits.
