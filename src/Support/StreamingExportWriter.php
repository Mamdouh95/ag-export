<?php

namespace Agriserv\Exports\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AbstractWriterMultiSheets;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\ODS\Options as OdsOptions;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;
use Throwable;

/**
 * Writes an export row-by-row straight to a file handle, so peak memory stays
 * flat regardless of how many rows the query returns.
 *
 * Contrast with the maatwebsite path (Sheet::fromQuery -> appendRows): that
 * chunks the *database read* but still accumulates every cell in a single
 * in-memory PhpSpreadsheet worksheet until the file is written at the end.
 */
class StreamingExportWriter
{
    /**
     * xlsx/ods cap out at 1,048,576 rows per sheet. Reserve one for the header
     * so a repeated heading row can't push us over on a rollover.
     */
    private const MAX_ROWS_PER_SHEET = 1048575;

    /**
     * Rows allowed on one sheet before rolling over to a new one. Overridable
     * so the rollover can be exercised without generating a million rows.
     */
    protected function maxRowsPerSheet(): int
    {
        return self::MAX_ROWS_PER_SHEET;
    }

    /**
     * Stream $export into $relativePath on $disk.
     *
     * @return int number of data rows written (headers excluded)
     */
    public function write(object $export, string $relativePath, string $disk, string $format): int
    {
        $format = strtolower($format);
        $local = $this->temporaryPath($format);

        try {
            $writer = $this->makeWriter($export, $format);
            $writer->openToFile($local);

            $headings = $export instanceof WithHeadings
                ? array_values($this->flattenHeadings($export->headings()))
                : [];

            $rightToLeft = $this->wantsRightToLeft($export);
            $multiSheet = $writer instanceof AbstractWriterMultiSheets;

            $this->prepareSheet($writer, $headings, $rightToLeft, 1);

            $written = 0;
            $onSheet = 0;
            $sheetNumber = 1;

            foreach ($this->records($export) as $record) {
                foreach ($this->rowsFor($export, $record) as $values) {
                    if ($multiSheet && $onSheet >= $this->maxRowsPerSheet()) {
                        $writer->addNewSheetAndMakeItCurrent();
                        $this->prepareSheet($writer, $headings, $rightToLeft, ++$sheetNumber);
                        $onSheet = 0;
                    }

                    $writer->addRow(new Row($this->cells($values)));
                    $written++;
                    $onSheet++;
                }
            }

            $writer->close();

            $this->moveToDisk($local, $relativePath, $disk);

            return $written;
        } finally {
            if (is_file($local)) {
                @unlink($local);
            }
        }
    }

    /**
     * Iterate the export's query without holding the result set in memory.
     *
     * Default is keyset pagination on the primary key, newest first. Any ORDER
     * BY the export set is dropped for that mode: lazyById only strips existing
     * orders for the id column, so a leftover `order by other_column` would
     * take precedence and silently break the keyset walk (rows skipped or
     * repeated), on top of re-running that sort for every chunk.
     *
     * An export that needs its own ordering can return 'query' from
     * streamOrder(). That keeps the ORDER BY and pages with LIMIT/OFFSET, which
     * is fine for moderate result sets but degrades on deep offsets — and, like
     * any offset paging, can skip or repeat rows if the sort is not total.
     */
    protected function records(object $export): LazyCollection
    {
        if (! method_exists($export, 'query')) {
            throw new RuntimeException(sprintf(
                '%s must implement Maatwebsite\Excel\Concerns\FromQuery to be streamed.',
                $export::class
            ));
        }

        $query = $export->query();

        if (! $query instanceof Builder) {
            throw new RuntimeException(sprintf(
                '%s::query() must return an Eloquent builder to be streamed, got %s.',
                $export::class,
                get_debug_type($query)
            ));
        }

        $chunkSize = method_exists($export, 'streamChunkSize')
            ? max(1, (int) $export->streamChunkSize())
            : max(1, (int) config('exports.stream.chunk_size', 1000));

        $mode = method_exists($export, 'streamOrder')
            ? strtolower((string) $export->streamOrder())
            : 'desc';

        return match ($mode) {
            'query' => $query->lazy($chunkSize),
            'asc' => $query->reorder()->lazyById($chunkSize),
            'desc' => $query->reorder()->lazyByIdDesc($chunkSize),
            default => throw new RuntimeException(sprintf(
                '%s::streamOrder() must return "desc", "asc" or "query", got "%s".',
                $export::class,
                $mode
            )),
        };
    }

    /**
     * Turn one record into zero or more output rows.
     *
     * A WithMapping export normally returns a single row, but it is allowed to
     * return a list of rows (one model fanning out into many), or an empty
     * array for "skip this record" — same contract as maatwebsite.
     *
     * @return array<int, array<int, mixed>>
     */
    protected function rowsFor(object $export, mixed $record): array
    {
        if (! $export instanceof WithMapping) {
            return [array_values($record instanceof \Illuminate\Database\Eloquent\Model
                ? $record->attributesToArray()
                : (array) $record)];
        }

        $mapped = $export->map($record);

        if (! is_array($mapped) || $mapped === []) {
            return [];
        }

        $first = $mapped[array_key_first($mapped)] ?? null;

        if (is_array($first)) {
            return array_map(static fn ($row) => array_values((array) $row), $mapped);
        }

        return [array_values($mapped)];
    }

