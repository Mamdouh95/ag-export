# agriserv/exports

Queued spreadsheet exports for Laravel. Wraps `maatwebsite/excel` with an
`ExportJob` tracking row and a Livewire status UI so requests don't time out
on heavy reports.

## Install (path repository)

In the host app's `composer.json`:

```json
{
  "repositories": [
    { "type": "path", "url": "../packages/agriserv/exports" }
  ],
  "require": {
    "agriserv/exports": "*"
  }
}
```

Then:

```bash
composer require agriserv/exports:*
php artisan vendor:publish --tag=exports-config        # optional
php artisan vendor:publish --tag=exports-migrations    # optional
php artisan migrate
```

## Configure (env)

```dotenv
EXPORTS_DISK=local          # any filesystem disk
EXPORTS_PATH=exports        # subfolder on that disk
EXPORTS_QUEUE=exports       # queue name workers listen on
EXPORTS_QUEUE_CONNECTION=   # leave blank to use default
EXPORTS_TRIES=3
EXPORTS_TIMEOUT=1800
EXPORTS_RETENTION_DAYS=7
EXPORTS_ROUTE_PREFIX=exports
```

Make sure a queue worker listens on the configured queue:

```bash
php artisan queue:work --queue=exports
```

## Usage

Replace synchronous `Excel::download(...)` calls with:

```php
use Agriserv\Exports\Facades\QueuedExports;
use App\Exports\InvoicesExport;

public function export()
{
    $query = Invoice::query()->filter(request()->all()); // your existing Builder

    $job = QueuedExports::queue(
        new InvoicesExport($query),
        filename: 'invoices-' . now()->format('Y-m-d-His') . '.xlsx',
        options: [
            'label' => 'تصدير الفواتير',
            // 'user' => auth()->user(),   // defaults to auth()->user()
            // 'disk' => 's3',             // override default disk
        ],
    );

    return back()->with('status', "تم إضافة طلب التصدير إلى الطابور (#{$job->id}).");
}
```

The user sees their export appear in the status component:

```blade
<livewire:exports.list />
```

The component polls every 4s while any export is pending/processing,
then shows a download button (signed URL, 30 min) when complete.

## Pruning

```bash
php artisan exports:prune              # uses config retention_days
php artisan exports:prune --days=30
php artisan exports:prune --dry-run
```

Schedule it in `routes/console.php`:

```php
Schedule::command('exports:prune')->dailyAt('03:00');
```

## Notes on existing exports

The existing export classes in `ratings`, `invoices`, and `appointments`
don't need changes — they're already plain Maatwebsite exports. The only
caveat for queueing: the export instance is serialized onto the queue,
so its constructor properties (Eloquent Builders, Collections) must be
serializable. **Don't put closures inside the query** — rebuild the
query from saved filters inside the export class instead.

For large datasets, add `implements WithChunkReading` and a `chunkSize()`
method to your `FromQuery` export so it reads in batches rather than
loading everything into memory.
