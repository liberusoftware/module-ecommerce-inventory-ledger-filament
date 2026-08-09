# Adoption

What a host application does to get these screens, and what it is signing up for.

## Installing

The domain package this presents is **not on Packagist**. Composer honours
`repositories` only from the root manifest, so the application declares it —
this package declaring it does not help its consumer:

```bash
composer config repositories.ecommerce-inventory-ledger vcs https://github.com/liberusoftware/module-ecommerce-inventory-ledger
composer require liberusoftware/ecommerce-inventory-ledger-filament
```

Remove that `repositories` entry once the domain package reaches Packagist;
nothing else changes.

## Enabling

Installing boots nothing. Neither package ships `extra.laravel.providers`, so
`ModuleManagerServiceProvider` is the only registrar and it acts only on what the
deployment names:

```dotenv
MODULES_ENABLED=ecommerce-inventory-ledger,ecommerce-inventory-ledger-filament
```

Both, in that order. This package presents the domain module; with the domain
module disabled there are no tables to read and no policy to authorize against.

Confirm with `php artisan module:list`.

## Migrating

The migrations belong to the **domain** package and run with it:

```bash
php artisan migrate
```

This package ships no migration, no config file and no publishable asset.

## Attaching the plugin to a panel

The application owns its panels. This package never registers itself into one:

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

The plugin's id is `liberu-ecommerce-inventory-ledger`, and `module.json`
declares it under `presentation.filament.admin`. Attach it to as many panels as
should carry these screens; attach it to none and the package is inert.

The two widgets are contributed to the panel and appear on its dashboard
according to the panel's own widget configuration.

## What the application must provide

**A `current_team_id` on the authenticatable.** Everything here is scoped and
authorized by it — it is the column `InventoryLocationPolicy` reads. An actor
without one sees nothing at all, which is deliberate rather than a
misconfiguration to work around: an unowned location is nobody's, and the scope
refuses it as firmly as the policy does.

Filament's own tenancy is **not** required. The resources set
`isScopedToTenant(false)` and scope against the column instead, so the plugin
works on a panel with no tenancy configured.

**Somewhere for stock to live.** Create a location before expecting balances:
Inventory › Locations › New. Everything else appears as stock moves.

**A scheduled expiry sweep**, if anything reserves stock:

```php
Schedule::call(fn () => app(ReleaseExpiredReservations::class)->handle())->everyFiveMinutes();
```

Without it, holds accumulate and available-to-sell falls without on-hand moving.
The Holds screen's *Overdue* filter is how that shows up here, but the fix is the
scheduler's.

## What the operator gets, and what they do not

They get the ledger, filterable, with the balance every row recorded — and a
correction that appends rather than edits.

They do **not** get a way to edit or delete a movement, or to type a balance in
directly. That is the module's design and not a missing feature; see
[presentation.md](presentation.md). If an integration needs to rewrite history,
the integration is the problem.

Receiving, issuing and transferring are not surfaced either — they belong to
purchasing, fulfilment and warehouse operations. `Adjust`, with a reason, covers
hand entry.

## Upgrading

`0.1.0` is the first release. Pre-1.0.0 the surfaces may change within a minor
version; the [changelog](../CHANGELOG.md) says when.

This package pins `liberusoftware/ecommerce-inventory-ledger` at `^0.1`. A
`0.2.0` of the domain package will need a release here to admit it — deliberately,
because a presentation package that floated across a pre-1.0 domain boundary
would break in the panel rather than in CI.
