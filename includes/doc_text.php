<?php
/**
 * Plain text extraction from uploaded question papers.
 *
 * Supports PDF, DOCX and TXT with no external library and no PHP extension
 * beyond zlib, so it runs on a stock XAMPP install:
 *
 *   PDF  - inflates the content streams and walks the text showing operators
 *          (Tj / TJ / ' / "), applying any /ToUnicode CMap it finds.
 *   DOCX - reads word/document.xml with a small built in ZIP reader
 *          (ext-zip is disabled by default in XAMPP).
 *   TXT  - read as is.
 *
 * Known limit: a PDF that is a scan (a photograph of a page) contains no text
 * at all, and no amount of parsing can recover it - that needs OCR. The
 * importer detects this case and tells the user to paste the text instead.
 */

/* ------------------------------------------------------------------ */
/*  Entry point                                                        */
/* ------------------------------------------------------------------ */

/**
 * @return array{text:string,type:string,warning:string}
 */
function extract_document_text(string $path, string $originalName): array
{
    $ext  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $data = (string)file_get_contents($path);
    $warning = '';

    switch ($ext) {
        case 'pdf':
            $text = pdf_extract_text($data);
            break;
        case 'docx':
            $text = docx_extract_text($data);
            break;
        case 'txt':
        case 'text':
        case 'md':
            $text = $data;
            break;
        default:
            return ['text' => '', 'type' => $ext,
                    'warning' => 'Unsupported file type. Upload a PDF, DOCX or TXT file.'];
    }

    $text = normalise_text($text);

    if (trim($text) === '') {
        $warning = $ext === 'pdf'
            ? 'No text could be read from this PDF. It is most likely a scanned image, '
            . 'which needs OCR. Please paste the questions as text instead.'
            : 'No text could be read from this file.';
    } elseif (!looks_like_text($text)) {
        $warning = 'The text read from this file looks garbled - the PDF probably uses an '
                 . 'embedded font with no Unicode mapping. Check the preview carefully, '
                 . 'or paste the questions as text instead.';
    }

    return ['text' => $text, 'type' => $ext, 'warning' => $warning];
}

/** Tidy up line endings and runs of blank lines. */
function normalise_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/ *\n */', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim((string)$text);
}

/**
 * Rough sanity check: readable prose is mostly letters, digits, spaces and
 * punctuation. A badly decoded font produces mostly control/high bytes.
 */
function looks_like_text(string $text): bool
{
    $sample = substr($text, 0, 4000);
    $len    = strlen($sample);
    if ($len === 0) {
        return false;
    }
    $good = preg_match_all('/[A-Za-z0-9 \n\.\,\?\!\:\;\(\)\[\]\-\'"\/]/', $sample);
    return ($good / $len) > 0.75;
}

/* ------------------------------------------------------------------ */
/*  PDF                                                                */
/* ------------------------------------------------------------------ */

function pdf_extract_text(string $bin): string
{
    $streams = pdf_collect_streams($bin);

    // Merge every /ToUnicode CMap found in the file. Question papers are
    // normally set in one or two fonts, so a merged map is accurate enough
    // and avoids having to resolve font resources per page.
    $cmap = [];
    foreach ($streams as $s) {
        if (str_contains($s, 'beginbfchar') || str_contains($s, 'beginbfrange')) {
            $cmap += pdf_parse_cmap($s);
        }
    }

    $out = '';
    foreach ($streams as $s) {
        if (str_contains($s, 'BT') && (str_contains($s, 'Tj') || str_contains($s, 'TJ'))) {
            $out .= pdf_read_content_stream($s, $cmap) . "\n";
        }
    }
    return $out;
}

/** Pull every stream out of the file and inflate it when it is compressed. */
function pdf_collect_streams(string $bin): array
{
    $streams = [];
    $offset  = 0;

    while (($start = strpos($bin, 'stream', $offset)) !== false) {
        // Skip the "endstream" keyword itself.
        if ($start >= 3 && substr($bin, $start - 3, 3) === 'end') {
            $offset = $start + 6;
            continue;
        }
        $dataStart = $start + 6;
        if (substr($bin, $dataStart, 2) === "\r\n")      { $dataStart += 2; }
        elseif (substr($bin, $dataStart, 1) === "\n")    { $dataStart += 1; }
        elseif (substr($bin, $dataStart, 1) === "\r")    { $dataStart += 1; }

        $end = strpos($bin, 'endstream', $dataStart);
        if ($end === false) {
            break;
        }
        $raw = substr($bin, $dataStart, $end - $dataStart);
        $offset = $end + 9;

        $decoded = @gzuncompress($raw);
        if ($decoded === false) { $decoded = @gzinflate($raw); }
        if ($decoded === false) { $decoded = @gzinflate(substr($raw, 2)); }
        if ($decoded === false) { $decoded = $raw; }

        if ($decoded !== '') {
            $streams[] = $decoded;
        }
    }
    return $streams;
}

