<?php
/** Page chrome: layout, sidebar, tabs, breadcrumb, login/fatal/print pages. */

function print_page_body(array $config, Mongo $mongo, string $db, array $colls): void
{
    $colls = array_values(array_filter($colls, 'strlen'));
    $cap = 200;
    ?><!doctype html><html lang="<?= e($GLOBALS['LANG'] ?? 'en') ?>"><head>
    <meta charset="utf-8"><title><?= e($config['app_name']) ?> — <?= e($db) ?></title>
    <style>
      body{font:13px/1.45 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#111;margin:24px}
      h1{font-size:18px;margin:0 0 2px} h2{font-size:15px;margin:22px 0 6px;border-bottom:2px solid #333;padding-bottom:3px}
      .meta{color:#666;font-size:11px;margin-bottom:14px}
      table{border-collapse:collapse;width:100%;margin-bottom:8px}
      th,td{border:1px solid #999;padding:4px 7px;text-align:left;vertical-align:top;font-size:11.5px}
      th{background:#eee} td{max-width:320px;overflow-wrap:anywhere}
      .trunc{color:#888;font-size:11px;margin:0 0 10px}
      .print-bar{margin-bottom:16px}
      .print-bar button{padding:7px 16px;font-size:13px;cursor:pointer}
      @media print{.print-bar{display:none}}
    </style></head><body>
    <div class="print-bar"><button onclick="window.print()"><?= e(t('print.btn')) ?></button></div>
    <h1><?= e($config['app_name']) ?> — <code><?= e($db) ?></code></h1>
    <div class="meta"><?= e(t('print.generated')) ?>: <?= e(date('Y-m-d H:i')) ?></div>
    <?php
    foreach ($colls as $cName) {
        try {
            $total = $mongo->count($db, $cName);
            $keys  = $mongo->topLevelKeys($db, $cName) ?: ['_id'];
            $docs  = $mongo->find($db, $cName, [], ['limit' => $cap]);
        } catch (Throwable $e) {
            echo '<h2>' . e($db) . '.' . e($cName) . '</h2><p class="trunc">' . e($e->getMessage()) . '</p>';
            continue;
        }
        echo '<h2>' . e($cName) . ' <span class="meta">(' . number_format($total) . ' ' . e(t('db.documents')) . ')</span></h2>';
        if (!$docs) { echo '<p class="trunc">—</p>'; continue; }
        echo '<table><thead><tr>';
        foreach ($keys as $k) echo '<th>' . e($k) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($docs as $d) {
            $arr = (array) $d['raw'];
            echo '<tr>';
            foreach ($keys as $k) echo '<td>' . e(array_key_exists($k, $arr) ? cell_preview($arr[$k]) : '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        if ($total > $cap) echo '<p class="trunc">' . e(sprintf(t('print.truncated'), $cap, $total)) . '</p>';
    }
    ?>
    <script>window.addEventListener('load',function(){setTimeout(function(){window.print();},250);});</script>
    </body></html><?php
}

function login_page_body(array $config): void
{
    $f = flash();
    ?><!doctype html><html lang="<?= e($GLOBALS['LANG'] ?? 'en') ?>" data-theme="<?= e($GLOBALS['THEME'] ?? 'light') ?>"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($config['app_name']) ?> – Login</title>
    <link rel="stylesheet" href="assets/style.css"></head>
    <body class="login-page">
      <form class="login-box" method="post" action="?do=login">
        <div class="login-logo"><?= e($config['app_name']) ?></div>
        <div class="login-sub">Log in with your MongoDB credentials</div>
        <?php if ($f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endif; ?>
        <input type="hidden" name="do" value="login">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <label>Username<input name="username" autofocus autocomplete="username"></label>
        <label>Password<input name="password" type="password" autocomplete="current-password"></label>
        <button type="submit">Log in</button>
        <p class="login-hint">Connecting to <code><?= e(parse_url($config['uri'], PHP_URL_HOST) ?: 'mongodb') ?></code>.
        If your server has no authentication enabled, leave the fields blank and just click Log in.</p>
      </form>
    </body></html><?php
}

function fatal_page_body(array $config, string $msg): void
{
    $authHint = stripos($msg, 'auth') !== false || stripos($msg, 'not authorized') !== false;
    ?><!doctype html><html><head><meta charset="utf-8">
    <title>Error</title><link rel="stylesheet" href="assets/style.css"></head>
    <body><div class="fatal"><h1><?= e($config['app_name']) ?></h1>
    <div class="alert alert-error"><?= e($msg) ?></div>
    <?php if ($authHint): ?>
    <p>MongoDB is requiring authentication. Set <code>$mongoUser</code> and
    <code>$mongoPass</code> (and <code>$mongoAuthDb</code> — the database the user
    was created in, usually <code>admin</code>) near the top of <code>config.php</code>.</p>
    <?php endif; ?>
    <p>Check your connection settings in <code>config.php</code> and that the
    <code>mongodb</code> PHP extension is installed.</p></div></body></html><?php
}

/**
 * Shown (instead of crashing) when the PHP "mongodb" extension is not loaded.
 * Detects the OS and gives tailored install + verification steps. Uses no
 * driver classes, so it is safe to call before any MongoDB connection.
 */
function no_mongodb_ext_page(array $config): void
{
    $isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    $isMac = (stripos(PHP_OS, 'Darwin') !== false);
    $os    = $isWin ? 'Windows' : ($isMac ? 'macOS' : 'Linux/Unix');
    $sapi  = php_sapi_name();
    $arch  = (PHP_INT_SIZE * 8) . '-bit';
    $ts    = defined('PHP_ZTS') ? (PHP_ZTS ? 'thread-safe (TS)' : 'non-thread-safe (NTS)') : 'unknown';
    $ini   = php_ini_loaded_file() ?: '(none loaded)';
    ?><!doctype html><html lang="en"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($config['app_name']) ?> &mdash; MongoDB extension required</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
      .setup{max-width:820px;margin:40px auto;padding:0 16px}
      .setup .card{background:var(--panel,#fff);border:1px solid var(--line,#d0d4d9);border-radius:8px;padding:22px 24px;margin-bottom:18px}
      .setup h1{margin:0 0 6px;font-size:20px}
      .setup h2{font-size:15px;margin:18px 0 8px}
      .setup code{font-family:ui-monospace,Consolas,monospace}
      .setup pre{font-family:ui-monospace,Consolas,monospace;background:#1e2127;color:#e6e6e6;padding:12px 14px;border-radius:6px;overflow:auto;line-height:1.5}
      .setup table{border-collapse:collapse;width:100%;font-size:13px}
      .setup th,.setup td{border:1px solid var(--line,#d0d4d9);padding:6px 10px;text-align:left}
      .setup .pill{display:inline-block;background:#fde2e1;color:#a12121;border-radius:20px;padding:3px 12px;font-size:12px;font-weight:600;margin-bottom:8px}
    </style></head>
    <body><div class="setup">
      <div class="card">
        <span class="pill">&#9888; Extension missing</span>
        <h1><?= e($config['app_name']) ?> needs the PHP <code>mongodb</code> extension</h1>
        <p>The <code>mongodb</code> PHP extension is not loaded, so the app cannot talk to your
        MongoDB server yet. Install or enable it (steps below) and reload this page.</p>
        <table>
          <tr><th scope="row">Operating system</th><td><?= e($os . ' (' . PHP_OS . ')') ?></td></tr>
          <tr><th scope="row">PHP version</th><td><?= e(PHP_VERSION . ' · ' . $arch . ' · ' . $ts) ?></td></tr>
          <tr><th scope="row">SAPI</th><td><?= e($sapi) ?></td></tr>
          <tr><th scope="row">Loaded php.ini</th><td><?= e($ini) ?></td></tr>
        </table>
      </div>

      <?php if ($isWin): ?>
      <div class="card">
        <h2>Windows (WAMP / XAMPP)</h2>
        <ol>
          <li>Match the build to the values above: PHP version, <b><?= e($arch) ?></b>, and
              <b><?= e($ts) ?></b>.</li>
          <li>Download the matching <code>php_mongodb.dll</code> from
              <a href="https://pecl.php.net/package/mongodb">pecl.php.net/package/mongodb</a>
              (DLL list: pick PHP version + TS/NTS + x64/x86 to match).</li>
          <li>Copy it into your PHP <code>ext\</code> folder, e.g.
              <code>C:\wamp64\bin\php\php&lt;version&gt;\ext\</code>.</li>
          <li>Add <code>extension=mongodb</code> to <b>both</b> php.ini files WAMP uses
              (Apache module and CLI). The currently loaded one is shown above.</li>
          <li>Left-click the WAMP tray icon &rarr; <b>Restart All Services</b>, then reload.</li>
        </ol>
      </div>
      <?php else: ?>
      <div class="card">
        <h2>Linux (Apache, or Nginx + PHP-FPM)</h2>
        <p><b>Debian / Ubuntu</b></p>
        <pre>sudo apt update
# Easiest: the distro package
sudo apt install php-mongodb
# Or build the latest with PECL:
sudo apt install php-pear php-dev pkg-config libssl-dev
sudo pecl install mongodb
sudo phpenmod mongodb
sudo systemctl restart apache2     # Nginx: sudo systemctl restart php*-fpm</pre>
        <p><b>AlmaLinux / Rocky / RHEL / CentOS</b></p>
        <pre>sudo dnf install php-pear php-devel openssl-devel make gcc
sudo pecl install mongodb
echo "extension=mongodb.so" | sudo tee /etc/php.d/40-mongodb.ini
sudo systemctl restart httpd       # Nginx: sudo systemctl restart php-fpm</pre>
        <p>With <b>Nginx</b>, PHP runs under <b>PHP-FPM</b> &mdash; enable the extension in the
        FPM php.ini and restart <code>php-fpm</code> (restarting Nginx alone is not enough).</p>
      </div>
      <?php endif; ?>

      <div class="card">
        <h2>Verify</h2>
        <pre>php -m | <?= $isWin ? 'findstr' : 'grep' ?> mongodb
php -r "var_dump(extension_loaded('mongodb'));"</pre>
        <p>You should see <code>mongodb</code> in the list and <code>bool(true)</code>. The full
        per-platform guide ships with this app as <code>INSTALL-ext-mongodb.md</code>; the
        official manual is
        <a href="https://www.php.net/manual/en/mongodb.installation.php">php.net/manual/en/mongodb.installation.php</a>.</p>
      </div>
    </div></body></html><?php
}

function render_navi(Mongo $mongo, array $databases, array $config, string $db, string $coll): void
{
    echo '<div class="navi-head">' . e(t('nav.databases')) . '</div><ul class="tree">';
    foreach ($databases as $d) {
        $name = $d['name'];
        if (in_array($name, $config['hidden_dbs'], true) && $name !== $db) continue;
        $open = $name === $db;
        echo '<li class="db' . ($open ? ' open' : '') . '" data-db="' . e($name) . '">';
        echo '<div class="db-row">'
            . '<button type="button" class="db-toggle" aria-label="expand">' . ($open ? '▾' : '▸') . '</button>'
            . '<a class="db-name" href="' . e(url(['db' => $name])) . '">🗄 ' . e($name) . '</a>'
            . '<button type="button" class="db-add" title="' . e(t('cc.title')) . '">+</button>'
            . '</div>';

        // Inline "create collection" mini-form (phpMyAdmin-style), hidden until + is clicked.
        echo '<form class="navi-create" method="post" action="?" hidden>'
            . '<input type="hidden" name="do" value="create_collection">'
            . '<input type="hidden" name="db" value="' . e($name) . '">'
            . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
            . '<input name="name" placeholder="' . e(t('cc.name')) . '" required>'
            . '<select name="type" class="cc-type"><option value="standard">' . e(t('cc.type_standard')) . '</option>'
            . '<option value="capped">' . e(t('cc.type_capped')) . '</option></select>'
            . '<span class="cc-capped" hidden>'
            . '<input name="size" type="number" min="1" placeholder="' . e(t('cc.capped_size')) . '">'
            . '<input name="max" type="number" min="0" placeholder="' . e(t('cc.capped_max')) . '">'
            . '</span>'
            . '<button type="submit">' . e(t('cc.create')) . '</button></form>';

        echo '<ul class="cols">';
        if ($open) {
            try {
                foreach ($mongo->listCollections($name) as $c) {
                    $active = $c['name'] === $coll ? ' active' : '';
                    echo '<li><a class="col' . $active . '" href="'
                        . e(url(['db' => $name, 'collection' => $c['name'], 'action' => 'browse']))
                        . '">▸ ' . e($c['name']) . '</a></li>';
                }
            } catch (Throwable $e) {
                echo '<li class="muted">' . e($e->getMessage()) . '</li>';
            }
        }
        echo '</ul>';
        echo '</li>';
    }
    echo '</ul>';
    echo '<form class="navi-new" method="post" action="?">'
        . '<input type="hidden" name="do" value="create_db">'
        . '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<input name="name" placeholder="' . e(t('nav.new_db')) . '" required>'
        . '<input name="firstCollection" placeholder="' . e(t('nav.first_collection')) . '" value="data">'
        . '<button type="submit">' . e(t('nav.create')) . '</button></form>';
}

function render_breadcrumb(string $db, string $coll, string $action): void
{
    echo '<div class="breadcrumb"><a href="?">' . e(t('crumb.server')) . '</a>';
    if ($db !== '')   echo ' <span class="sep">›</span> <a href="' . e(url(['db' => $db])) . '">' . e($db) . '</a>';
    $dbActions = ['import' => 'tab.import', 'export' => 'tab.export', 'operations' => 'dbtab.operations',
                  'privileges' => 'dbtab.privileges', 'search' => 'dbtab.search', 'mql' => 'dbtab.mql'];
    if ($coll === '' && isset($dbActions[$action])) {
        echo ' <span class="sep">›</span> <span class="muted">' . e(t($dbActions[$action])) . '</span>';
    }
    if ($coll !== '') echo ' <span class="sep">›</span> <a href="' . e(url(['db' => $db, 'collection' => $coll])) . '">' . e($coll) . '</a>';
    if ($coll !== '' && $action !== 'browse') echo ' <span class="sep">›</span> <span class="muted">' . e(ucfirst($action)) . '</span>';
    echo '</div>';
}

function render_db_tabs(string $db, string $action): void
{
    $tabs = [
        'structure'  => [t('dbtab.structure'), '🗂'],
        'search'     => [t('dbtab.search'), '🔍'],
        'mql'        => [t('dbtab.mql'), '⌨'],
        'operations' => [t('dbtab.operations'), '⚙'],
        'privileges' => [t('dbtab.privileges'), '👤'],
        'import'     => [t('tab.import'), '⬆'],
        'export'     => [t('tab.export'), '⬇'],
    ];
    echo '<div class="tabs">';
    foreach ($tabs as $key => [$label, $icon]) {
        $isStruct = $key === 'structure' && in_array($action, ['database', 'structure'], true);
        $on  = ($action === $key || $isStruct) ? ' on' : '';
        $url = $key === 'structure' ? url(['db' => $db]) : url(['db' => $db, 'action' => $key]);
        echo '<a class="tab' . $on . '" href="' . e($url) . '">' . $icon . ' ' . e($label) . '</a>';
    }
    echo '</div>';
}

function render_tabs(string $db, string $coll, string $action): void
{
    $tabs = [
        'browse'     => [t('tab.browse'), '📄'],
        'structure'  => [t('tab.structure'), '🗂'],
        'find'       => [t('tab.find'), '🔍'],
        'insert'     => [t('tab.insert'), '➕'],
        'operations' => [t('tab.operations'), '⚙'],
        'import'     => [t('tab.import'), '⬆'],
        'export'     => [t('tab.export'), '⬇'],
    ];
    echo '<div class="tabs">';
    foreach ($tabs as $key => [$label, $icon]) {
        $on = ($action === $key || ($action === 'edit' && $key === 'browse')) ? ' on' : '';
        echo '<a class="tab' . $on . '" href="'
            . e(url(['db' => $db, 'collection' => $coll, 'action' => $key]))
            . '">' . $icon . ' ' . e($label) . '</a>';
    }
    echo '</div>';
}

