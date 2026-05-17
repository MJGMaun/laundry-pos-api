# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# First-time setup
composer run setup        # Install deps, generate key, run migrations

# Development
composer run dev          # Starts Laravel server, queue listener, log tail, and npm dev concurrently

# Testing
composer run test         # Clears config cache then runs all Pest tests
php artisan test --filter=ClassName                              # Run a single test class
php artisan test tests/Feature/Auth/AuthenticationTest.php      # Run a specific file

# Linting
./vendor/bin/pint         # Laravel Pint code formatter

# Database
php artisan migrate       # Run pending migrations
php artisan db:seed       # Seed with DefaultDataSeeder
```

## Architecture

This is a **Laravel 12 REST API** for a multi-branch laundry Point-of-Sale system. All routes live under `/api/` and are authenticated via Laravel Sanctum (Bearer tokens).

### Multi-tenant Branch Scoping

Every request operating on branch-level data must include an `X-Branch-Id` header. The `SetBranchContext` middleware resolves this to a `Branch` model and binds it into the request. Controllers extend a base controller that exposes `branchId()` and `scopeToBranch($query)` helpers — all Eloquent queries on branch-owned resources must go through these to enforce tenant isolation.

### Role-Based Access Control

Four roles: `super_admin`, `admin`, `cashier`, `staff`. The `CheckRole` middleware is applied per route group in `routes/api.php`. Super admins are not branch-scoped and manage branches globally.

### Layers

- `app/Http/Controllers/Api/` — thin controllers; delegate business logic to Services or Eloquent directly
- `app/Http/Middleware/` — `SetBranchContext`, `CheckRole`, `EnsureEmailIsVerified`
- `app/Models/` — Eloquent models: `User`, `Branch`, `Customer`, `Order`, `Load`, `Service`, `Payment`, `Expense`, `Settings`, and loyalty models
- `app/Services/` — business logic; add new services here rather than fattening controllers
- `routes/api.php` — all API routes; branch-scoped routes are grouped under the `SetBranchContext` middleware

### Key Data Relationships

- **Orders**: an `Order` has many `Load`s; each `Load` references a `Service`. `Payment` records belong to an `Order`.
- **Loyalty**: `Customer` accumulates points via `LoyaltyTransaction`s; tier is determined by `LoyaltyTier` thresholds.
- **Settings**: key/value pairs scoped per branch, with branch-level overrides of global defaults.

### Testing

Tests use Pest PHP with the `RefreshDatabase` trait against an in-memory SQLite database (configured in `phpunit.xml`). Feature tests in `tests/Feature/`, unit tests in `tests/Unit/`. The test DB is entirely separate from the dev MySQL database.