/** Build a code => character map from a /ToUnicode CMap stream. */
function pdf_parse_cmap(string $cmap): array
{
    $map = [];

    // <src> <dst>
    if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks)) {
        foreach ($blocks[1] as $block) {
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER)) {
                foreach ($pairs as $p) {
                    $map[strtoupper($p[1])] = pdf_hex_to_utf8($p[2]);
                }
            }
        }
    }

    // <first> <last> <dstStart>
    if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks)) {
        foreach ($blocks[1] as $block) {
            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/',
                               $block, $ranges, PREG_SET_ORDER)) {
                foreach ($ranges as $r) {
                    $from = hexdec($r[1]);
                    $to   = hexdec($r[2]);
                    $dst  = hexdec(substr($r[3], 0, 4));
                    $width = strlen($r[1]);
                    if ($to - $from > 65535) { continue; }        // corrupt range
                    for ($c = $from; $c <= $to; $c++) {
                        $key = strtoupper(str_pad(dechex($c), $width, '0', STR_PAD_LEFT));
                        $map[$key] = mb_chr($dst + ($c - $from), 'UTF-8') ?: '';
                    }
                }
            }
        }
    }
    return $map;
}

/** "0041" -> "A"; surrogate pairs and multi character targets handled. */
function pdf_hex_to_utf8(string $hex): string
{
    $out = '';
    for ($i = 0; $i + 3 < strlen($hex) + 1; $i += 4) {
        $chunk = substr($hex, $i, 4);
        if (strlen($chunk) < 4) { break; }
        $code = hexdec($chunk);
        if ($code >= 0xD800 && $code <= 0xDBFF) {           // high surrogate
            $low = hexdec(substr($hex, $i + 4, 4));
            $code = 0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00);
            $i += 4;
        }
        $out .= mb_chr($code, 'UTF-8') ?: '';
    }
    return $out;
}

/** Walk a content stream and collect everything the text operators show. */
function pdf_read_content_stream(string $c, array $cmap): string
{
    $out     = '';
    $len     = strlen($c);
    $inArray = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $c[$i];

        // ---- literal string: (Hello \(world\)) ----
        if ($ch === '(') {
            $depth = 1;
            $str   = '';
            for ($i++; $i < $len && $depth > 0; $i++) {
                $x = $c[$i];
                if ($x === '\\') {
                    $n = $c[$i + 1] ?? '';
                    $i++;
                    switch ($n) {
                        case 'n': $str .= "\n"; break;
                        case 'r': $str .= "\r"; break;
                        case 't': $str .= "\t"; break;
                        case 'b': $str .= "\x08"; break;
                        case 'f': $str .= "\x0C"; break;
                        case '(': $str .= '('; break;
                        case ')': $str .= ')'; break;
                        case '\\': $str .= '\\'; break;
                        case "\n": break;                       // line continuation
                        default:
                            if (ctype_digit($n)) {              // octal escape
                                $oct = $n;
                                while (strlen($oct) < 3 && ctype_digit($c[$i + 1] ?? '')) {
                                    $oct .= $c[++$i];
                                }
                                $str .= chr(octdec($oct));
                            } else {
                                $str .= $n;
                            }
                    }
                    continue;
                }
                if ($x === '(') { $depth++; $str .= $x; continue; }
                if ($x === ')') { $depth--; if ($depth > 0) { $str .= $x; } continue; }
                $str .= $x;
            }
            $i--;
            $out .= pdf_decode_bytes($str, $cmap);
            continue;
        }

        // ---- hex string: <0048 0065> ----
        if ($ch === '<' && ($c[$i + 1] ?? '') !== '<') {
            $end = strpos($c, '>', $i);
            if ($end === false) { break; }
            $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($c, $i + 1, $end - $i - 1));
            $i = $end;
            $out .= pdf_decode_hex((string)$hex, $cmap);
            continue;
        }

        if ($ch === '[') { $inArray = true;  continue; }
        if ($ch === ']') { $inArray = false; continue; }

        // A large negative kern inside a TJ array is a word space.
        if ($inArray && ($ch === '-' || ctype_digit($ch))) {
            $num = $ch;
            while ($i + 1 < $len && (ctype_digit($c[$i + 1]) || $c[$i + 1] === '.')) {
                $num .= $c[++$i];
            }
            if ((float)$num <= -120) { $out .= ' '; }
            continue;
        }

        // ---- operators that move to a new line ----
        if ($ch === 'T') {
            $op = substr($c, $i, 2);
            if ($op === 'Td' || $op === 'TD' || $op === 'T*') { $out .= "\n"; $i++; }
            elseif ($op === 'Tj' || $op === 'TJ') { $i++; }
            continue;
        }
        if ($ch === "'" || $ch === '"') { $out .= "\n"; continue; }
        if ($ch === 'E' && substr($c, $i, 2) === 'ET') { $out .= "\n"; $i++; }
    }
    return $out;
}

