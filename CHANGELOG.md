# Changelog

## v0.4.0

Adds a streaming writer so large exports stop being bounded by worker memory,
which in turn makes real `.xlsx` viable at any row count instead of falling
back to `.csv`.

### Added

- **`Agriserv\Exports\Contracts\ShouldStream`.** A marker interface on an
  export routes it through `Support\StreamingExportWriter` (OpenSpout), which
  writes row-by-row to a file handle instead of assembling a PhpSpreadsheet
  worksheet in memory. Peak memory goes flat at ~55 MB regardless of row count.

  Measured on a 26-column report: 24,882 rows went 15.1s / 394 MB → 10.7s /
  54 MB; 115,732 rows went ~70s / ~1.85 GB → 50.2s / 56 MB.

- **Correct value typing on the streamed path.** Strings are always written as
  strings, so `0501234567` and `00012345` keep their leading zeros instead of
  being coerced to numbers, and a value beginning with `=` is written as inert
  text rather than a formula. Dates are written as real date cells carrying a
  number format, so Excel renders a date instead of the raw serial (`46212.27`).

- **RTL sheets and a frozen, bold heading row** for streamed xlsx, replacing
  the `WithEvents`/`AfterSheet` dance that only worked on the maatwebsite path.

- **Automatic sheet rollover** at the 1,048,576-row xlsx limit, repeating the
  heading row and RTL setting on each new sheet.

- **`total_rows`** is now populated on `ExportJob` for streamed exports and
  displayed in the Livewire status list.

- **`exports.stream` config block**: `chunk_size`, `right_to_left`,
  `date_format`, `temp_folder` (`EXPORTS_STREAM_*`).

- Optional per-export overrides, picked up when defined: `streamChunkSize()`,
  `streamOrder()`, `streamRightToLeft()`. `getCsvSettings()` (maatwebsite's
  `WithCustomCsvSettings`) is honoured for `use_bom`, `delimiter`, `enclosure`.

### Changed

- `ProcessExportJob` takes a fourth constructor argument, `?string $format`,
  and branches on `ShouldStream`. Exports without the marker keep going through
  `Excel::store()` unchanged.
- `QueuedExports::queue()` validates the format for streamed exports at
  dispatch time rather than letting the worker fail, and forwards the plain
  format string to the job.
- Streamed exports are written to a local temp file and moved to the target
  disk with `writeStream()`, so remote disks no longer buffer the whole file.

### Fixed

- README claimed the `csv` format "streams cell-by-cell, memory stays flat
  regardless of row count". It never did on the maatwebsite path:
  `Sheet::fromQuery()` chunks the database read but `appendRows()` still
  accumulates every cell in one worksheet, and the Csv writer only serialises
  that finished worksheet. Format choice barely moved peak memory. Documented
  accurately, and `ShouldStream` now makes the claim true.
- README recommended `WithChunkReading` for large datasets as if it bounded
  memory. It only affects the database read; noted as such.

### Upgrading

`composer require agriserv/exports:^0.4.0`, then add `ShouldStream` to the
`FromQuery` exports you want streamed. Nothing else changes: exports without
the marker behave exactly as before.

Two things to know before you add the marker:

- **Ordering.** The writer walks the primary key newest-first and drops the
  export's own `ORDER BY` (`lazyById` only strips existing orders for the id
  column, so a leftover sort would take precedence and silently break the
  keyset walk). Return `'query'` from `streamOrder()` to keep your ordering and
  page with offsets instead — needed for models with no usable primary key,
  such as SQL views.
- **Styling concerns are ignored** on this path (`WithEvents`, `WithStyles`,
  `WithColumnFormatting`, `WithCustomValueBinder`, `WithDrawings`). Leave small
  styled exports on the default writer.

`ProcessExportJob` gained a constructor argument. Jobs already sitting on the
`exports` queue during deploy deserialise with `format = null` and fall back to
the filename extension, so they still complete — but draining the queue first
avoids the question entirely.

## v0.3.0

- Added the `format` option (csv/xlsx/tsv/ods/html) to `QueuedExports::queue()`.

## v0.2.0

- Rewrote the exports list view in Tailwind.

## v0.1.x

- Initial release: queued exports with an `ExportJob` tracking row, Livewire
  status UI, signed download routes, and the `exports:prune` command.
