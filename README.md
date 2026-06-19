# phpMongoAdmin
MongoDB administration in plain PHP — **no framework, no Composer**.
This build targets **PHP 7.3 through 8.x**.

## Requirements

- **PHP 7.3+**
- **ext-mongodb 1.6+** (any compatible 1.x / 2.x build for your PHP)
- `ext-zip` for ZIP import/export
  
## 1. Pick the right driver version for your PHP

ext-mongodb is released independently of PHP. Newer driver releases drop old PHP
versions, so on older PHP you must install an **older** driver. Rough guide:

| PHP version | Php Mongodb Extension | Notes |
|-------------|-----------------------|-------|
| 8.1 – 8.4   | latest 1.x / 2.x      | newest stable |
| 8.0         | 1.9 – 1.20            | |
| 7.4         | 1.6 – 1.20            | |
| 7.3         | 1.6 – 1.17            | |
| 7.2         | 1.5 – 1.15            | |
| 7.1         | 1.3 – 1.11            | |
| 7.0         | 1.2 – 1.9 (use **1.6.0** for this build) | |
| 5.6         | up to **1.7.x** (use **1.7.4** for this build) | last line supporting 5.6 |

## Run

```bash
cd phpmongoadmin
php -S localhost:8000      # http://localhost:8000
```

Or drop the folder in your Apache/Nginx  web root or wamp www folder and open it. Log in with your
MongoDB username and password (leave blank if the mongo server has no authentication).

## Layout

```
index.php            front controller (bootstrap -> auth -> intercepts -> action -> render)
config.php           settings (URI, session, themes, languages, hidden_dbs)
assets/  lang/  src/  (engine: Mongo.php, Bson.php, Porter.php)
app/                 bootstrap, helpers, auth, intercepts, actions, views, layout
```

## Demo ScreenShots
<img width="1900" height="551" alt="phpmongoadmin_pic" src="https://github.com/user-attachments/assets/e4319cc0-dac6-40be-9ced-281d0cccbb73" />

<img width="1612" height="469" alt="phpmongoadmin_pic2" src="https://github.com/user-attachments/assets/265868c0-67e3-4b38-b6b4-c6e15fb11ec0" />
