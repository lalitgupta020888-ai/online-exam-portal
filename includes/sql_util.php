<?php
/**
 * Helpers shared by install.php and upgrade.php.
 */

/**
 * Split a SQL dump into statements while respecting quoted strings,
 * backtick identifiers and comments (a naive explode(';') would break on
 * every semicolon that appears inside a text value).
 */
function split_sql(string $sql): array
{
    $statements = [];
    $buffer     = '';
    $len        = strlen($sql);
    $quote      = null;          // active quote character, if any

    for ($i = 0; $i < $len; $i++) {
        $ch   = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ($quote === null) {
            // Line comment: -- ... or # ...
            if (($ch === '-' && $next === '-') || $ch === '#') {
                while ($i < $len && $sql[$i] !== "\n") { $i++; }
                $buffer .= "\n";
                continue;
            }
            // Block comment: /* ... */
            if ($ch === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i   = $end === false ? $len : $end + 1;
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            } elseif ($ch === ';') {
                if (trim($buffer) !== '') { $statements[] = trim($buffer); }
                $buffer = '';
                continue;
            }
        } else {
            if ($ch === '\\') {              // escaped character inside a string
                $buffer .= $ch . $next;
                $i++;
                continue;
            }
            if ($ch === $quote) {
                if ($next === $quote) {      // doubled quote = literal quote
                    $buffer .= $ch . $next;
                    $i++;
                    continue;
                }
                $quote = null;
            }
        }
        $buffer .= $ch;
    }

    if (trim($buffer) !== '') { $statements[] = trim($buffer); }
    return $statements;
}

/** True when $table already has a column named $column. */
function column_exists(PDO $pdo, string $db, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$db, $table, $column]);
    return (int)$st->fetchColumn() > 0;
}

/** True when $table already has an index/constraint named $index. */
function index_exists(PDO $pdo, string $db, string $table, string $index): bool
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $st->execute([$db, $table, $index]);
    return (int)$st->fetchColumn() > 0;
}
