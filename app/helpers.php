<?php
/** Helpers, i18n and small HTML fragments. */

function t(string $key): string
{
    return $GLOBALS['I18N'][$key] ?? ($GLOBALS['I18N_EN'][$key] ?? $key);
}

function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

function url(array $params): string
{
    return '?' . http_build_query($params);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $stored = $_SESSION['csrf'] ?? '';
    $sent   = $_POST['csrf'] ?? '';
    if ($stored === '' || !is_string($sent) || !hash_equals($stored, $sent)) {
        http_response_code(400);
        exit('Invalid CSRF token. Reload the page and try again.');
    }
}

function flash(?string $msg = null, string $type = 'success'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect(array $params): void
{
    header('Location: ' . url($params));
    exit;
}

function human_bytes($bytes): string
{
    $bytes = (float) $bytes;
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
    return round($bytes, $i ? 1 : 0) . ' ' . $units[$i];
}

function pretty_json(string $json): string
{
    $decoded = json_decode($json);
    return json_last_error() === JSON_ERROR_NONE
        ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : $json;
}

function web_server_string(): string
{
    $s = $_SERVER['SERVER_SOFTWARE'] ?? '';
    if ($s === '') $s = (PHP_SAPI === 'cli-server') ? 'PHP built-in server' : ('PHP ' . PHP_SAPI);
    return $s;
}

function mongo_client_version(): string
{
    $ext = phpversion('mongodb') ?: 'n/a';
    $libmongoc = '';
    if (function_exists('phpinfo')) {
        ob_start(); @phpinfo(INFO_MODULES); $raw = strip_tags(ob_get_clean());
        if (preg_match('/libmongoc bundled version\s*=>?\s*([0-9.]+)/i', $raw, $m)) $libmongoc = $m[1];
    }
    return $libmongoc ? "mongodb $ext — libmongoc $libmongoc" : "mongodb $ext";
}

function php_extension_links(): string
{
    $want = ['mongodb', 'curl', 'mbstring', 'openssl', 'zip', 'zlib', 'json', 'sodium'];
    $out  = [];
    foreach ($want as $ext) {
        if (!extension_loaded($ext)) continue;
        $url = 'https://www.php.net/manual/en/book.' . $ext . '.php';
        $out[] = '<span class="ext"><b>' . e($ext) . '</b> '
            . '<a href="' . e($url) . '" target="_blank" rel="noopener">' . e(t('web.documentation')) . '</a></span>';
    }
    return $out ? implode('', $out) : '—';
}

function visible_db_names(array $names, array $hidden, string $keep = ''): array
{
    return array_values(array_filter($names, function($n) use ($keep, $hidden) { return $n === $keep || !in_array($n, $hidden, true); }));
}

function db_options(array $dbs, string $current = ''): string
{
    $o = '';
    foreach ($dbs as $d) {
        $o .= '<option value="' . e($d) . '"' . ($d === $current ? ' selected' : '') . '>' . e($d) . '</option>';
    }
    return $o;
}

function create_collection_form(string $db): string
{
    return '<form method="post" action="?">'
        . '<input type="hidden" name="do" value="create_collection">'
        . '<input type="hidden" name="db" value="' . e($db) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<div class="row">'
        . '<label>' . e(t('cc.name')) . '<input name="name" placeholder="' . e(t('db.collection_name')) . '" required></label>'
        . '<label>' . e(t('cc.type')) . '<select name="type" class="cc-type">'
        . '<option value="standard">' . e(t('cc.type_standard')) . '</option>'
        . '<option value="capped">' . e(t('cc.type_capped')) . '</option></select></label>'
        . '</div>'
        . '<div class="row cc-capped" hidden>'
        . '<label>' . e(t('cc.capped_size')) . '<input name="size" type="number" min="1" placeholder="e.g. 1048576"></label>'
        . '<label>' . e(t('cc.capped_max')) . '<input name="max" type="number" min="0" placeholder="0 = unlimited"></label>'
        . '</div>'
        . '<p class="hint cc-capped-hint" hidden>' . e(t('cc.capped_hint')) . '</p>'
        . '<button type="submit">' . e(t('cc.create')) . '</button></form>';
}

function mongo_regex_quote(string $s): string
{
    return preg_replace('/[.\\\\+*?\[^\]$(){}=!<>|:#\/-]/', '\\\\$0', $s);
}

/** Type a single "find by fields" value the way the Insert form does. */
function ff_coerce(string $s)
{
    $t = trim($s);
    if ($t === '')      return '';
    if ($t === 'true')  return true;
    if ($t === 'false') return false;
    if ($t === 'null')  return null;
    if (is_numeric($t)) {
        if ((string) (int) $t === $t) return (int) $t;   // exact integer
        return (float) $t;
    }
    return $s;
}

function search_build_filter(array $keys, string $input, string $mode): array
{
    $keys = array_values(array_filter($keys, function($k) { return $k !== ''; }));
    if (!$keys) $keys = ['_id'];
    $input = trim($input);
    if ($input === '') return [];

    $overFields = function (array $clause) use ($keys): array {
        $or = [];
        foreach ($keys as $k) $or[] = [$k => $clause];
        return ['$or' => $or];
    };

    if ($mode === 'regex')  return $overFields(['$regex' => $input, '$options' => 'i']);
    if ($mode === 'substr') return $overFields(['$regex' => mongo_regex_quote($input), '$options' => 'i']);
    if ($mode === 'whole') {
        $or = [];
        foreach ($keys as $k) $or[] = [$k => $input];      // exact value match
        return ['$or' => $or];
    }
    // any / all -> split into words
    $words = preg_split('/\s+/', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $clauses = [];
    foreach ($words as $w) $clauses[] = $overFields(['$regex' => mongo_regex_quote($w), '$options' => 'i']);
    if (!$clauses) return [];
    return $mode === 'all' ? ['$and' => $clauses] : ['$or' => $clauses];
}

function mql_to_command(string $text)
{
    $t = trim($text);
    if ($t === '') throw new RuntimeException('Enter a query.');
    if ($t[0] === '{') return Mongo::jsonToPhp($t);          // raw command document

    if (preg_match('/^db\.([A-Za-z0-9_.$-]+)\.(find|findOne|aggregate|count|countDocuments|distinct)\s*\((.*)\)\s*;?$/s', $t, $m)) {
        $coll = $m[1]; $method = $m[2]; $args = trim($m[3]);
        switch ($method) {
            case 'find':
            case 'findOne':
                $filter = $args === '' ? new stdClass() : Mongo::jsonToPhp($args);
                $cmd = ['find' => $coll, 'filter' => $filter, 'limit' => $method === 'findOne' ? 1 : 50];
                return $cmd;
            case 'aggregate':
                $pipeline = $args === '' ? [] : Mongo::jsonToPhp($args);
                return ['aggregate' => $coll, 'pipeline' => $pipeline, 'cursor' => new stdClass()];
            case 'count':
            case 'countDocuments':
                $q = $args === '' ? new stdClass() : Mongo::jsonToPhp($args);
                return ['count' => $coll, 'query' => $q];
            case 'distinct':
                if (!preg_match('/^\s*["\']([^"\']+)["\']\s*(?:,\s*(\{.*\}))?\s*$/s', $args, $dm)) {
                    throw new RuntimeException('distinct(field[, query]) expects a quoted field name.');
                }
                $cmd = ['distinct' => $coll, 'key' => $dm[1]];
                if (!empty($dm[2])) $cmd['query'] = Mongo::jsonToPhp($dm[2]);
                return $cmd;
        }
    }
    throw new RuntimeException('Could not parse the query. Use a command document like {"find":"coll","filter":{}} or shell style db.coll.find({}).');
}

function drop_button(string $do, array $fields, string $confirm, string $label = 'Drop'): string
{
    $h = '<form method="post" action="?" class="inline confirm" data-confirm="' . e($confirm) . '">'
       . '<input type="hidden" name="do" value="' . e($do) . '">'
       . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
    foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    $h .= '<button class="mini danger" type="submit">' . e($label) . '</button></form>';
    return $h;
}

function str_clip(string $s, int $max = 80): string
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 3) . '…' : $s;
    }
    return strlen($s) > $max ? substr($s, 0, $max - 3) . '…' : $s;
}

function cell_preview($value): string
{
    if ($value === null) return 'null';
    if (is_bool($value)) return $value ? 'true' : 'false';
    if ($value instanceof \MongoDB\BSON\ObjectId) return (string) $value;
    if ($value instanceof \MongoDB\BSON\UTCDateTime) return $value->toDateTime()->format('Y-m-d H:i:s');
    if (is_scalar($value)) {
        return str_clip((string) $value);
    }
    // arrays / nested objects
    return str_clip((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