/** Decode a literal string, using the CMap when the PDF has one. */
function pdf_decode_bytes(string $str, array $cmap): string
{
    if (!$cmap) {
        // Single byte encoding - treat as Latin-1 so accented characters survive.
        return mb_convert_encoding($str, 'UTF-8', 'Windows-1252');
    }
    return pdf_decode_hex(bin2hex($str), $cmap);
}

/** Decode a hex string through the CMap (2 byte codes) or as raw bytes. */
function pdf_decode_hex(string $hex, array $cmap): string
{
    if (!$cmap) {
        $out = '';
        for ($i = 0; $i + 1 < strlen($hex); $i += 2) {
            $out .= chr((int)hexdec(substr($hex, $i, 2)));
        }
        return mb_convert_encoding($out, 'UTF-8', 'Windows-1252');
    }

    $out = '';
    for ($i = 0; $i + 3 < strlen($hex) + 1; $i += 4) {
        $code = strtoupper(substr($hex, $i, 4));
        if (strlen($code) < 4) { break; }
        if (isset($cmap[$code])) {
            $out .= $cmap[$code];
        } else {
            // Not in the map - fall back to the raw code point.
            $n = hexdec($code);
            $out .= ($n >= 32 && $n <= 0x2FFFF) ? (mb_chr($n, 'UTF-8') ?: '') : '';
        }
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/*  DOCX                                                               */
/* ------------------------------------------------------------------ */

function docx_extract_text(string $bin): string
{
    $xml = zip_read_entry($bin, 'word/document.xml');
    if ($xml === null) {
        return '';
    }

    // Paragraphs and breaks become newlines; tabs become spaces. The captured
    // delimiter is put back so a bare <w:p> keeps its closing bracket.
    $xml = preg_replace('#<w:p([ >])#', "\n<w:p$1", $xml);
    $xml = str_replace(['<w:br/>', '<w:br />', '<w:tab/>', '<w:tab />'],
                       ["\n", "\n", ' ', ' '], (string)$xml);
    $text = strip_tags((string)$xml);
    return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Minimal ZIP reader - returns one entry's contents, or null.
 * Written by hand because ext-zip is disabled in a default XAMPP build.
 */
function zip_read_entry(string $bin, string $wanted): ?string
{
    // Locate the End Of Central Directory record (scan back from the end).
    $eocd = false;
    for ($i = strlen($bin) - 22; $i >= 0 && $i > strlen($bin) - 65558; $i--) {
        if (substr($bin, $i, 4) === "PK\x05\x06") { $eocd = $i; break; }
    }
    if ($eocd === false) {
        return null;
    }

    // EOCD layout from +10: total entries (2), central directory size (4),
    // central directory offset (4).
    $entries = unpack('vcount/x4/Voffset', substr($bin, $eocd + 10, 10));
    $count   = $entries['count'];
    $pos     = $entries['offset'];

    for ($n = 0; $n < $count; $n++) {
        if (substr($bin, $pos, 4) !== "PK\x01\x02") {
            return null;
        }
        $h = unpack('vmethod', substr($bin, $pos + 10, 2))
           + unpack('Vcsize',  substr($bin, $pos + 20, 4))
           + unpack('vnamelen/vextralen/vcommentlen', substr($bin, $pos + 28, 6))
           + unpack('Vlocal',  substr($bin, $pos + 42, 4));
        $name = substr($bin, $pos + 46, $h['namelen']);

        if ($name === $wanted) {
            // Jump to the local header to find where the data actually starts.
            $lp = $h['local'];
            if (substr($bin, $lp, 4) !== "PK\x03\x04") {
                return null;
            }
            $l = unpack('vnamelen/vextralen', substr($bin, $lp + 26, 4));
            $dataStart = $lp + 30 + $l['namelen'] + $l['extralen'];
            $data = substr($bin, $dataStart, $h['csize']);

            if ($h['method'] === 8) {
                $out = @gzinflate($data);
                return $out === false ? null : $out;
            }
            return $h['method'] === 0 ? $data : null;
        }
        $pos += 46 + $h['namelen'] + $h['extralen'] + $h['commentlen'];
    }
    return null;
}
