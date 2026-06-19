<?php
/** Session, engine, i18n and module loading. Expects $config in scope. */

$SESSION_TTL = (int) ($config['session_lifetime'] ?? 7200);
ini_set('session.gc_maxlifetime', (string) $SESSION_TTL);
session_set_cookie_params(['lifetime' => $SESSION_TTL, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_start();

require __DIR__ . '/../src/Mongo.php';
require __DIR__ . '/../src/Bson.php';
require __DIR__ . '/../src/Porter.php';

/* ------------------------------------------------ appearance: theme + i18n */

$GLOBALS['THEMES'] = $config['themes']   ?? ['light' => 'Light'];
$GLOBALS['LANGS']  = $config['languages'] ?? ['en' => 'English'];
$GLOBALS['HIDDEN_DBS'] = $config['hidden_dbs'] ?? [];

$theme = $_COOKIE['pma_theme'] ?? ($config['default_theme'] ?? 'light');
if (!isset($GLOBALS['THEMES'][$theme])) $theme = 'light';
$GLOBALS['THEME'] = $theme;

$lang = $_COOKIE['pma_lang'] ?? ($config['default_lang'] ?? 'en');
if (!isset($GLOBALS['LANGS'][$lang])) $lang = 'en';
$GLOBALS['LANG']    = $lang;
$GLOBALS['I18N_EN'] = require __DIR__ . '/../lang/en.php';
$GLOBALS['I18N']    = $lang === 'en'
    ? $GLOBALS['I18N_EN']
    : array_merge($GLOBALS['I18N_EN'], require __DIR__ . "/../lang/$lang.php");


require __DIR__ . '/helpers.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/views.php';
require __DIR__ . '/view_engine.php';
require __DIR__ . '/actions.php';
