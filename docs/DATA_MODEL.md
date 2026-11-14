# Data model

| Table | Purpose |
|---|---|
| locations | Addresses, contact, opening checklist, active state |
| users | Hashed credentials, roles, active state, salary reference, optional customer link |
| user_locations | Many-to-many staff location permissions |
| customers | Individual/corporate account, contact/address, home location |
| bike_models | Company, exact model, photo/source URL, image permission |
| bikes | Unique normalised registration and owning customer |
| packages | Editable offers for new sales |
| memberships | Immutable purchase benefits, status, remaining/reserved credit and validity |
| membership_bikes | Explicit bikes authorised to spend a membership |
| payments | One purchase payment, merchant QR/payment references and verified state |
| washes | Bike, membership, wash location, washer, checker, state and earned revenue |
| shifts | Location/date/shift/position, assigned employee, attendance and handover |
| expenses | Location/date/category operational cost ledger |
| audit_logs | Actor, action, entity, details and timestamp |
| login_attempts | Hashed email/IP throttle key and lockout |

All currency columns ending in `_paise` store integers. A membership's `remaining` includes reserved credits; its available amount is `remaining - reserved`. Creating a job increments `reserved`. Completion decrements both balances, cancellation only releases `reserved`, and rewash preserves the reservation.

Membership purchase snapshots separate old entitlements from future package edits. Payment rows and provider identifiers are unique; replaying the same payment is idempotent. MySQL uses InnoDB transactions and `SELECT ... FOR UPDATE`; SQLite tests use `BEGIN IMMEDIATE`. Application code controls table names and columns, while all incoming values use bound parameters.

Foreign keys preserve operational history. This release supports deactivation for users, locations, packages and catalogue entries; destructive removal is limited to unused packages. Customer deletion, financial corrections/refunds, transactional record deletion and accountant adjustments require explicit future workflows rather than direct database edits.
