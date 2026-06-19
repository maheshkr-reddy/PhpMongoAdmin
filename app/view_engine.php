<?php
/** BladeOne view engine: renders the layout + partials + per-screen views. */

$__bl_autoload = __DIR__ . '/../vendor/autoload.php';     // composer install (eftec/bladeone)
$__bl_vendored = __DIR__ . '/../lib/eftec/bladeone/BladeOne.php';  // bundled fallback
if (is_file($__bl_autoload))     { require_once $__bl_autoload; }
elseif (is_file($__bl_vendored)) { require_once $__bl_vendored; }

function blade()
{
    static $b = null;
    if ($b === null) {
        $views = __DIR__ . '/../resources/views';
        $cache = __DIR__ . '/../cache';
        if (!is_dir($cache)) { @mkdir($cache, 0775, true); }
        $cls  = '\\eftec\\bladeone\\BladeOne';
        $mode = defined($cls . '::MODE_AUTO') ? constant($cls . '::MODE_AUTO') : 0;
        $b = new $cls($views, $cache, $mode);
    }
    return $b;
}

function render_layout($config, $mongo, $db, $coll, $action)
{
    try {
        $databases = $mongo->listDatabases();
    } catch (Throwable $e) {
        render_fatal($config, 'Cannot reach MongoDB: ' . $e->getMessage());
        return;
    }

    $inner = 'home';
    if ($coll !== '') {
        $map = array('find' => 'find', 'insert' => 'insert', 'edit' => 'edit', 'structure' => 'structure',
                     'operations' => 'operations', 'editmulti' => 'edit_multi', 'export' => 'export', 'import' => 'import');
        $inner = isset($map[$action]) ? $map[$action] : 'browse';
    } elseif ($db !== '') {
        $map = array('import' => 'import', 'export' => 'export', 'operations' => 'db_operations',
                     'privileges' => 'db_privileges', 'search' => 'db_search', 'mql' => 'mql');
        $inner = isset($map[$action]) ? $map[$action] : 'database';
    }

    echo blade()->run('layout', array(
        'config' => $config, 'mongo' => $mongo, 'databases' => $databases,
        'db' => $db, 'coll' => $coll, 'action' => $action, 'inner' => $inner,
        'id'  => isset($_GET['id']) ? $_GET['id'] : '',
        'ids' => isset($_GET['ids']) ? (array) $_GET['ids'] : array(),
        'lang' => $GLOBALS['LANG'], 'theme' => $GLOBALS['THEME'],
        'appName' => $config['app_name'],
        'host' => (parse_url($config['uri'], PHP_URL_HOST) ?: 'mongodb'),
        'titleSuffix' => ($db ? ' - ' . $db : '') . ($coll ? '.' . $coll : ''),
        'username' => isset($_SESSION['mongo_auth']['username']) ? $_SESSION['mongo_auth']['username'] : '',
        'csrf' => csrf_token(),
        'flash' => flash(),
        'logoutLabel' => t('topbar.logout'),
    ));
}

function render_login($config)            { echo blade()->run('login', array('config' => $config)); }
function render_fatal($config, $msg)      { echo blade()->run('fatal', array('config' => $config, 'msg' => $msg)); }
function render_print($config, $mongo, $db, $colls) { echo blade()->run('print', array('config' => $config, 'mongo' => $mongo, 'db' => $db, 'colls' => $colls)); }
