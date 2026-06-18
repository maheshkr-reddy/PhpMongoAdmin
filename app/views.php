<?php
/** Inner page views (one function per screen). */

function view_home(Mongo $mongo, array $config, array $databases)
{
    $info = $mongo->serverInfo();
    echo '<div class="panel"><h2>' . e(t('home.mongodb_server')) . '</h2><table class="kv">';
    $rows = [
        t('home.host')           => (isset($info['host']) ? $info['host'] : ((parse_url($config['uri'], PHP_URL_HOST) ?: ''))),
        t('home.server_version') => (isset($info['version']) ? $info['version'] : ('?')),
        t('home.process')        => (isset($info['process']) ? $info['process'] : ('')),
        t('home.uptime')         => isset($info['uptime']) ? gmdate('H:i:s', (int)$info['uptime']) . ' (' . (int)$info['uptime'] . 's)' : '—',
        t('home.connections')    => (isset($info['connections']) ? $info['connections'] : ('—')),
        t('home.max_bson')       => isset($info['maxBson']) ? human_bytes($info['maxBson']) : '—',
        t('home.driver')         => 'PHP ext-mongodb ' . (phpversion('mongodb') ?: 'n/a'),
    ];
    foreach ($rows as $k => $v) echo '<tr><th>' . e($k) . '</th><td>' . e((string)$v) . '</td></tr>';
    echo '</table></div>';

    // ---- Web server (phpMyAdmin-style) ----
    echo '<div class="panel"><h2>' . e(t('web.server')) . '</h2><table class="kv">';
    echo '<tr><th>' . e(t('web.server')) . '</th><td>' . e(web_server_string()) . '</td></tr>';
    echo '<tr><th>' . e(t('web.db_client')) . '</th><td>' . e(mongo_client_version()) . '</td></tr>';
    echo '<tr><th>' . e(t('web.php_extensions')) . '</th><td class="ext-list">' . php_extension_links() . '</td></tr>';
    echo '<tr><th>' . e(t('web.php_version')) . '</th><td>' . e(PHP_VERSION) . '</td></tr>';
    echo '</table></div>';

    // ---- Appearance settings ----
    echo '<div class="panel"><h2>' . e(t('appear.title')) . '</h2>'
        . '<form method="post" action="?" class="appearance-form inline-form">'
        . '<input type="hidden" name="do" value="appearance">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
    echo '<label>' . e(t('appear.language')) . '<select name="lang">';
    foreach ($GLOBALS['LANGS'] as $code => $name) {
        $sel = $code === $GLOBALS['LANG'] ? ' selected' : '';
        echo '<option value="' . e($code) . '"' . $sel . '>' . e($name) . '</option>';
    }
    echo '</select></label>';
    echo '<label>' . e(t('appear.theme')) . '<select name="theme">';
    foreach ($GLOBALS['THEMES'] as $code => $name) {
        $sel = $code === $GLOBALS['THEME'] ? ' selected' : '';
        echo '<option value="' . e($code) . '"' . $sel . '>' . e($name) . '</option>';
    }
    echo '</select></label>';
    echo '<button type="submit">' . e(t('appear.apply')) . '</button>';
    echo '</form></div>';

    $hidden  = (isset($config['hidden_dbs']) ? $config['hidden_dbs'] : ([]));
    $visible = array_values(array_filter($databases, function($d) use ($hidden) { return !in_array($d['name'], $hidden, true); }));
    echo '<div class="panel"><h2>' . e(t('home.databases')) . ' <span class="count">' . count($visible) . '</span></h2>';
    echo '<table class="grid"><thead><tr><th>' . e(t('home.database')) . '</th><th class="num">' . e(t('home.size_on_disk')) . '</th><th></th></tr></thead><tbody>';
    foreach ($visible as $d) {
        echo '<tr><td><a href="' . e(url(['db' => $d['name']])) . '">🗄 ' . e($d['name']) . '</a></td>'
            . '<td class="num">' . e(human_bytes($d['sizeOnDisk'])) . '</td>'
            . '<td class="actions">' . drop_button('drop_db', ['db' => $d['name']], 'Drop database "' . $d['name'] . '"?') . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

function view_database(Mongo $mongo,  $db)
{
    $collections = $mongo->listCollections($db);

    // datalist of databases (for copy targets in the bulk modal)
    $dbs = visible_db_names(array_map(function($d) { return $d['name']; }, $mongo->listDatabases()), $GLOBALS['HIDDEN_DBS'], $db);
    echo '<datalist id="dblist">';
    foreach ($dbs as $d) echo '<option value="' . e($d) . '">';
    echo '</datalist>';

    echo '<div class="panel"><h2>' . e(t('db.collections_in')) . ' <code>' . e($db) . '</code> <span class="count">' . count($collections) . '</span></h2>';

    if ($collections) {
        // Bulk-action toolbar (phpMyAdmin "With selected").
        $ops = ['copy' => 'csel.copy', 'export' => 'csel.export', 'empty' => 'csel.empty', 'drop' => 'csel.drop',
                'add_prefix' => 'csel.add_prefix', 'replace_prefix' => 'csel.replace_prefix',
                'copy_prefix' => 'csel.copy_prefix', 'print' => 'csel.print'];
        echo '<div class="collsel" data-db="' . e($db) . '" data-csrf="' . e(csrf_token()) . '" data-none="' . e(t('csel.none')) . '"'
            . ' data-confirm="' . e(t('csel.confirm')) . '" data-cancel="' . e(t('csel.cancel')) . '">'
            . '<span class="sel-label">' . e(t('csel.with')) . '</span> '
            . '<select class="bulk-op"><option value="">' . e(t('csel.choose')) . '</option>';
        foreach ($ops as $val => $lbl) echo '<option value="' . $val . '">' . e(t($lbl)) . '</option>';
        echo '</select> <button type="button" class="mini" data-go>' . e(t('csel.go')) . '</button>'
            . '<span class="sel-count"><b>0</b> ' . e(t('csel.count')) . '</span></div>';

        // Hidden parameter templates (cloned into the modal by JS, so labels stay translated).
        $dbOpts = db_options($dbs, $db);
        echo '<div class="bulk-templates" hidden>'
            . '<div class="bulk-tpl" data-for="copy"><label>' . e(t('csel.target_db')) . '<select name="targetDb">' . $dbOpts . '</select></label></div>'
            . '<div class="bulk-tpl" data-for="copy_prefix"><label>' . e(t('csel.target_db')) . '<select name="targetDb">' . $dbOpts . '</select></label><label>' . e(t('csel.prefix')) . '<input name="prefix" placeholder="new_"></label></div>'
            . '<div class="bulk-tpl" data-for="add_prefix"><label>' . e(t('csel.prefix')) . '<input name="prefix" placeholder="new_"></label></div>'
            . '<div class="bulk-tpl" data-for="replace_prefix"><label>' . e(t('csel.from_prefix')) . '<input name="fromPrefix" placeholder="old_"></label><label>' . e(t('csel.to_prefix')) . '<input name="toPrefix" placeholder="new_"></label></div>'
            . '<div class="bulk-tpl" data-for="export"><label>' . e(t('csel.format')) . '<select name="format"><option value="json">JSON</option><option value="ndjson">NDJSON</option><option value="csv">CSV</option><option value="bson">BSON</option></select></label></div>'
            . '</div>';

        echo '<table class="grid data"><thead><tr>'
            . '<th class="cbcol"><input type="checkbox" id="coll-check-all" title="' . e(t('export.select_all')) . '"></th>'
            . '<th>' . e(t('export.collection')) . '</th><th class="num">' . e(t('db.documents')) . '</th>'
            . '<th class="num">' . e(t('db.size')) . '</th><th class="num">' . e(t('db.indexes')) . '</th>'
            . '<th>' . e(t('db.action')) . '</th></tr></thead><tbody>';
        foreach ($collections as $c) {
            $stats = $mongo->collectionStats($db, $c['name']);
            $base  = ['db' => $db, 'collection' => $c['name']];
            echo '<tr>'
                . '<td class="cbcol"><input type="checkbox" class="collcheck" data-name="' . e($c['name']) . '"></td>'
                . '<td><a href="' . e(url($base + ['action' => 'browse'])) . '">▸ ' . e($c['name']) . '</a>'
                . ($c['type'] === 'view' ? ' <span class="badge">view</span>' : '') . '</td>'
                . '<td class="num">' . number_format((int)((isset($stats['count']) ? $stats['count'] : (0)))) . '</td>'
                . '<td class="num">' . e(human_bytes((isset($stats['size']) ? $stats['size'] : (0)))) . '</td>'
                . '<td class="num">' . (int)((isset($stats['nindexes']) ? $stats['nindexes'] : (0))) . '</td>'
                . '<td class="actions">'
                . '<a class="mini" href="' . e(url($base + ['action' => 'browse'])) . '">' . e(t('btn.browse')) . '</a> '
                . '<button type="button" class="mini coll-copy" data-name="' . e($c['name']) . '">' . e(t('csel.copy_btn')) . '</button> '
                . drop_button('drop_collection', $base, 'Drop collection "' . $c['name'] . '"?', t('csel.delete_btn'))
                . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p class="muted">No collections yet — create one below.</p>';
    }
    echo '</div>';

    // Create collection (also available in the sidebar and on the Operations tab).
    echo '<div class="panel"><h3>' . e(t('cc.title')) . '</h3>' . create_collection_form($db) . '</div>';
}

function view_db_operations(Mongo $mongo,  $db)
{
    // (a) Create collection
    echo '<div class="panel ops"><h3>' . e(t('cc.title')) . '</h3>' . create_collection_form($db) . '</div>';

    // (b) Rename database
    echo '<div class="panel ops"><h3>' . e(t('dbop.rename_title')) . '</h3>'
        . '<form method="post" action="?" class="inline-form">'
        . '<input type="hidden" name="do" value="rename_db">'
        . '<input type="hidden" name="db" value="' . e($db) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<label>' . e(t('dbop.rename_to')) . '<input name="newName" value="' . e($db) . '" required></label>'
        . '<button type="submit">' . e(t('dbop.rename_btn')) . '</button></form>'
        . '<p class="hint">' . e(t('dbop.rename_hint')) . '</p></div>';

    // (c) Copy database
    echo '<div class="panel ops"><h3>' . e(t('dbop.copy_title')) . '</h3>'
        . '<form method="post" action="?">'
        . '<input type="hidden" name="do" value="copy_db">'
        . '<input type="hidden" name="db" value="' . e($db) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<label>' . e(t('dbop.copy_to')) . '<input name="newName" value="' . e($db) . '_copy" required></label>'
        . '<label class="chk"><input type="checkbox" name="copyIndexes" value="1" checked> ' . e(t('dbop.copy_indexes')) . '</label>'
        . '<button type="submit">' . e(t('dbop.copy_btn')) . '</button></form></div>';

    // (d) Drop database
    echo '<div class="panel ops danger-zone"><h3>' . e(t('dbop.drop_title')) . '</h3>'
        . drop_button('drop_db', ['db' => $db], 'Drop the ENTIRE database "' . $db . '"?', t('dbop.drop_btn'))
        . '</div>';
}

function view_db_privileges(Mongo $mongo,  $db)
{
    echo '<div class="panel"><h3>' . e(t('priv.title')) . '</h3>';
    $users = [];
    $err = '';
    try { $users = $mongo->listUsers($db); }
    catch (Exception $e) { $err = $e->getMessage(); }

    if (!$users) {
        echo '<p class="muted">' . e(t('priv.none')) . ($err ? ' <span class="muted">(' . e($err) . ')</span>' : '') . '</p>';
        echo '<p class="hint">' . e(t('priv.hint')) . '</p></div>';
        return;
    }
    echo '<table class="grid"><thead><tr><th>' . e(t('priv.user')) . '</th><th>' . e(t('priv.db')) . '</th><th>' . e(t('priv.roles')) . '</th></tr></thead><tbody>';
    foreach ($users as $u) {
        $roles = '';
        foreach ($u['roles'] as $r) $roles .= '<span class="role-pill">' . e($r) . '</span> ';
        echo '<tr><td><b>' . e($u['user']) . '</b></td><td><code>' . e($u['db']) . '</code></td><td>' . ($roles ?: '<span class="muted">—</span>') . '</td></tr>';
    }
    echo '</tbody></table><p class="hint">' . e(t('priv.hint')) . '</p></div>';
}

function view_db_search(Mongo $mongo,  $db)
{
    $colls   = $mongo->listCollections($db);
    $words   = (isset($_GET['words']) ? $_GET['words'] : (''));
    $mode    = (isset($_GET['mode']) ? $_GET['mode'] : ('substr'));
    $picked  = (array) ((isset($_GET['collections']) ? $_GET['collections'] : ([])));
    $ran     = isset($_GET['run']);
    $modes   = ['any' => 'search.mode_any', 'all' => 'search.mode_all', 'substr' => 'search.mode_substr',
               'whole' => 'search.mode_whole', 'regex' => 'search.mode_regex'];

    echo '<div class="panel"><h3>' . e(t('search.title')) . ' <code>' . e($db) . '</code></h3>'
        . '<form method="get" action="?" class="search-form">'
        . '<input type="hidden" name="db" value="' . e($db) . '">'
        . '<input type="hidden" name="action" value="search">'
        . '<input type="hidden" name="run" value="1">'
        . '<label>' . e(t('search.words')) . '<input name="words" value="' . e($words) . '" autofocus></label>'
        . '<label>' . e(t('search.mode')) . '<select name="mode">';
    foreach ($modes as $key => $lbl) {
        $sel = $key === $mode ? ' selected' : '';
        echo '<option value="' . $key . '"' . $sel . '>' . e(t($lbl)) . '</option>';
    }
    echo '</select></label>';

    echo '<label>' . e(t('search.in')) . '</label>';
    echo '<div class="checklist"><label class="chk all"><input type="checkbox" id="search-all" checked> <b>' . e(t('export.select_all')) . '</b></label>';
    foreach ($colls as $c) {
        $checked = (!$picked || in_array($c['name'], $picked, true)) ? ' checked' : '';
        echo '<label class="chk"><input type="checkbox" class="search-coll" name="collections[]" value="' . e($c['name']) . '"' . $checked . '> ' . e($c['name']) . '</label>';
    }
    echo '</div>';
    echo '<p class="hint">' . e(t('search.hint')) . '</p>';
    echo '<button type="submit">' . e(t('search.go')) . '</button></form></div>';

    if (!$ran) return;

    $targets = $picked ?: array_map(function($c) { return $c['name']; }, $colls);
    echo '<div class="panel"><h3>' . e(t('search.results')) . '</h3>';
    echo '<table class="grid"><thead><tr><th>' . e(t('export.collection')) . '</th><th class="num">' . e(t('search.matches')) . '</th><th></th></tr></thead><tbody>';
    $total = 0;
    foreach ($targets as $cName) {
        try {
            $keys   = $mongo->topLevelKeys($db, $cName);
            $filter = search_build_filter($keys, $words, $mode);
            $count  = $mongo->count($db, $cName, $filter);
        } catch (Exception $e) {
            echo '<tr><td>' . e($cName) . '</td><td class="num muted">—</td><td class="muted">' . e($e->getMessage()) . '</td></tr>';
            continue;
        }
        $total += $count;
        $browse = url(['db' => $db, 'collection' => $cName, 'action' => 'find',
                       'filter' => json_encode($filter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'run' => 1]);
        echo '<tr><td><a href="' . e(url(['db' => $db, 'collection' => $cName, 'action' => 'browse'])) . '">▸ ' . e($cName) . '</a></td>'
            . '<td class="num"><b>' . number_format($count) . '</b></td>'
            . '<td>' . ($count > 0 ? '<a class="mini" href="' . e($browse) . '">' . e(t('search.browse')) . '</a>' : '') . '</td></tr>';
    }
    echo '</tbody><tfoot><tr><th>' . e(t('search.total')) . '</th><th class="num">' . number_format($total) . '</th><th></th></tr></tfoot>';
    echo '</table></div>';
}

function view_mql(Mongo $mongo,  $db)
{
    $query = (isset($_POST['query']) ? $_POST['query'] : (''));
    echo '<div class="panel"><h3>' . e(t('mql.title')) . ' <code>' . e($db) . '</code></h3>'
        . '<form method="post" action="' . e(url(['db' => $db, 'action' => 'mql'])) . '" class="mql-form">'
        . '<input type="hidden" name="do" value="mql_run">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<textarea name="query" rows="6" spellcheck="false" class="code" placeholder=\'{"find":"users","filter":{},"limit":20}\'>' . e($query) . '</textarea>'
        . '<p class="hint">' . e(t('mql.hint')) . '</p>'
        . '<button type="submit">▶ ' . e(t('mql.run')) . '</button></form></div>';

    if (trim($query) === '') return;

    try {
        $cmd  = mql_to_command($query);
        $rows = $mongo->command($db, $cmd);
    } catch (Exception $e) {
        echo '<div class="alert alert-error">' . e($e->getMessage()) . '</div>';
        return;
    }

    echo '<div class="panel"><div class="result-bar"><b>' . count($rows) . '</b> ' . e(t('mql.docs')) . '</div>';
    if (!$rows) { echo '<p class="muted">' . e(t('mql.empty')) . '</p></div>'; return; }
    foreach ($rows as $r) {
        echo '<pre class="doc">' . e(pretty_json($r['json'])) . '</pre>';
    }
    echo '</div>';
}

function view_browse(Mongo $mongo,  $db,  $coll, array $config)
{
    $perPage = max(1, (int) ((isset($config['rows_per_page']) ? $config['rows_per_page'] : (25))));
    $page    = max(1, (int) ((isset($_GET['page']) ? $_GET['page'] : (1))));
    $total   = $mongo->count($db, $coll);
    $pages   = max(1, (int) ceil($total / $perPage));
    $page    = min($page, $pages);

    $docs = $mongo->find($db, $coll, [], [
        'limit' => $perPage,
        'skip'  => ($page - 1) * $perPage,
    ]);

    echo '<div class="panel">';
    echo '<div class="result-bar"><span>Showing <b>' . count($docs) . '</b> of <b>' . number_format($total)
        . '</b> documents · page ' . $page . ' / ' . $pages . '</span></div>';

    if (!$docs) {
        echo '<p class="muted">This collection is empty. '
            . '<a href="' . e(url(['db' => $db, 'collection' => $coll, 'action' => 'insert'])) . '">Insert a document →</a></p></div>';
        return;
    }

    // With-selected toolbar (checkbox actions, phpMyAdmin-style). JS-driven so it
    // doesn't wrap the table (avoids nesting the per-row delete forms).
    echo '<div class="withsel" data-db="' . e($db) . '" data-coll="' . e($coll) . '" data-csrf="' . e(csrf_token()) . '" data-none="' . e(t('sel.none')) . '">'
        . '<span class="sel-label">' . e(t('sel.with')) . '</span> '
        . '<button type="button" class="mini" data-op="edit">✎ ' . e(t('sel.edit')) . '</button> '
        . '<button type="button" class="mini" data-op="copy">⧉ ' . e(t('sel.copy')) . '</button> '
        . '<button type="button" class="mini danger" data-op="delete">✕ ' . e(t('sel.delete')) . '</button> '
        . '<select class="sel-format" title="Export format"><option value="json">JSON</option><option value="ndjson">NDJSON</option><option value="csv">CSV</option><option value="bson">BSON</option></select> '
        . '<button type="button" class="mini" data-op="export">⬇ ' . e(t('sel.export')) . '</button> '
        . '<span class="sel-count"><b>0</b> ' . e(t('sel.count')) . '</span>'
        . '<span class="copy-target" hidden> · ' . e(t('sel.copy_to')) . ' '
        . '<input class="ct-db" value="' . e($db) . '" placeholder="db"> . '
        . '<input class="ct-coll" value="' . e($coll) . '" placeholder="collection"> '
        . '<button type="button" class="mini" data-op="copy-go">' . e(t('sel.go')) . '</button></span>'
        . '</div>';

    // Union of top-level keys across the page → phpMyAdmin-like columns.
    $columns = [];
    foreach ($docs as $d) {
        foreach ((array) $d['raw'] as $k => $_) {
            if (!in_array($k, $columns, true)) $columns[] = $k;
        }
    }

    echo '<div class="table-scroll" tabindex="0" role="region" aria-label="Documents table (scroll horizontally to see more columns)">';
    echo '<table class="grid data"><thead><tr><th class="cbcol"><input type="checkbox" id="check-all" aria-label="Select all rows" title="Select all"></th><th scope="col" class="rownum">#</th><th scope="col">Action</th>';
    foreach ($columns as $c) echo '<th scope="col">' . e($c) . '</th>';
    echo '</tr></thead><tbody>';

    $n = ($page - 1) * $perPage;
    foreach ($docs as $d) {
        $n++;
        $arr = (array) $d['raw'];
        $base = ['db' => $db, 'collection' => $coll];
        echo '<tr>';
        echo '<td class="cbcol">' . ($d['id'] !== null
            ? '<input type="checkbox" class="rowcheck" data-id="' . e($d['id']) . '">'
            : '') . '</td>';
        echo '<td class="rownum">' . $n . '</td>';
        echo '<td class="actions nowrap">';
        if ($d['id'] !== null) {
            echo '<a class="mini" href="' . e(url($base + ['action' => 'edit', 'id' => $d['id']])) . '">✎ Edit</a> ';
            echo '<form method="post" action="?" class="inline confirm" data-confirm="Delete this document?">'
                . '<input type="hidden" name="do" value="delete">'
                . '<input type="hidden" name="db" value="' . e($db) . '">'
                . '<input type="hidden" name="collection" value="' . e($coll) . '">'
                . '<input type="hidden" name="id" value="' . e($d['id']) . '">'
                . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
                . '<button class="mini danger" type="submit">✕ Delete</button></form>';
        } else {
            echo '<span class="muted">no _id</span>';
        }
        echo '</td>';
        foreach ($columns as $c) {
            if (!array_key_exists($c, $arr)) {
                echo '<td class="null-cell"><span class="null">∅</span></td>';
                continue;
            }
            $val      = $arr[$c];
            $fieldRaw = json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $editable = ($c !== '_id' && $d['id'] !== null);
            if ($editable) {
                echo '<td class="editable" title="Double-click to edit"'
                    . ' data-db="' . e($db) . '" data-coll="' . e($coll) . '"'
                    . ' data-id="' . e($d['id']) . '" data-field="' . e($c) . '"'
                    . ' data-json="' . e($fieldRaw) . '">'
                    . '<span class="cell">' . e(cell_preview($val)) . '</span></td>';
            } else {
                echo '<td><span class="cell">' . e(cell_preview($val)) . '</span></td>';
            }
        }
        echo '</tr>';
        // Full document row (collapsible).
        echo '<tr class="json-row"><td colspan="2"></td><td colspan="' . (count($columns) + 1) . '">'
            . '<details><summary>JSON</summary><pre class="doc">' . e(pretty_json($d['json'])) . '</pre></details></td></tr>';
    }
    echo '</tbody></table></div>';   // end .table-scroll

    // Footer: "go to page" jump + pager links
    if ($pages > 1) {
        echo '<div class="table-footer">';

        // Go directly to a page (avoids clicking through many sequential pages)
        echo '<form method="get" action="?" class="goto-form">'
            . '<input type="hidden" name="db" value="' . e($db) . '">'
            . '<input type="hidden" name="collection" value="' . e($coll) . '">'
            . '<input type="hidden" name="action" value="browse">'
            . '<label class="goto-label" for="goto-page">' . e(t('pager.goto')) . '</label>'
            . '<input id="goto-page" class="goto-input" type="number" name="page" min="1" max="' . $pages
            . '" value="' . $page . '" inputmode="numeric" aria-label="' . e(t('pager.goto')) . '">'
            . '<span class="goto-total muted">/ ' . $pages . '</span>'
            . '<button type="submit" class="mini">' . e(t('pager.go')) . '</button>'
            . '</form>';

        // Sequential pager
        echo '<nav class="pager" aria-label="Pagination">';
        $mk = function($p, $label, $rel) use ($page, $db, $coll) { return '<a class="pg' . ($p == $page ? ' on' : '') . '"'
            . ($rel !== '' ? ' rel="' . $rel . '"' : '')
            . ($p == $page ? ' aria-current="page"' : '')
            . ' href="' . e(url(['db' => $db, 'collection' => $coll, 'action' => 'browse', 'page' => $p])) . '">' . $label . '</a>'; };
        if ($page > 1) echo $mk(1, '« First', '') . $mk($page - 1, '‹ Prev', 'prev');
        for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++) echo $mk($p, (string) $p, '');
        if ($page < $pages) echo $mk($page + 1, 'Next ›', 'next') . $mk($pages, 'Last »', '');
        echo '</nav>';

        echo '</div>';   // end .table-footer
    }
    echo '</div>';
}

function view_find(Mongo $mongo,  $db,  $coll)
{
    $filter = (isset($_GET['filter']) ? $_GET['filter'] : ('{}'));
    $sort   = (isset($_GET['sort']) ? $_GET['sort'] : (''));
    $proj   = (isset($_GET['projection']) ? $_GET['projection'] : (''));
    $limit  = (int) ((isset($_GET['limit']) ? $_GET['limit'] : (25)));
    $ran    = isset($_GET['run']);
    $mode   = (((isset($_GET['fmode']) ? $_GET['fmode'] : (''))) === 'fields') ? 'fields' : 'json';
    try { $fields = array_values(array_filter($mongo->topLevelKeys($db, $coll), function($k) { return $k !== '_id'; })); }
    catch (Exception $e) { $fields = []; }

    // shared result renderer (used by both JSON and field search)
    $render_results = function (array $docs) use ($db, $coll) {
        echo '<div class="panel"><div class="result-bar"><b>' . count($docs) . '</b> document(s) returned</div>';
        if (!$docs) { echo '<p class="muted">No matches.</p></div>'; return; }
        foreach ($docs as $d) {
            $base = ['db' => $db, 'collection' => $coll];
            echo '<div class="doc-card"><div class="doc-card-actions">';
            if ($d['id'] !== null) {
                echo '<a class="mini" href="' . e(url($base + ['action' => 'edit', 'id' => $d['id']])) . '">&#9998; Edit</a>';
            }
            echo '</div><pre class="doc">' . e(pretty_json($d['json'])) . '</pre></div>';
        }
        echo '</div>';
    };

    echo '<div class="subtabs">'
        . '<button type="button" class="subtab' . ($mode === 'json' ? ' on' : '') . '" data-tab="find">' . e(t('tab.find')) . '</button>'
        . '<button type="button" class="subtab' . ($mode === 'fields' ? ' on' : '') . '" data-tab="fields">' . e(t('find.by_fields')) . '</button>'
        . '<button type="button" class="subtab" data-tab="replace">' . e(t('frep.title')) . '</button>'
        . '</div>';

    // ---- Find documents (JSON filter) ----
    echo '<div class="subpanel" data-panel="find"' . ($mode === 'json' ? '' : ' hidden') . '>';
    echo '<div class="panel"><h3>Find documents</h3>'
        . '<form method="get" action="?" class="find-form">'
        . '<input type="hidden" name="db" value="' . e($db) . '">'
        . '<input type="hidden" name="collection" value="' . e($coll) . '">'
        . '<input type="hidden" name="action" value="find">'
        . '<input type="hidden" name="run" value="1">'
        . '<label>Filter (JSON)<textarea name="filter" rows="4" spellcheck="false">' . e($filter) . '</textarea></label>'
        . '<div class="row">'
        . '<label>Sort <input name="sort" placeholder=\'{"field":-1}\' value="' . e($sort) . '"></label>'
        . '<label>Projection <input name="projection" placeholder=\'{"field":1}\' value="' . e($proj) . '"></label>'
        . '<label>Limit <input name="limit" type="number" value="' . e((string)$limit) . '" min="1" max="1000" style="width:90px"></label>'
        . '</div>'
        . '<button type="submit">Run find()</button></form></div>';

    if ($ran && $mode === 'json') {
        try {
            $opts = ['limit' => max(1, min(1000, $limit))];
            if (trim($sort) !== '') $opts['sort']       = Mongo::jsonToPhp($sort);
            if (trim($proj) !== '') $opts['projection']  = Mongo::jsonToPhp($proj);
            $docs = $mongo->find($db, $coll, $filter ?: '{}', $opts);
            $render_results($docs);
        } catch (Exception $e) {
            echo '<div class="alert alert-error">Query error: ' . e($e->getMessage()) . '</div>';
        }
    }
    echo '</div>';   // end find subpanel

    // ---- Find by fields (tabular) ----
    $ffield = (array) ((isset($_GET['ffield']) ? $_GET['ffield'] : ([])));
    $fop    = (array) ((isset($_GET['fop']) ? $_GET['fop'] : ([])));
    $fval   = (array) ((isset($_GET['fval']) ? $_GET['fval'] : ([])));
    $ops = [
        'eq'     => '= equals',
        'ne'     => '&ne; not equal',
        'gt'     => '&gt; greater than',
        'gte'    => '&ge; at least',
        'lt'     => '&lt; less than',
        'lte'    => '&le; at most',
        'regex'  => e(t('find.contains')),
        'exists' => e(t('find.exists')),
    ];
    $ffRow = function ( $field = '',  $op = 'eq',  $val = '') use ($ops) {
        $h = '<tr class="ff-row">'
           . '<td><input name="ffield[]" list="ff-fields" placeholder="field" value="' . e($field) . '"></td>'
           . '<td><select name="fop[]" aria-label="Operator">';
        foreach ($ops as $k => $lbl) {
            $h .= '<option value="' . e($k) . '"' . ($k === $op ? ' selected' : '') . '>' . $lbl . '</option>';
        }
        $h .= '</select></td>'
           . '<td><input name="fval[]" placeholder="value" spellcheck="false" value="' . e($val) . '"></td></tr>';
        return $h;
    };

    echo '<div class="subpanel" data-panel="fields"' . ($mode === 'fields' ? '' : ' hidden') . '>';
    echo '<div class="panel"><h3>' . e(t('find.by_fields')) . ' &rarr; <code>' . e($coll) . '</code></h3>'
        . '<form method="get" action="?" class="find-fields">'
        . '<input type="hidden" name="db" value="' . e($db) . '">'
        . '<input type="hidden" name="collection" value="' . e($coll) . '">'
        . '<input type="hidden" name="action" value="find">'
        . '<input type="hidden" name="fmode" value="fields">'
        . '<input type="hidden" name="run" value="1">';
    echo '<datalist id="ff-fields">';
    foreach ($fields as $f) echo '<option value="' . e($f) . '"></option>';
    echo '</datalist>';
    echo '<div class="table-scroll"><table class="grid ff-grid"><thead><tr>'
        . '<th scope="col">' . e(t('ins.field')) . '</th>'
        . '<th scope="col">' . e(t('find.operator')) . '</th>'
        . '<th scope="col">' . e(t('ins.value')) . '</th></tr></thead><tbody class="ff-rows">';
    $shown = 0;
    foreach ($ffield as $i => $fv) {
        if (trim((string) $fv) === '') continue;
        echo $ffRow((string) $fv, (string) ((isset($fop[$i]) ? $fop[$i] : ('eq'))), (string) ((isset($fval[$i]) ? $fval[$i] : (''))));
        $shown++;
    }
    for ($i = $shown; $i < 3; $i++) echo $ffRow();
    echo '</tbody></table></div>';
    echo '<p><button type="button" class="btn-secondary ff-add">' . e(t('find.add_row')) . '</button></p>';
    echo '<div class="row"><label>Limit <input name="limit" type="number" value="' . e((string)$limit) . '" min="1" max="1000" style="width:90px"></label></div>';
    echo '<p class="hint">' . e(t('find.fields_hint')) . '</p>';
    echo '<button type="submit">' . e(t('find.search_btn')) . '</button></form></div>';

    if ($ran && $mode === 'fields') {
        try {
            $pairs = [];
            foreach ($ffield as $i => $fv) {
                $field = trim((string) $fv);
                if ($field === '') continue;
                $op  = (string) ((isset($fop[$i]) ? $fop[$i] : ('eq')));
                $raw = (string) ((isset($fval[$i]) ? $fval[$i] : ('')));
                $tv  = ff_coerce($raw);
                switch ($op) {
                    case 'ne':     $pairs[$field] = ['$ne'  => $tv]; break;
                    case 'gt':     $pairs[$field] = ['$gt'  => $tv]; break;
                    case 'gte':    $pairs[$field] = ['$gte' => $tv]; break;
                    case 'lt':     $pairs[$field] = ['$lt'  => $tv]; break;
                    case 'lte':    $pairs[$field] = ['$lte' => $tv]; break;
                    case 'regex':  $pairs[$field] = ['$regex' => mongo_regex_quote($raw), '$options' => 'i']; break;
                    case 'exists': $pairs[$field] = ['$exists' => (strtolower(trim($raw)) !== 'false')]; break;
                    default:       $pairs[$field] = $tv; break;
                }
            }
            $filterJson = $pairs ? json_encode($pairs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '{}';
            $docs = $mongo->find($db, $coll, $filterJson, ['limit' => max(1, min(1000, $limit))]);
            $render_results($docs);
        } catch (Exception $e) {
            echo '<div class="alert alert-error">Query error: ' . e($e->getMessage()) . '</div>';
        }
    }
    echo '</div>';   // end fields subpanel

    // ---- Find and replace ----
    echo '<div class="subpanel" data-panel="replace" hidden><div class="panel ops"><h3>' . e(t('frep.title')) . ' &rarr; <code>' . e($coll) . '</code></h3>';
    if (!$fields) {
        echo '<p class="muted">' . e(t('frep.no_fields')) . '</p>';
    } else {
        echo '<form method="post" action="?" class="confirm" data-confirm="' . e(t('frep.btn')) . '?">'
            . '<input type="hidden" name="do" value="find_replace">'
            . '<input type="hidden" name="db" value="' . e($db) . '">'
            . '<input type="hidden" name="collection" value="' . e($coll) . '">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<div class="row">'
            . '<label>' . e(t('frep.field')) . '<select name="field">';
        foreach ($fields as $f) echo '<option value="' . e($f) . '">' . e($f) . '</option>';
        echo '</select></label>'
            . '<label>' . e(t('frep.find')) . '<input name="find"></label>'
            . '<label>' . e(t('frep.replace')) . '<input name="replace"></label>'
            . '</div>'
            . '<label class="chk"><input type="checkbox" name="whole" value="1"> ' . e(t('frep.whole')) . '</label>'
            . '<p class="hint">' . e(t('frep.hint')) . '</p>'
            . '<button type="submit" class="danger-btn">' . e(t('frep.btn')) . '</button></form>';
    }
    echo '</div></div>';   // end replace subpanel
}

function view_insert(Mongo $mongo,  $db,  $coll)
{
    // Suggest columns from existing documents (excluding _id, which auto-generates).
    try { $keys = $mongo->topLevelKeys($db, $coll); }
    catch (Exception $e) { $keys = []; }
    $fields = array_values(array_filter($keys, function($k) { return $k !== '_id'; }));

    // sub-tab bar
    echo '<div class="subtabs">'
        . '<button type="button" class="subtab on" data-tab="fields">' . e(t('ins.create_title')) . '</button>'
        . '<button type="button" class="subtab" data-tab="json">' . e(t('ins.advanced')) . '</button>'
        . '</div>';

    // ---- panel 1: phpMyAdmin-style "Create document" field form ----
    echo '<div class="subpanel" data-panel="fields"><div class="panel"><h3>' . e(t('ins.create_title')) . ' → <code>' . e($coll) . '</code></h3>'
        . '<form method="post" action="?" class="insert-fields">'
        . '<input type="hidden" name="do" value="insert_fields">'
        . '<input type="hidden" name="db" value="' . e($db) . '">'
        . '<input type="hidden" name="collection" value="' . e($coll) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
    echo '<table class="grid insert-grid"><thead><tr><th>' . e(t('ins.field')) . '</th><th>' . e(t('ins.value')) . '</th></tr></thead><tbody class="if-rows">';
    $rowFn = function ( $name = '',  $known = false) {
        return '<tr class="if-row">'
            . '<td><input name="keys[]" value="' . e($name) . '"' . ($known ? ' readonly' : '') . ' placeholder="field"></td>'
            . '<td><input name="vals[]" placeholder="value" spellcheck="false"></td></tr>';
    };
    foreach ($fields as $f) echo $rowFn($f, true);
    for ($i = 0; $i < ($fields ? 2 : 4); $i++) echo $rowFn('', false);
    echo '</tbody></table>';
    echo '<p><button type="button" class="btn-secondary if-add">' . e(t('ins.add_row')) . '</button></p>';
    echo '<p class="hint">' . e(t('ins.col_hint')) . ' ' . e(t('ins.value_hint')) . '</p>';
    echo '<button type="submit">' . e(t('ins.insert_btn')) . '</button></form></div></div>';

    // ---- panel 2: raw Extended JSON ----
    $template = "{\n  \n}";
    echo '<div class="subpanel" data-panel="json" hidden><div class="panel"><h3>Document (Extended JSON) → <code>' . e($coll) . '</code></h3>'
        . '<form method="post" action="?">'
        . '<input type="hidden" name="do" value="insert">'
        . '<input type="hidden" name="db" value="' . e($db) . '">'
        . '<input type="hidden" name="collection" value="' . e($coll) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<label>Document (Extended JSON)<textarea name="document" rows="14" spellcheck="false" class="code">' . e($template) . '</textarea></label>'
        . '<p class="hint">Use MongoDB Extended JSON. Omit <code>_id</code> to auto-generate, '
        . 'or specify one like <code>{"_id":{"$oid":"..."}}</code>.</p>'
        . '<button type="submit">' . e(t('ins.insert_btn')) . '</button></form></div></div>';
}

function view_edit(Mongo $mongo,  $db,  $coll,  $id)
{
    if ($id === '') { echo '<div class="alert alert-error">Missing document id.</div>'; return; }
    $doc = $mongo->findById($db, $coll, $id);
    if (!$doc) { echo '<div class="alert alert-error">Document not found.</div>'; return; }

    $raw = (array) $doc['raw'];
    // Prefill values so they round-trip through parseScalarOrJson: plain strings
    // stay unquoted; numeric/bool/null/structured values use their JSON form.
    $prefill = function ($v) {
        if (is_string($v)) {
            return (Mongo::parseScalarOrJson($v) === $v)
                ? $v
                : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    };

    echo '<div class="subtabs">'
        . '<button type="button" class="subtab on" data-tab="fields">' . e(t('edit.fields_title')) . '</button>'
        . '<button type="button" class="subtab" data-tab="json">' . e(t('edit.advanced')) . '</button>'
        . '</div>';

    // ---- tabular field editor (reuses the Insert add-row behaviour) ----
    echo '<div class="subpanel" data-panel="fields"><div class="panel"><h3>' . e(t('edit.fields_title')) . ' &rarr; <code>' . e($id) . '</code></h3>'
        . '<form method="post" action="?" class="insert-fields">'
        . '<input type="hidden" name="do" value="update_fields">'
        . '<input type="hidden" name="db" value="' . e($db) . '">'
        . '<input type="hidden" name="collection" value="' . e($coll) . '">'
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
    echo '<div class="table-scroll"><table class="grid insert-grid"><thead><tr><th scope="col">' . e(t('ins.field')) . '</th><th scope="col">' . e(t('ins.value')) . '</th></tr></thead><tbody class="if-rows">';
    foreach ($raw as $k => $v) {
        $isId = ($k === '_id');
        $val  = $isId ? json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $prefill($v);
        echo '<tr class="if-row">'
            . '<td><input name="keys[]" value="' . e((string) $k) . '" readonly></td>'
            . '<td><input name="vals[]" value="' . e((string) $val) . '" spellcheck="false"' . ($isId ? ' readonly' : '') . '></td></tr>';
    }
    for ($i = 0; $i < 2; $i++) {
        echo '<tr class="if-row"><td><input name="keys[]" placeholder="field"></td><td><input name="vals[]" placeholder="value" spellcheck="false"></td></tr>';
    }
    echo '</tbody></table></div>';
    echo '<p><button type="button" class="btn-secondary if-add">' . e(t('ins.add_row')) . '</button></p>';
    echo '<p class="hint">' . e(t('ins.value_hint')) . ' ' . e(t('edit.fields_hint')) . '</p>';
    echo '<button type="submit">' . e(t('edit.update_btn')) . '</button> '
        . '<a class="btn-secondary" href="' . e(url(['db' => $db, 'collection' => $coll, 'action' => 'browse'])) . '">Cancel</a>'
        . '</form></div></div>';

    // ---- advanced: replace the whole document as Extended JSON ----
    echo '<div class="subpanel" data-panel="json" hidden><div class="panel"><h3>Edit document <code>' . e($id) . '</code> (Extended JSON)</h3>'
        . '<form method="post" action="?">'
        . '<input type="hidden" name="do" value="update">'
        . '<input type="hidden" name="db" value="' . e($db) . '">'
        . '<input type="hidden" name="collection" value="' . e($coll) . '">'
        . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<label>Document (Extended JSON)<textarea name="document" rows="20" spellcheck="false" class="code">'
        . e(pretty_json($doc['json'])) . '</textarea></label>'
        . '<p class="hint">This replaces the entire document (keep the same <code>_id</code>).</p>'
        . '<button type="submit">Save changes</button> '
        . '<a class="btn-secondary" href="' . e(url(['db' => $db, 'collection' => $coll, 'action' => 'browse'])) . '">Cancel</a>'
        . '</form></div></div>';
}

function view_structure(Mongo $mongo,  $db,  $coll)
{
    $stats   = $mongo->collectionStats($db, $coll);
    $indexes = $mongo->listIndexes($db, $coll);

    echo '<div class="panel"><h3>Statistics</h3><table class="kv">';
    $rows = [
        'Documents'        => number_format((int)((isset($stats['count']) ? $stats['count'] : (0)))),
        'Data size'        => human_bytes((isset($stats['size']) ? $stats['size'] : (0))),
        'Storage size'     => human_bytes((isset($stats['storageSize']) ? $stats['storageSize'] : (0))),
        'Avg. document'    => human_bytes((isset($stats['avgObjSize']) ? $stats['avgObjSize'] : (0))),
        'Indexes'          => (int)((isset($stats['nindexes']) ? $stats['nindexes'] : (0))),
        'Total index size' => human_bytes((isset($stats['totalIndexSize']) ? $stats['totalIndexSize'] : (0))),
    ];
    foreach ($rows as $k => $v) echo '<tr><th>' . e($k) . '</th><td>' . e((string)$v) . '</td></tr>';
    echo '</table></div>';

    echo '<div class="panel"><h3>Indexes</h3><table class="grid"><thead><tr><th>Name</th><th>Key</th><th>Unique</th></tr></thead><tbody>';
    foreach ($indexes as $ix) {
        echo '<tr><td>' . e($ix['name']) . '</td><td><code>' . e($ix['key']) . '</code></td>'
            . '<td>' . ($ix['unique'] ? '✔' : '—') . '</td></tr>';
    }
    if (!$indexes) echo '<tr><td colspan="3" class="muted">No index info available.</td></tr>';
    echo '</tbody></table></div>';

    echo '<div class="panel danger-zone"><h3>Danger zone</h3>'
        . drop_button('drop_collection', ['db' => $db, 'collection' => $coll],
            'Drop collection "' . $coll . '"?', 'Drop collection') . '</div>';
}

function view_operations(Mongo $mongo,  $db,  $coll)
{
    $dbs    = visible_db_names(array_map(function($d) { return $d['name']; }, $mongo->listDatabases()), $GLOBALS['HIDDEN_DBS'], $db);
    $fields = array_values(array_filter($mongo->topLevelKeys($db, $coll), function($k) { return $k !== '_id'; }));
    $csrf   = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<input type="hidden" name="db" value="' . e($db) . '">'
            . '<input type="hidden" name="collection" value="' . e($coll) . '">';

    $opts = db_options($dbs, $db);

    // (a) Move collection
    echo '<div class="panel ops"><h3>' . e(t('ops.move_title')) . '</h3>'
        . '<form method="post" action="?">' . $csrf
        . '<input type="hidden" name="do" value="move_collection">'
        . '<div class="row">'
        . '<label>' . e(t('ops.target_db')) . '<select name="targetDb" required>' . $opts . '</select></label>'
        . '<label>' . e(t('ops.new_name')) . '<input name="newName" value="' . e($coll) . '"></label>'
        . '</div>'
        . '<p class="hint">' . e(t('ops.same_db_note')) . '</p>'
        . '<button type="submit">' . e(t('ops.move_btn')) . '</button></form></div>';

    // (b) Collection options (rename within this db)
    echo '<div class="panel ops"><h3>' . e(t('ops.options_title')) . '</h3>'
        . '<form method="post" action="?" class="inline-form">' . $csrf
        . '<input type="hidden" name="do" value="rename_collection">'
        . '<label>' . e(t('ops.new_name')) . '<input name="newName" value="' . e($coll) . '" required></label>'
        . '<button type="submit">' . e(t('ops.rename_btn')) . '</button></form></div>';

    // (c) Copy collection
    echo '<div class="panel ops"><h3>' . e(t('ops.copy_title')) . '</h3>'
        . '<form method="post" action="?">' . $csrf
        . '<input type="hidden" name="do" value="copy_collection">'
        . '<div class="row">'
        . '<label>' . e(t('ops.target_db')) . '<select name="targetDb" required>' . $opts . '</select></label>'
        . '<label>' . e(t('ops.new_name')) . '<input name="newName" value="' . e($coll) . '_copy" required></label>'
        . '</div>'
        . '<label class="chk"><input type="checkbox" name="copyIndexes" value="1" checked> ' . e(t('ops.copy_indexes')) . '</label>'
        . '<button type="submit">' . e(t('ops.copy_btn')) . '</button></form></div>';

    // (d) Add new column / field
    echo '<div class="panel ops"><h3>' . e(t('ops.addcol_title')) . '</h3>'
        . '<form method="post" action="?">' . $csrf
        . '<input type="hidden" name="do" value="add_field">'
        . '<div class="row">'
        . '<label>' . e(t('ops.field_name')) . '<input name="field" placeholder="e.g. status" required></label>'
        . '<label>' . e(t('ops.default_value')) . '<input name="value" placeholder=\'e.g. "active", 0, true, null\'></label>'
        . '</div>'
        . '<label class="chk"><input type="checkbox" name="onlyMissing" value="1" checked> ' . e(t('ops.only_missing')) . '</label>'
        . '<p class="hint">' . e(t('ops.value_hint')) . '</p>'
        . '<button type="submit">' . e(t('ops.addcol_btn')) . '</button></form></div>';

    // (e) Remove column / field
    echo '<div class="panel ops"><h3>' . e(t('ops.removecol_title')) . '</h3>'
        . '<form method="post" action="?" class="inline-form confirm" data-confirm="Remove this field from every document?">' . $csrf
        . '<input type="hidden" name="do" value="remove_field">';
    if ($fields) {
        echo '<label>' . e(t('ops.field_name')) . '<select name="field">';
        foreach ($fields as $f) echo '<option value="' . e($f) . '">' . e($f) . '</option>';
        echo '</select></label>';
    } else {
        echo '<label>' . e(t('ops.field_name')) . '<input name="field" required></label>';
    }
    echo '<button type="submit" class="danger-btn">' . e(t('ops.removecol_btn')) . '</button></form></div>';
}

function view_edit_multi(Mongo $mongo,  $db,  $coll, array $ids)
{
    $ids  = array_values(array_filter($ids, 'strlen'));
    $docs = $mongo->findByIds($db, $coll, $ids);
    echo '<div class="panel"><h3>' . e(t('editmulti.title')) . ' <span class="count">' . count($docs) . '</span></h3>';
    if (!$docs) { echo '<p class="muted">' . e(t('editmulti.none')) . '</p></div>'; return; }

    echo '<form method="post" action="?">'
        . '<input type="hidden" name="do" value="update_multi">'
        . '<input type="hidden" name="db" value="' . e($db) . '">'
        . '<input type="hidden" name="collection" value="' . e($coll) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
    foreach ($docs as $d) {
        $idJson = (isset($d['id']) ? $d['id'] : (''));
        echo '<label><code>_id</code> = ' . e($idJson) . '</label>'
            . '<input type="hidden" name="ids[]" value="' . e($idJson) . '">'
            . '<textarea name="docs[]" rows="10" spellcheck="false" class="code">' . e(pretty_json($d['json'])) . '</textarea>';
    }
    echo '<p></p><button type="submit">' . e(t('editmulti.save')) . '</button> '
        . '<a class="btn-secondary" href="' . e(url(['db' => $db, 'collection' => $coll, 'action' => 'browse'])) . '">Cancel</a>'
        . '</form></div>';
}

function view_export(Mongo $mongo,  $db,  $coll = '')
{
    $formatSelect = '<select name="format">'
        . '<option value="json">JSON array (Extended JSON)</option>'
        . '<option value="ndjson">NDJSON / JSON Lines (mongoexport)</option>'
        . '<option value="csv">CSV</option>'
        . '<option value="bson">BSON (mongodump-style)</option>'
        . '</select>';

    if ($coll !== '') {                                       // collection-scoped (tab)
        echo '<div class="panel"><h3>' . e(t('export.collection')) . ' <code>' . e($db) . '.' . e($coll) . '</code></h3>'
            . '<form method="post" action="?">'
            . '<input type="hidden" name="do" value="export">'
            . '<input type="hidden" name="db" value="' . e($db) . '">'
            . '<input type="hidden" name="collections[]" value="' . e($coll) . '">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<label>' . e(t('export.format')) . $formatSelect . '</label>'
            . '<p class="hint">JSON / NDJSON / BSON preserve every BSON type. CSV flattens top-level fields.</p>'
            . '<button type="submit">⬇ ' . e(t('export.button')) . '</button></form></div>';
        return;
    }

    $collections = $mongo->listCollections($db);
    echo '<div class="panel"><h3>' . e(t('export.from')) . ' <code>' . e($db) . '</code></h3>'
        . '<form method="post" action="?">'
        . '<input type="hidden" name="do" value="export">'
        . '<input type="hidden" name="db" value="' . e($db) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';

    echo '<label>' . e(t('export.collections')) . ' <span class="hint" style="font-weight:400">' . e(t('export.pick_hint')) . '</span></label>';
    echo '<div class="checklist"><label class="chk all"><input type="checkbox" id="exp-all"> <b>' . e(t('export.select_all')) . '</b></label>';
    foreach ($collections as $c) {
        echo '<label class="chk"><input type="checkbox" class="exp-coll" name="collections[]" value="' . e($c['name']) . '"> ' . e($c['name']) . '</label>';
    }
    echo '</div>';

    echo '<div class="row" style="margin-top:12px">'
        . '<label>' . e(t('export.format')) . $formatSelect . '</label>'
        . '<label class="chk" style="align-self:flex-end;margin-bottom:14px"><input type="checkbox" name="aszip" value="1"> ' . e(t('export.always_zip')) . '</label>'
        . '</div>';

    echo '<p class="hint">Multiple collections are bundled into a ZIP automatically. '
        . 'BSON exports use a <code>dump/' . e($db) . '/</code> layout compatible with <code>mongorestore</code>.</p>'
        . '<button type="submit">⬇ ' . e(t('export.button')) . '</button></form></div>';
}

function view_import(Mongo $mongo,  $db,  $coll = '')
{
    $hasZip  = class_exists('ZipArchive');
    $hasZlib = function_exists('gzdecode');

    $formats = '<option value="auto">' . e(t('import.auto')) . '</option>'
        . '<option value="json">JSON array</option>'
        . '<option value="ndjson">NDJSON / JSON Lines</option>'
        . '<option value="csv">CSV</option>'
        . '<option value="bson">BSON (mongodump .bson)</option>';

    if ($coll !== '') {                                       // collection-scoped (tab)
        echo '<div class="panel"><h3>' . e(t('import.into_collection')) . ' <code>' . e($db) . '.' . e($coll) . '</code></h3>'
            . '<form method="post" action="?" enctype="multipart/form-data">'
            . '<input type="hidden" name="do" value="import">'
            . '<input type="hidden" name="db" value="' . e($db) . '">'
            . '<input type="hidden" name="collection" value="' . e($coll) . '">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<label>' . e(t('import.file')) . '<input type="file" name="file" required></label>'
            . '<label>' . e(t('import.format')) . '<select name="format">' . $formats . '</select></label>'
            . '<div class="checklist" style="margin-top:6px">'
            . '<label class="chk"><input type="checkbox" name="drop" value="1"> ' . e(t('import.drop')) . '</label>'
            . '<label class="chk"><input type="checkbox" name="infer" value="1" checked> ' . e(t('import.infer')) . '</label>'
            . '</div>'
            . '<p class="hint">All documents are imported into <code>' . e($coll) . '</code>. '
            . 'Supported: .json, .jsonl/.ndjson, .csv, .bson'
            . ($hasZlib ? ', and .gz (auto-decompressed).' : '.')
            . ' For a whole mongodump folder (.zip), use the database-level Import.</p>'
            . '<button type="submit">⬆ ' . e(t('import.button')) . '</button></form></div>';
        return;
    }

    echo '<div class="panel"><h3>' . e(t('import.into')) . ' <code>' . e($db) . '</code></h3>'
        . '<form method="post" action="?" enctype="multipart/form-data">'
        . '<input type="hidden" name="do" value="import">'
        . '<input type="hidden" name="db" value="' . e($db) . '">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<label>' . e(t('import.file')) . '<input type="file" name="file" required></label>'
        . '<div class="row">'
        . '<label>' . e(t('import.format')) . '<select name="format">' . $formats
        . '<option value="zip">ZIP of a dump folder</option>'
        . '</select></label>'
        . '<label>' . e(t('import.target')) . ' <input name="collection" placeholder="' . e(t('import.target_ph')) . '"></label>'
        . '</div>'
        . '<div class="checklist" style="margin-top:6px">'
        . '<label class="chk"><input type="checkbox" name="drop" value="1"> ' . e(t('import.drop')) . '</label>'
        . '<label class="chk"><input type="checkbox" name="infer" value="1" checked> ' . e(t('import.infer')) . '</label>'
        . '</div>'
        . '<p class="hint">Supported: <b>.json</b> (array or NDJSON), <b>.jsonl</b>, <b>.csv</b>, '
        . '<b>.bson</b> (single collection), <b>.zip</b> (whole mongodump folder). '
        . ($hasZlib ? '<b>.gz</b> files are decompressed automatically. ' : '<span class="muted">(gzip disabled: enable ext-zlib) </span>')
        . (!$hasZip ? '<span class="muted">(ZIP disabled: enable ext-zip) </span>' : '')
        . 'For ZIP/mongodump, the collection name comes from each file.</p>'
        . '<button type="submit">⬆ ' . e(t('import.button')) . '</button></form></div>';
}

