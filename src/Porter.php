<?php
/**
 * Import / export engine for phpMongoAdmin.
 * Pure functions (uses Mongo + Bson); kept separate so it is unit-testable.
 */

/** Stream the export to the browser. Never returns (sends headers + body). */
function handle_export(Mongo $mongo, array $post)
{
    $db     = (string) ((isset($post['db']) ? $post['db'] : ('')));
    $colls  = array_values(array_filter((array) ((isset($post['collections']) ? $post['collections'] : ([]))), 'strlen'));
    $format = in_array((isset($post['format']) ? $post['format'] : ('json')), ['json', 'ndjson', 'csv', 'bson'], true) ? $post['format'] : 'json';
    $asZip  = !empty($post['aszip']);
    $ids    = array_values(array_filter((array) ((isset($post['ids']) ? $post['ids'] : ([]))), 'strlen'));
    if ($db === '' || !$colls) {
        flash('Pick at least one collection to export.', 'error');
        redirect(['db' => $db, 'action' => 'export']);
    }
    $ext = ['json' => 'json', 'ndjson' => 'jsonl', 'csv' => 'csv', 'bson' => 'bson'][$format];

    // Single collection, single file (unless ZIP requested). Selected ids only apply here.
    if (count($colls) === 1 && !$asZip) {
        $coll   = $colls[0];
        $filter = $ids ? $mongo->idInFilter($ids) : [];
        $suffix = $ids ? '.selection' : '';
        $mime = ['json' => 'application/json', 'ndjson' => 'application/x-ndjson',
                 'csv' => 'text/csv', 'bson' => 'application/octet-stream'][$format];
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $db . '.' . $coll . $suffix . '.' . $ext . '"');
        export_collection_stream($mongo, $db, $coll, $format, fopen('php://output', 'w'), $filter);
        return;
    }

    // Multiple collections -> ZIP.
    if (!class_exists('ZipArchive')) {
        flash('ZIP export needs the PHP zip extension (ext-zip).', 'error');
        redirect(['db' => $db, 'action' => 'export']);
    }
    $tmpZip = tempnam(sys_get_temp_dir(), 'pma_zip_');
    $zip = new ZipArchive();
    $zip->open($tmpZip, ZipArchive::OVERWRITE);
    foreach ($colls as $coll) {
        $tmp = tempnam(sys_get_temp_dir(), 'pma_col_');
        $fh  = fopen($tmp, 'w');
        export_collection_stream($mongo, $db, $coll, $format, $fh);
        fclose($fh);
        $entry = $format === 'bson' ? "dump/$db/$coll.bson" : "$db/$coll.$ext";
        $zip->addFile($tmp, $entry);
        if ($format === 'bson') {                              // mongorestore metadata
            $meta = ['collectionName' => $coll, 'options' => new stdClass(),
                     'indexes' => array_map(function($ix) { return ['name' => $ix['name'], 'key' => json_decode($ix['key'])]; }, $mongo->listIndexes($db, $coll))];
            $zip->addFromString("dump/$db/$coll.metadata.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $tmpFiles[] = $tmp;
    }
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $db . '-export-' . date('Ymd-His') . '.zip"');
    header('Content-Length: ' . filesize($tmpZip));
    readfile($tmpZip);
    @unlink($tmpZip);
    foreach ((isset($tmpFiles) ? $tmpFiles : ([]))as $t) @unlink($t);
}