    /**
     * Build typed cells.
     *
     * Strings are always written as strings — never re-parsed as numbers or
     * formulas. That keeps `0501234567` and `0000483275` intact instead of
     * losing the leading zeros (or turning into 5.01234E+08) the way Excel
     * does when it parses a CSV, and it stops a customer-entered value that
     * starts with "=" from being evaluated as a formula.
     *
     * @param  array<int, mixed>  $values
     * @return array<int, Cell>
     */
    protected function cells(array $values): array
    {
        $cells = [];

        foreach ($values as $value) {
            $cells[] = match (true) {
                $value === null, $value === '' => new Cell\EmptyCell(null, null),
                is_bool($value) => new Cell\BooleanCell($value, null),
                is_int($value), is_float($value) => new Cell\NumericCell($value, null),
                $value instanceof \DateTimeInterface => new Cell\DateTimeCell($value, $this->dateStyle()),
                is_string($value) => new Cell\StringCell($value, null),
                default => new Cell\StringCell($this->stringify($value), null),
            };
        }

        return $cells;
    }

    /**
     * Dates need an explicit number format — OpenSpout writes the raw Excel
     * serial otherwise, so the cell shows up as 45678.5 instead of a date.
     */
    protected function dateStyle(): Style
    {
        static $style = null;

        return $style ??= (new Style)->setFormat(
            (string) config('exports.stream.date_format', 'yyyy-mm-dd hh:mm')
        );
    }

    protected function headingStyle(): Style
    {
        static $style = null;

        return $style ??= (new Style)->setFontBold();
    }

    protected function makeWriter(object $export, string $format): WriterInterface
    {
        $tempFolder = config('exports.stream.temp_folder');

        return match ($format) {
            'csv' => new CsvWriter($this->csvOptions($export)),
            'xlsx' => new XlsxWriter(tap(new XlsxOptions, function (XlsxOptions $options) use ($tempFolder) {
                if ($tempFolder) {
                    $options->setTempFolder($tempFolder);
                }
            })),
            'ods' => new OdsWriter(tap(new OdsOptions, function (OdsOptions $options) use ($tempFolder) {
                if ($tempFolder) {
                    $options->setTempFolder($tempFolder);
                }
            })),
            default => throw new RuntimeException(
                "Streaming exports support xlsx, csv and ods. Got \"{$format}\"."
            ),
        };
    }

    /**
     * Honours maatwebsite's WithCustomCsvSettings so an export keeps the same
     * delimiter/BOM behaviour it had on the old path.
     */
    protected function csvOptions(object $export): CsvOptions
    {
        $options = new CsvOptions;

        $settings = method_exists($export, 'getCsvSettings') ? $export->getCsvSettings() : [];

        $options->SHOULD_ADD_BOM = (bool) ($settings['use_bom'] ?? true);

        if (! empty($settings['delimiter'])) {
            $options->FIELD_DELIMITER = $settings['delimiter'];
        }

        if (! empty($settings['enclosure'])) {
            $options->FIELD_ENCLOSURE = $settings['enclosure'];
        }

        return $options;
    }

    /**
     * @param  array<int, string>  $headings
     */
    protected function prepareSheet(WriterInterface $writer, array $headings, bool $rightToLeft, int $sheetNumber): void
    {
        if ($writer instanceof XlsxWriter) {
            $sheet = $writer->getCurrentSheet();

            $sheet->setSheetView(
                (new SheetView)->setRightToLeft($rightToLeft)->setFreezeRow(2)
            );

            if ($sheetNumber > 1) {
                $sheet->setName('Sheet '.$sheetNumber);
            }
        }

        if ($headings !== []) {
            $writer->addRow(new Row($this->cells($headings), $this->headingStyle()));
        }
    }

    protected function wantsRightToLeft(object $export): bool
    {
        if (method_exists($export, 'streamRightToLeft')) {
            return (bool) $export->streamRightToLeft();
        }

        return (bool) config('exports.stream.right_to_left', true);
    }

    /**
     * WithHeadings may return a single heading row or several stacked rows;
     * the streaming writer only supports a single row, so take the last one
     * (the one sitting directly above the data).
     *
     * @param  array<mixed>  $headings
     * @return array<int, mixed>
     */
    protected function flattenHeadings(array $headings): array
    {
        if ($headings === []) {
            return [];
        }

        $first = $headings[array_key_first($headings)] ?? null;

        if (is_array($first)) {
            $last = end($headings);

            return is_array($last) ? array_values($last) : [];
        }

        return array_values($headings);
    }

    protected function moveToDisk(string $localPath, string $relativePath, string $disk): void
    {
        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to read the generated export at {$localPath}.");
        }

        try {
            Storage::disk($disk)->writeStream($relativePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    protected function temporaryPath(string $format): string
    {
        $path = tempnam(sys_get_temp_dir(), 'agriserv-export-');

        if ($path === false) {
            throw new RuntimeException('Unable to allocate a temporary file for the export.');
        }

        // OpenSpout picks the writer from the handle, not the name, but keeping
        // the extension makes stray temp files identifiable.
        $withExtension = $path.'.'.$format;

        if (@rename($path, $withExtension)) {
            return $withExtension;
        }

        return $path;
    }

    protected function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn ($item) => $this->stringify($item), $value));
        }

        if ($value instanceof \Stringable || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        if (is_object($value)) {
            try {
                return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return '';
            }
        }

        return (string) $value;
    }
}
