<?php

declare(strict_types=1);

namespace Superscript\Axiom\Lookup\Support\Sqlite;

use Superscript\Axiom\Lookup\Readers\SqliteLookupSourceReader;

/**
 * The shared contract between {@see SqliteLookupConverter} (which writes a
 * sidecar) and {@see SqliteLookupSourceReader} (which reads one). Everything
 * a reader must know to answer a lookup lives inside the artefact itself —
 * the meta table names the format version, the source fingerprint, the
 * header layout, and which columns carry an index — so the sidecar needs no
 * companion description and can be validated wherever it turns up.
 *
 * Records are stored one column per CSV cell, always as TEXT: SQLite keeps
 * the bytes exactly as League\Csv parsed them (including non-UTF-8 cells),
 * so a reconstructed record is byte-identical to what a full CSV scan would
 * have yielded. NULL marks a cell the original row never had — with a
 * header that is League's own padding of short rows; without one it is the
 * gap between a short row and the widest row in the file.
 */
final class SqliteLookupFormat
{
    /** Bump when the table layout changes; readers rebuild versions they don't know. */
    public const string VERSION = '1';

    public const string RECORDS_TABLE = 'records';

    public const string META_TABLE = '_axiom_meta';

    public const string HEADER_TABLE = '_axiom_header';

    /** The row-order column: original CSV row number, the file's fold order. */
    public const string ROW_COLUMN = 'rn';

    public static function column(int $position): string
    {
        return 'c' . $position;
    }

    public static function index(int $position): string
    {
        return 'ax_idx_c' . $position;
    }
}