/** Write one collection to a stream in the requested format. */
function export_collection_stream(Mongo $mongo,  $db,  $coll,  $format, $fh, array $filter = [])
{
    if ($format === 'json') {
        fwrite($fh, '[');
        $first = true;
        $mongo->each($db, $coll, function ($doc) use ($fh, &$first) {
            fwrite($fh, ($first ? "\n  " : ",\n  ") . json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $first = false;
        }, $filter);
        fwrite($fh, ($first ? '' : "\n") . "]\n");
    } elseif ($format === 'ndjson') {
        $mongo->each($db, $coll, function ($doc) use ($fh) {
            fwrite($fh, json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        }, $filter);
    } elseif ($format === 'bson') {
        $mongo->each($db, $coll, function ($doc) use ($fh) {
            fwrite($fh, Bson::encodeDocument($doc));
        }, $filter);
    } elseif ($format === 'csv') {
        $keys = $mongo->topLevelKeys($db, $coll);
        if (!$keys) $keys = ['_id'];
        fputcsv($fh, $keys);
        $mongo->each($db, $coll, function ($doc) use ($fh, $keys) {
            $arr = (array) $doc;
            $row = [];
            foreach ($keys as $k) {
                if (!array_key_exists($k, $arr)) { $row[] = ''; continue; }
                $v = $arr[$k];
                if ($v === null)            $row[] = '';
                elseif (is_bool($v))        $row[] = $v ? 'true' : 'false';
                elseif (is_scalar($v))      $row[] = (string) $v;
                elseif ($v instanceof \MongoDB\BSON\ObjectId)    $row[] = (string) $v;
                elseif ($v instanceof \MongoDB\BSON\UTCDateTime) $row[] = $v->toDateTime()->format('c');
                else                        $row[] = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            fputcsv($fh, $row);
        }, $filter);
    }
}

/** Process an uploaded import; returns a human-readable report. */
function handle_import(Mongo $mongo,  $db, array $post, array $file = null)
{
    if ($db === '') throw new RuntimeException('No target database.');
    if (!$file || ((isset($file['error']) ? $file['error'] : (UPLOAD_ERR_NO_FILE))) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No file uploaded (or it exceeded the PHP upload limit).');
    }
    $name   = (isset($file['name']) ? $file['name'] : ('upload'));
    $bytes  = file_get_contents($file['tmp_name']);
    $format = (isset($post['format']) ? $post['format'] : ('auto'));
    $drop   = !empty($post['drop']);
    $infer  = !empty($post['infer']);
    $target = trim((string) ((isset($post['collection']) ? $post['collection'] : (''))));

    // transparent gunzip for *.gz (but not *.zip)
    $lower = strtolower($name);
    if (substr($lower, -3) === '.gz' && substr($lower, -4) !== '.zip' && function_exists('gzdecode')) {
        $bytes = gzdecode($bytes);
        $name  = substr($name, 0, -3);
        $lower = strtolower($name);
    }

    if ($format === 'auto') {
        if (substr($lower, -4) === '.zip')        $format = 'zip';
        elseif (substr($lower, -5) === '.bson')   $format = 'bson';
        elseif (substr($lower, -4) === '.csv')    $format = 'csv';
        elseif (substr($lower, -6) === '.jsonl' || substr($lower, -7) === '.ndjson') $format = 'ndjson';
        else                                      $format = 'json';
    }

    $collFromName = function ( $fname) {
        $base = preg_replace('/\.(gz)$/i', '', $fname);
        $base = preg_replace('/\.(bson|json|jsonl|ndjson|csv)$/i', '', basename($base));
        return $base !== '' ? $base : 'imported';
    };

    $totals = [];
    $importInto = function ( $coll, iterable $docs) use ($mongo, $db, $drop, &$totals) {
        if ($drop) { try { $mongo->dropCollection($db, $coll); } catch (Exception $e) {} }
        $n = $mongo->insertMany($db, $coll, $docs);
        $totals[$coll] = ((isset($totals[$coll]) ? $totals[$coll] : (0))) + $n;
    };

    if ($format === 'zip') {
        if (!class_exists('ZipArchive')) throw new RuntimeException('ZIP import needs ext-zip.');
        $tmp = tempnam(sys_get_temp_dir(), 'pma_in_');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) { @unlink($tmp); throw new RuntimeException('Could not open ZIP.'); }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            $en    = strtolower($entry);
            if (substr($en, -1) === '/' || strpos($en, '.metadata.json') !== false) continue;
            $content = $zip->getFromIndex($i);
            if (substr($en, -3) === '.gz' && function_exists('gzdecode')) { $content = gzdecode($content); $en = substr($en, 0, -3); }
            $coll = $collFromName($entry);
            if (substr($en, -5) === '.bson')      $importInto($coll, Bson::decodeAll($content));
            elseif (substr($en, -4) === '.csv')   $importInto($coll, parse_csv_docs($content, $infer));
            elseif (substr($en, -6) === '.jsonl' || substr($en, -7) === '.ndjson') $importInto($coll, parse_ndjson_docs($content));
            elseif (substr($en, -5) === '.json')  $importInto($coll, parse_json_docs($content));
        }
        $zip->close(); @unlink($tmp);
    } else {
        $coll = $target !== '' ? $target : $collFromName($name);
        switch ($format) {
            case 'bson':   $importInto($coll, Bson::decodeAll($bytes)); break;
            case 'csv':    $importInto($coll, parse_csv_docs($bytes, $infer)); break;
            case 'ndjson': $importInto($coll, parse_ndjson_docs($bytes)); break;
            default:       $importInto($coll, parse_json_docs($bytes)); break;
        }
    }

    if (!$totals) return 'Nothing was imported (no recognised documents found).';
    $parts = [];
    foreach ($totals as $c => $n) $parts[] = "$c: $n";
    return 'Imported ' . array_sum($totals) . ' document(s) → ' . implode(', ', $parts) . '.';
}

/** Parse a JSON array (or a single object) of Extended-JSON documents. */
function parse_json_docs( $content)
{
    $content = trim($content);
    if ($content === '') return [];
    if ($content[0] === '{') return [Mongo::jsonToPhp($content)];   // single object
    $decoded = json_decode($content, true);
    if (!is_array($decoded)) throw new RuntimeException('Expected a JSON array of documents.');
    $out = [];
    foreach ($decoded as $row) {
        $out[] = Mongo::jsonToPhp(json_encode($row, JSON_UNESCAPED_SLASHES));
    }
    return $out;
}

/** Parse NDJSON / JSON Lines (one Extended-JSON document per line). */
function parse_ndjson_docs( $content)
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $out[] = Mongo::jsonToPhp($line);
    }
    return $out;
}

/** Parse CSV (first row = header) into documents, with optional type inference. */
function parse_csv_docs( $content,  $infer)
{
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $content);
    rewind($fh);
    $header = fgetcsv($fh);
    if (!$header) return [];
    $out = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) === 1 && ($row[0] === null || $row[0] === '')) continue;
        $doc = [];
        foreach ($header as $i => $key) {
            if ($key === '' || $key === null) continue;
            $cell = (isset($row[$i]) ? $row[$i] : (''));
            $doc[$key] = $infer ? csv_infer($cell) : $cell;
        }
        $out[] = $doc;
    }
    fclose($fh);
    return $out;
}

function csv_infer($cell)
{
    if ($cell === '' || $cell === null) return null;
    $l = strtolower(trim($cell));
    if ($l === 'true')  return true;
    if ($l === 'false') return false;
    if ($l === 'null')  return null;
    if (preg_match('/^-?\d+$/', $cell)) {
        $n = (int) $cell;
        if ((string) $n === ltrim($cell, '+')) return $n;
    }
    if (is_numeric($cell)) return (float) $cell;
    return $cell;
}

