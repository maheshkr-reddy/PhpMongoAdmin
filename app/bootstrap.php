<?php
/** Session, engine, i18n and module loading. Expects $config in scope. */

$SESSION_TTL = (int) ((isset($config['session_lifetime']) ? $config['session_lifetime'] : (7200)));
ini_set('session.gc_maxlifetime', (string) $SESSION_TTL);
session_set_cookie_params($SESSION_TTL, '/', '', false, true);  // positional (PHP < 7.3)
session_start();

require __DIR__ . '/../src/Mongo.php';
require __DIR__ . '/../src/Bson.php';
require __DIR__ . '/../src/Porter.php';

/* ------------------------------------------------ appearance: theme + i18n */

$GLOBALS['THEMES'] = (isset($config['themes']) ? $config['themes'] : (['light' => 'Light']));
$GLOBALS['LANGS']  = (isset($config['languages']) ? $config['languages'] : (['en' => 'English']));
$GLOBALS['HIDDEN_DBS'] = (isset($config['hidden_dbs']) ? $config['hidden_dbs'] : ([]));

$theme = (isset($_COOKIE['pma_theme']) ? $_COOKIE['pma_theme'] : (((isset($config['default_theme']) ? $config['default_theme'] : ('light')))));
if (!isset($GLOBALS['THEMES'][$theme])) $theme = 'light';
$GLOBALS['THEME'] = $theme;

$lang = (isset($_COOKIE['pma_lang']) ? $_COOKIE['pma_lang'] : (((isset($config['default_lang']) ? $config['default_lang'] : ('en')))));
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
