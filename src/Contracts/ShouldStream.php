<?php

namespace Agriserv\Exports\Contracts;

/**
 * Marker interface: write this export with the streaming writer (OpenSpout)
 * instead of building a PhpSpreadsheet worksheet in memory.
 *
 * Peak memory becomes flat (~55 MB) instead of growing ~615 bytes per cell,
 * so row count stops being the limiting factor.
 *
 * The export must also implement maatwebsite's `FromQuery` (an Eloquent
 * builder — not Scout, not a Collection). `WithHeadings` and `WithMapping`
 * are honoured when present.
 *
 * Optional methods the streaming writer picks up when they exist on the export:
 *
 *   streamChunkSize(): int      Rows per DB round trip. Default config('exports.stream.chunk_size').
 *   streamOrder(): string       'desc' (default) / 'asc' — keyset walk on the primary key, the
 *                               export's own ORDER BY is dropped. 'query' — keep the export's
 *                               ORDER BY and page with LIMIT/OFFSET instead; use it when the sort
 *                               matters (or the model has no usable primary key, e.g. a SQL view),
 *                               and keep the sort total or offset paging may skip rows.
 *   streamRightToLeft(): bool   RTL sheet for xlsx. Default config('exports.stream.right_to_left').
 *   getCsvSettings(): array     maatwebsite's WithCustomCsvSettings — use_bom / delimiter / enclosure.
 *
 * Not supported on this path: WithEvents, WithStyles, WithColumnFormatting,
 * WithCustomValueBinder, WithDrawings and anything else that needs a live
 * PhpSpreadsheet worksheet. Use RTL + heading bold via the options above, or
 * keep the export on the maatwebsite path if it needs real styling.
 *
 * @see \Agriserv\Exports\Support\StreamingExportWriter
 */
interface ShouldStream
{
}
