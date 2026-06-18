<?php
/**
 * phpMongoAdmin – configuration
 *
 * A phpMyAdmin-style web interface for MongoDB, written in plain PHP.
 * Only requires the `mongodb` PHP extension (ext-mongodb). No Composer needed.
 *
 *   pecl install mongodb        # or your distro's php-mongodb package
 *   echo "extension=mongodb.so" >> php.ini
 *
 * There are no credentials in this file. MongoDB username/password are entered
 * on the login screen and kept in your session. If your MongoDB server has no
 * authentication enabled, just submit the login form with the fields left blank.
 */

return [
    // MongoDB connection string (host/port/replicaSet/TLS — NOT credentials).
    //   mongodb://localhost:27017
    //   mongodb://host1:27017,host2:27017/?replicaSet=rs0
    //   mongodb+srv://cluster0.xxxx.mongodb.net          (Atlas)
    'uri' => getenv('MONGO_URI') ?: 'mongodb://localhost:27017',

    // Extra driver options for MongoDB\Driver\Manager (TLS, replicaSet, timeouts).
    // Credentials are NOT set here — they come from the login form / session.
    'uri_options'    => [],
    'driver_options' => [],

    // How long a MongoDB login stays valid without activity (seconds).
    'session_lifetime' => 2 * 60 * 60,   // 2 hours

    // Authentication source = the database your MongoDB user was CREATED in
    // (NOT a restriction on which data you can see — roles decide that).
    // The login form does NOT ask for it: login automatically tries "admin"
    // (self-hosted) and then the username itself (shared-hosting / cPanel, where
    // the user lives in its own same-named database). Add more candidates here
    // if your user lives in some other database.
    'default_auth_source'   => getenv('MONGO_AUTHDB') ?: 'admin',
    'auth_source_fallbacks' => [],        // e.g. ['$external', 'myappdb']

    // UI
    'app_name'      => 'phpMongoAdmin',
    'rows_per_page' => 25,             // documents per page in Browse

    // Appearance — users can switch these from the dashboard (stored per browser).
    'default_theme' => 'light',        // light | dark | original
    'default_lang'  => 'en',
    'themes' => [
        'light'    => 'pmahomme (light)',
        'dark'     => 'Dark',
        'original' => 'Original (blue)',
    ],
    'languages' => [
        'en' => 'English',
        'es' => 'Español',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'hi' => 'हिन्दी',
    ],

    // Databases hidden from the navigation tree
    'hidden_dbs' => ['admin', 'local', 'config'],
];
