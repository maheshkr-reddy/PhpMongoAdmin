# phpMongoAdmin (standalone — PHP 5.6+)


phpMyAdmin-style MongoDB administration in plain PHP .
This build targets **PHP 5.6 and up**.

## Requirements

- **PHP 5.6+**
- **ext-mongodb 1.7.4** (or a compatible 1.x build for PHP 5.6)
- `ext-zip` for ZIP import/export

## Run

```bash
cd phpmongoadmin
php -S localhost:8000      # http://localhost:8000  
```

Or drop the folder in your Apache/Nginx web root and open it. Log in with your
MongoDB username and password (leave blank if the server has no auth).

## Layout

```
index.php            front controller (bootstrap -> auth -> intercepts -> action -> render)
config.php           settings (URI, session, themes, languages, hidden_dbs)
assets/  lang/  src/  (engine: Mongo.php, Bson.php, Porter.php)
app/                 bootstrap, helpers, auth, intercepts, actions, views, layout
```

Same features and file structure as the main standalone build; only the PHP
syntax is down-leveled.
