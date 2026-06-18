<?php
/**
 * phpMongoAdmin — standalone front controller (no framework required).
 *
 *   php -S localhost:8000        # then open http://localhost:8000
 * or point an Apache/Nginx vhost DocumentRoot at this folder.
 */
$config = require __DIR__ . '/config.php';

require __DIR__ . '/app/bootstrap.php';    // session, engine, i18n, helpers, views, layout, actions

if (!extension_loaded('mongodb')) {        // show a friendly setup page instead of a fatal
    no_mongodb_ext_page($config);
    exit;
}

require __DIR__ . '/app/auth.php';         // login gate + connect  -> $mongo  (renders login & exits if needed)
require __DIR__ . '/app/intercepts.php';   // nav AJAX / print / inline-edit / export  (exits if it handled the request)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_post($mongo, $config);          // state-changing actions (each redirects)
}

// GET page render
render_layout(
    $config,
    $mongo,
    (isset($_GET['db']) ? $_GET['db'] : ('')),
    (isset($_GET['collection']) ? $_GET['collection'] : ('')),
    (isset($_GET['action']) ? $_GET['action'] : ('')));
