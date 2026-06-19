<?php
/** MongoDB login gate (session) + connection. Defines $mongo, or renders login and exits. */

/* ----------------------------------------------- MongoDB login (session) */

if (($_GET['do'] ?? '') === 'logout') {
    $_SESSION = [];
    session_destroy();
    redirect([]);
}

if (($_POST['do'] ?? '') === 'login') {
    csrf_check();
    $user = trim($_POST['username'] ?? '');
    $pass = (string) ($_POST['password'] ?? '');

    if ($user === '') {
        // No-auth server: connect with no credentials.
        try {
            (new Mongo($config['uri'], $config['uri_options'], $config['driver_options']))->ping();
            $_SESSION['mongo_auth'] = ['username' => '', 'password' => '', 'authSource' => '', 'time' => time()];
            redirect([]);
        } catch (Throwable $e) {
            flash('Login failed: ' . $e->getMessage(), 'error');
            redirect(['do' => 'login']);
        }
    }

    // Try the configured auth database, the username itself (shared-hosting /
    // cPanel pattern where the user lives in its own same-named database), then
    // any fallbacks — so the user never has to type an auth database.
    // Only auth failures advance to the next candidate; connection problems stop.
    $candidates = array_values(array_unique(array_filter(array_merge(
        [$config['default_auth_source'] ?? 'admin'],
        [$user],
        (array) ($config['auth_source_fallbacks'] ?? [])
    ), 'strlen')));
    $lastErr = 'authentication failed';
    foreach ($candidates as $authDb) {
        $opts = $config['uri_options'];
        $opts['username'] = $user; $opts['password'] = $pass; $opts['authSource'] = $authDb;
        try {
            (new Mongo($config['uri'], $opts, $config['driver_options']))->ping();
            $_SESSION['mongo_auth'] = ['username' => $user, 'password' => $pass, 'authSource' => $authDb, 'time' => time()];
            redirect([]);
        } catch (\MongoDB\Driver\Exception\AuthenticationException $e) {
            $lastErr = $e->getMessage();          // wrong authSource/credentials → try next
        } catch (\Throwable $e) {
            $lastErr = $e->getMessage();          // connection/other → stop trying
            break;
        }
    }
    flash('Login failed: ' . $lastErr, 'error');
    redirect(['do' => 'login']);
}

// Expire the session after the configured idle window.
if (!empty($_SESSION['mongo_auth']) && (time() - (int)($_SESSION['mongo_auth']['time'] ?? 0)) > $SESSION_TTL) {
    $_SESSION = [];
    flash('Your session expired. Please log in again.', 'error');
}

if (empty($_SESSION['mongo_auth'])) {
    render_login($config);
    exit;
}
$_SESSION['mongo_auth']['time'] = time();   // sliding refresh

/* ----------------------------------------------------------------- connect */

$uriOptions = $config['uri_options'];
if (!empty($_SESSION['mongo_auth']['username'])) {
    $uriOptions['username']   = $_SESSION['mongo_auth']['username'];
    $uriOptions['password']   = $_SESSION['mongo_auth']['password'];
    $uriOptions['authSource'] = $_SESSION['mongo_auth']['authSource'] ?: 'admin';
}

try {
    $mongo = new Mongo($config['uri'], $uriOptions, $config['driver_options']);
} catch (Throwable $e) {
    render_fatal($config, 'Could not initialise MongoDB driver: ' . $e->getMessage());
    exit;
}

