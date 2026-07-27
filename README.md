# agriserv/exports

Queued spreadsheet exports for Laravel. Wraps `maatwebsite/excel` with an
`ExportJob` tracking row and a Livewire status UI so requests don't time out
on heavy reports.

## Install

```bash
composer require agriserv/exports
php artisan vendor:publish --tag=exports-config        # optional
php artisan vendor:publish --tag=exports-migrations    # optional
php artisan migrate
```

The service provider and `QueuedExports` facade are auto-discovered.

Requires PHP ^8.2, Laravel 10/11/12, `maatwebsite/excel` ^3.1, and
`livewire/livewire` ^3.0.

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

# streaming writer (see "Streaming large exports")
EXPORTS_STREAM_CHUNK=1000
EXPORTS_STREAM_RTL=true
EXPORTS_STREAM_DATE_FORMAT="yyyy-mm-dd hh:mm"
EXPORTS_STREAM_TEMP=            # blank = system temp; point at a roomy volume if /tmp is small
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
            // 'format' => 'xlsx',         // xlsx (default) | csv | tsv | ods | html
        ],
    );

    return back()->with('status', "تم إضافة طلب التصدير إلى الطابور (#{$job->id}).");
}
```

### Picking a format

| Format | When to use |
|---|---|
| `xlsx` (default) | Most cases, and the right answer for anything a human opens in Excel. |
| `csv` | Machine-to-machine handoffs. Note Excel *parses* a CSV on open: leading zeros are stripped (`0501234567` → `501234567`), long digit strings go scientific, a leading `=` is evaluated as a formula, and on Arabic Windows the list separator is `;` so a comma-delimited file lands entirely in column A. `['use_bom' => true]` fixes the encoding, not any of the rest. |
| `tsv` / `ods` / `html` | Niche. `tsv`/`html` are maatwebsite-only (see streaming below). |

## Streaming large exports

By default an export is written by maatwebsite/PhpSpreadsheet, which chunks the
*database read* but still accumulates every cell in one in-memory worksheet
until the file is saved at the end. Peak memory runs ~615 bytes per cell, so it
grows linearly with rows × columns and eventually OOMs the worker.

Add the `ShouldStream` marker and the export is written row-by-row with
OpenSpout instead. Peak memory goes flat:

```php
use Agriserv\Exports\Contracts\ShouldStream;

class RatingsExport implements FromQuery, WithHeadings, WithMapping, ShouldStream
{
    // query() / headings() / map() unchanged
}
```

Measured on a 26-column report:

| Rows | maatwebsite | streamed |
|---|---|---|
| 24,882 | 15.1s / 394 MB | 10.7s / 54 MB |
| 115,732 | ~70s / ~1.85 GB | 50.2s / 56 MB |

Memory stays ~55 MB whatever the row count, so rows stop being the constraint.

**What you get on this path**

- Real xlsx at any size — no need to fall back to csv for memory reasons.
- RTL sheet direction and a frozen, bold heading row (config `exports.stream`).
- Strings stay strings. `0501234567` and `00012345` keep their leading zeros,
  and a value starting with `=` is written as inert text rather than a formula.
- Dates are written as real date cells with a number format, so Excel shows a
  date instead of the raw serial (`46212.27`).
- Sheets roll over automatically at the 1,048,576-row xlsx limit, repeating the
  heading row on each new sheet.
- `total_rows` is recorded on the `ExportJob` and shown in the status UI.

**Requirements and limits**

- The export must implement `FromQuery` returning an **Eloquent** builder.
  `FromArray`, `FromCollection` and Scout builders stay on the maatwebsite path.
- Formats: `xlsx`, `csv`, `ods`. Asking for `tsv`/`html` with `ShouldStream`
  throws at dispatch time.
- `WithEvents`, `WithStyles`, `WithColumnFormatting`, `WithCustomValueBinder`
  and `WithDrawings` are ignored — they need a live PhpSpreadsheet worksheet.
  Keep genuinely styled exports (small ones) on the default path.
- **Ordering changes.** By default the writer walks the primary key newest-first
  and drops the export's own `ORDER BY`, because `lazyById` only strips existing
  orders for the id column — a leftover `order by other_column` would take
  precedence and silently break the keyset walk. If the sort matters, or the
  model has no usable primary key (a SQL view, say), return `'query'` from
  `streamOrder()` to keep the `ORDER BY` and page with `LIMIT`/`OFFSET` instead.
  Make that sort total or offset paging can skip rows.

**Optional methods** picked up when present on the export:

| Method | Default | Purpose |
|---|---|---|
| `streamChunkSize(): int` | `exports.stream.chunk_size` (1000) | Rows per DB round trip. Raise it for `'query'` mode, where each page re-runs the query. |
| `streamOrder(): string` | `'desc'` | `'desc'`/`'asc'` keyset walk on the primary key, or `'query'` to keep the export's own ordering. |
| `streamRightToLeft(): bool` | `exports.stream.right_to_left` (true) | RTL sheet direction for xlsx. |
| `getCsvSettings(): array` | — | maatwebsite's `WithCustomCsvSettings`; `use_bom`, `delimiter`, `enclosure` are honoured. |

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

Your existing Maatwebsite export classes work as-is — pass them through
`QueuedExports::queue()` instead of `Excel::download()`. The only caveat
for queueing: the export instance is serialized onto the queue, so its
constructor properties (Eloquent Builders, Collections) must be
serializable. **Don't put closures inside the query** — rebuild the
query from saved filters inside the export class instead.

`WithChunkReading` + `chunkSize()` only affects how rows are read from the
database — every row still ends up in the same in-memory worksheet, so it does
not bound peak memory. For large datasets use `ShouldStream` (above); once an
export is streamed, `WithChunkReading` is redundant and `streamChunkSize()`
takes over.
