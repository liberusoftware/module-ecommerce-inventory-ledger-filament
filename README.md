# Ecommerce: Inventory Ledger Filament

> This optional Filament 5 presentation package presents exactly one independent domain module. It contributes reusable resources, pages, widgets, schemas, tables, infolists, and actions to application-owned panels while delegating authorization, validation, tenancy, persistence, and business rules to the [ecommerce-inventory-ledger](https://github.com/liberusoftware/module-ecommerce-inventory-ledger) public boundary.

[Software](https://liberusoftware.com) ·
[Hosting](https://liberuhosting.com) ·
[Services](https://liberuservices.com) ·
[Liberu Group](https://liberugroup.com)

![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white) ![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white) ![Filament](https://img.shields.io/badge/Filament-5-FDAE4B)
[![Latest release](https://img.shields.io/github/v/release/liberusoftware/module-ecommerce-inventory-ledger-filament?sort=semver)](https://github.com/liberusoftware/module-ecommerce-inventory-ledger-filament/releases/latest) [![Tests](https://github.com/liberusoftware/module-ecommerce-inventory-ledger-filament/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/liberusoftware/module-ecommerce-inventory-ledger-filament/actions/workflows/tests.yml)

## Features

- Fully compatible with **Laravel 13**, **PHP 8.5**, and **Pest 5**.
- Built following the domain-driven design guidelines of the Liberu architecture.
- Reusable, presenting a clean public contract and boundaries.
- Adheres to the strict database, security, and authorization standards of Liberu.

## The rule that shapes this package

**The ledger is append-only, and the UI does not pretend otherwise.**

`StockMovement` refuses updates and deletes at the model. So there is no edit
action and no delete action on a movement — a resource scaffolded from habit
offers both and fails at the model, which is a worse experience than not offering
them. A correction is a **new** movement with reason `Correction` and a note,
recorded from the row it corrects.

Balances are read-only for the same reason one step down: every write goes
through a domain action, because anything incrementing a column directly makes
`LedgerIntegrity::discrepancies()` non-empty.

## What it contributes

| Surface | Covers |
| --- | --- |
| `Resources\StockMovementResource` (list) | The trail — filterable by item, location, type, reason, shrinkage and date, every row carrying the `on_hand_after` and `available_after` it recorded. Row action: record a correction |
| `Resources\StockLevelResource` (list) | On hand, reserved and available per item and location. Row actions: record a count, adjust, open the movement trail for that one balance |
| `Resources\StockReservationResource` (list) | Stock spoken for, the overdue holds a stalled sweep leaves behind, and releasing one |
| `Resources\InventoryLocationResource` (list, create, edit) | Where stock is held — the only thing here a panel may create, and where tenancy lives |
| `Widgets\StockOverview` | Locations, on hand, reserved, and whether the balances can be believed |
| `Widgets\LedgerDiscrepancies` | Balances that disagree with their own history, both numbers side by side, and the first movement where the trail stops adding up |

An operator comes here to answer one question — *why does this say 3 when the
shelf has 5* — so the movement trail is the centre of the package rather than a
sub-tab, and `LedgerIntegrity` is on the dashboard rather than in a document. A
discrepancy nobody sees is a stock count nobody trusts.

Every write goes through a `Liberu\Ecommerce\InventoryLedger\Actions\*` action, so
`StockMovementRecorded` and `ReservationStatusChanged` are dispatched from the
panel exactly as they are from anywhere else. Reason codes come from
`MovementReason::cases()` and the note is required exactly when `requiresNote()`
says so, rather than from a list kept here that would drift.

## Requirements

- **PHP 8.5**
- **Composer 2**
- A supported database (e.g. MySQL, PostgreSQL, SQLite)

## Quick start

The domain package this presents is not on Packagist, so add its repository
alongside the requirement:

```bash
composer config repositories.ecommerce-inventory-ledger vcs https://github.com/liberusoftware/module-ecommerce-inventory-ledger
composer require liberusoftware/ecommerce-inventory-ledger-filament
```

Installing boots nothing — the package ships no `extra.laravel.providers`. Enable
the module and the domain module it presents:

```dotenv
MODULES_ENABLED=ecommerce-inventory-ledger,ecommerce-inventory-ledger-filament
```

Then attach the plugin to whichever panel should carry these resources. The
application owns its panels; this package never registers itself into one:

```php
use Filament\Panel;
use Filament\PanelProvider;
use Liberu\Ecommerce\InventoryLedger\Filament\InventoryLedgerPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->plugins([
                InventoryLedgerPlugin::make(),
            ]);
    }
}
```

The plugin's id is `liberu-ecommerce-inventory-ledger`, and `module.json` declares
it under `presentation.filament.admin`.

The resources scope themselves to the team the panel user is working in — the
`current_team_id` `InventoryLocationPolicy` reads — rather than to Filament's
tenant, so the plugin works on a panel with no tenancy configured at all.

## Documentation

- [Adoption and upgrade](docs/adoption.md) — installing, enabling the module,
  attaching the plugin to a panel, and what the application must provide
- [Presentation](docs/presentation.md) — the surface inventory, navigation,
  authorization, the domain action behind every write, theme integration,
  accessibility, and what is deliberately left out
- [Runbook](docs/runbook.md) — production failure modes and the operator's
  response to each
- [Changelog](CHANGELOG.md) — release notes
- [Liberu Main Documentation](https://github.com/liberusoftware/documentation)
- [Architecture & Standards Index](https://github.com/liberusoftware/documentation/tree/main/architecture)

## Related Liberu Projects

| Project | Repository | Purpose |
| --- | --- | --- |
| **Boilerplate** | [liberusoftware/boilerplate-laravel](https://github.com/liberusoftware/project-boilerplate-laravel-filament) | Shared Laravel application foundation and reference composition |
| **CMS** | [liberu-cms/cms-laravel](https://github.com/liberu-cms/cms-laravel) | Structured content, publishing, media, multisite, and headless delivery |
| **CRM** | [liberu-crm/crm-laravel](https://github.com/liberu-crm/crm-laravel) | Customer data, sales, marketing, service, and customer success |
| **Billing** | [liberu-billing/billing-laravel](https://github.com/liberu-billing/billing-laravel) | Products, subscriptions, invoicing, payments, and provisioning |
| **Accounting** | [liberu-accounting/accounting-laravel](https://github.com/liberu-accounting/accounting-laravel) | Ledgers, banking, tax, expenses, close, and financial reporting |
| **Ecommerce** | [liberu-ecommerce/ecommerce-laravel](https://github.com/liberu-ecommerce/ecommerce-laravel) | Catalog, checkout, orders, fulfillment, returns, B2B, and omnichannel commerce |
| **Control Panel** | [liberu-control-panel/control-panel-laravel](https://github.com/liberu-control-panel/control-panel-laravel) | Hosting, infrastructure, DNS, mail, databases, backups, and security operations |
| **Automation** | [liberu-automation/automation-laravel](https://github.com/liberu-automation/automation-laravel) | Governed workflows, provider-neutral AI, approvals, and connectors |

## Security

Please do not report security vulnerabilities through public GitHub issues.
Follow our [Security Policy](https://github.com/liberusoftware/documentation/blob/main/architecture/SECURITY.md) for private reporting and supported versions.

## License

This project is open-source software. You may use, modify, and distribute it
under the terms described in [LICENSE.md](LICENSE.md).

The linked license text is authoritative; this summary is not legal advice.

## Feedback and contributing

Feedback and contributions are welcome. You can help by reporting reproducible
bugs, proposing focused enhancements, improving documentation or translations,
and submitting tested code changes.

Before contributing, please read [CONTRIBUTING.md](https://github.com/liberusoftware/documentation/blob/main/standards/CONTRIBUTING.md) and our
[Code of Conduct](https://github.com/liberusoftware/documentation/blob/main/architecture/CODE_OF_CONDUCT.md). Search existing issues first, then use
the appropriate issue template. Pull requests should explain the problem and
approach, remain focused, include or update tests, pass the required workflows,
and document user-visible or breaking changes.

## Contributors

Thank you to everyone who helps improve Liberu.

<a href="https://github.com/liberusoftware/module-ecommerce-inventory-ledger-filament/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=liberusoftware/module-ecommerce-inventory-ledger-filament" alt="Contributors to liberusoftware/module-ecommerce-inventory-ledger-filament">
</a>

[View the full contributors graph](https://github.com/liberusoftware/module-ecommerce-inventory-ledger-filament/graphs/contributors).

<!-- module-presentation-guide:begin -->
## Presentation architecture and project hosts

- Capability/source key: `planned:ecommerce:inventory-ledger`.
- Actual Composer identity: `liberusoftware/ecommerce-inventory-ledger-filament`.
- [Filament capability plan](https://github.com/liberusoftware/documentation/blob/main/projects/ecommerce/filament/inventory-ledger.md) · [Filament standard](https://github.com/liberusoftware/documentation/blob/main/standards/FILAMENT.md) · [Module architecture](https://github.com/liberusoftware/documentation/blob/main/architecture/MODULES.md).

The application owns panel composition, routing, session integration and deployment. This module owns its focused presentation and delegates domain behavior to reviewed public owner contracts. Keep host App aliases, duplicate records and client-only authorization outside reusable package boundaries.

Documented consumer project families:

- [Ecommerce](https://github.com/liberusoftware/project-ecommerce-laravel-filament): Catalogs, checkout, orders and fulfillment.

Other web, mobile and desktop presentations are listed in the [project catalog](https://github.com/liberusoftware/documentation/blob/main/projects/REPOSITORIES.md). Those skeletons provide starting structures; links do not establish feature parity, compatible releases or completed device/API integrations. Independent presentation packages keep their own Composer, npm or pub metadata.
<!-- module-presentation-guide:end -->
