# Installing the PHP `mongodb` extension (ext-mongodb)

phpMongoAdmin talks to MongoDB through the official **PHP `mongodb` driver
extension** (often called *ext-mongodb*). It is a compiled C extension — it is
**not** the same thing as the `mongodb/mongodb` Composer library, and this app
does **not** need that library. You only need the extension.

If the extension is missing, phpMongoAdmin shows a setup page instead of crashing
(see *“The app's built-in check”* at the bottom). This document explains how to
install and verify the extension on Windows (WAMP/XAMPP) and on Linux
(Ubuntu/Debian and AlmaLinux/Rocky/RHEL/CentOS), for both Apache and Nginx.

---

## 0. Is it already installed?

Run any of these:

```bash
php -m | grep mongodb          # Linux/macOS  → prints "mongodb" if present
php -m | findstr mongodb       # Windows
php -r "var_dump(extension_loaded('mongodb'));"   # prints bool(true)/bool(false)
php --ri mongodb               # prints driver + libmongoc/libbson versions if present
```

Or create a file `info.php` in your web root containing `<?php phpinfo();`, open it
in the browser, and search the page for **mongodb**. (Delete it afterwards.)

> **Important (Windows & Nginx):** the command line (`php -m`) and the web server
> often use **different `php.ini` files**. The extension can show up on the CLI
> but still be missing in the browser, or vice-versa. Always verify in the same
> SAPI you actually run the app under.

---

## 1. Pick the right driver version for your PHP

ext-mongodb is released independently of PHP. Newer driver releases drop old PHP
versions, so on older PHP you must install an **older** driver. Rough guide:

| PHP version | Use ext-mongodb | Notes |
|-------------|-----------------|-------|
| 8.1 – 8.4   | latest 1.x / 2.x | newest stable |
| 8.0         | 1.9 – 1.20      | |
| 7.4         | 1.6 – 1.20      | |
| 7.3         | 1.6 – 1.17      | |
| 7.2         | 1.5 – 1.15      | |
| 7.1         | 1.3 – 1.11      | |
| 7.0         | 1.2 – 1.9 (use **1.6.0** for this build) | |
| 5.6         | up to **1.7.x** (use **1.7.4** for this build) | last line supporting 5.6 |

On Linux with PECL you can pin a version, e.g. `sudo pecl install mongodb-1.7.4`.
On Windows, download the DLL that matches your PHP from the version's release page.

---

## 2. Windows (WAMP / XAMPP / Laragon)

### 2.1 Find your exact PHP build

The Windows DLL must match **four** things: PHP major.minor version, architecture
(x64 vs x86), and thread-safety (TS vs NTS). Find them with:

```bat
php -i | findstr /C:"PHP Version" /C:"Architecture" /C:"Thread Safety" /C:"Compiler"
```

or open `phpinfo()` in the browser and read **PHP Version**, **Architecture**
(x64), **Thread Safety** (enabled = TS, disabled = NTS) and **Compiler**
(e.g. Visual C++ `vs16`/`vc15`). WAMP's bundled PHP is almost always **x64 + TS**.

### 2.2 Download the matching DLL

1. Go to <https://pecl.php.net/package/mongodb> and open the release you need
   (see the version table above).
2. Click the **DLL** link for that release.
3. Download the ZIP whose name matches your build, e.g.
   `php_mongodb-1.x.y-8.3-ts-vs16-x64.zip` (TS + x64 for PHP 8.3).
   - **TS** if Thread Safety is *enabled*, **NTS** if *disabled*.
   - `x64` for 64-bit, `x86` for 32-bit.

### 2.3 Install it

1. Extract `php_mongodb.dll` from the ZIP.
2. Copy it into your PHP **`ext`** folder, e.g.
   `C:\wamp64\bin\php\php8.3.x\ext\`.
3. Edit **php.ini** and add a line in the extensions area:
   ```ini
   extension=mongodb
   ```
   **WAMP uses two separate php.ini files** — one for Apache and one for the CLI:
   - Apache: `C:\wamp64\bin\apache\apache2.4.x\bin\php.ini`
     (or use the WAMP tray menu → *PHP* → *php.ini*, which edits the Apache one)
   - CLI: `C:\wamp64\bin\php\php8.3.x\php.ini`

   Add the line to **both** if you want the extension on the command line too.
   The currently-loaded file is shown by `php -i | findstr "Loaded Configuration"`.
4. Left-click the **WAMP tray icon → Restart All Services** (or restart Apache).
5. Reload phpMongoAdmin.

### 2.4 Common Windows problems

- **`'php_mongodb.dll' ... is not a valid Win32 application` / fails to load:**
  wrong architecture or TS/NTS. Re-download the matching DLL.
- **Nothing changes after editing php.ini:** you edited the CLI php.ini but the
  app runs under Apache (or vice-versa). Edit the right one, then **restart**.
- **WAMP icon stays orange:** Apache didn't start — check
  `C:\wamp64\logs\apache_error.log`; usually a DLL/php.ini mismatch.

---

## 3. Linux — Ubuntu / Debian

### 3.1 Easiest: the distro package

```bash
sudo apt update
sudo apt install php-mongodb
```

This installs a prebuilt extension and usually enables it automatically. If you
run multiple PHP versions, install the version-specific package, e.g.
`sudo apt install php8.3-mongodb`.

### 3.2 Or build the latest with PECL

```bash
sudo apt update
sudo apt install php-pear php-dev pkg-config libssl-dev   # build tools + headers
sudo pecl install mongodb               # add a version to pin: pecl install mongodb-1.17.0
```

PECL prints a line like `extension=mongodb.so`. Enable it the Debian way:

```bash
# create a mods-available entry (replace 8.3 with your version: php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
echo "extension=mongodb.so" | sudo tee /etc/php/8.3/mods-available/mongodb.ini
sudo phpenmod mongodb
```

### 3.3 Restart the right service

- **Apache (mod_php):** `sudo systemctl restart apache2`
- **Nginx (PHP-FPM):** `sudo systemctl restart php8.3-fpm`  (use your version)

---

## 4. Linux — AlmaLinux / Rocky / RHEL / CentOS

### 4.1 Build with PECL (most reliable across these distros)

```bash
sudo dnf install php-pear php-devel openssl-devel make gcc
sudo pecl install mongodb
```

Then register it. On RHEL-family PHP, drop a small ini into `/etc/php.d/`:

```bash
echo "extension=mongodb.so" | sudo tee /etc/php.d/40-mongodb.ini
```

### 4.2 Or use a repo package (Remi)

If you use the **Remi** repository (common for newer PHP on RHEL-family):

```bash
sudo dnf install php-mongodb        # or php74-php-pecl-mongodb etc. for SCL-style installs
```

### 4.3 Restart the right service

- **Apache:** `sudo systemctl restart httpd`
- **Nginx (PHP-FPM):** `sudo systemctl restart php-fpm`

### 4.4 SELinux note

If MongoDB is on another host and PHP can't connect under Apache/Nginx while the
CLI works, SELinux may be blocking outbound connections:

```bash
sudo setsebool -P httpd_can_network_connect 1
```

---

## 5. Apache vs Nginx — the key difference

- **Apache with mod_php** runs PHP *inside* Apache, so enabling the extension in
  the PHP that Apache loads and restarting Apache is enough.
- **Nginx never runs PHP itself** — it forwards to **PHP-FPM**. You must enable
  the extension in the **FPM** PHP configuration and **restart php-fpm**
  (`systemctl restart php-fpm` / `php8.3-fpm`). Restarting Nginx alone does
  nothing for extensions.

To see exactly which ini files your FPM uses:

```bash
php-fpm -i | grep -i "Loaded Configuration\|Scan this dir"   # or php-fpm8.3 -i
```

---

## 6. Verify it's installed

After installing and restarting, confirm in **the same SAPI the app uses**:

```bash
# CLI
php -m | grep mongodb            # Linux/macOS
php -m | findstr mongodb         # Windows
php --ri mongodb                 # driver + libmongoc / libbson versions
php -r "var_dump(extension_loaded('mongodb'));"
```

For the **web server**, the surest check is a `phpinfo()` page (search for
*mongodb*) or simply reloading phpMongoAdmin — if the extension is present, the
normal login screen appears instead of the setup page.

---

## 7. The app's built-in check

phpMongoAdmin checks for the extension on every request **before** it tries to
connect. If `mongodb` is not loaded it serves a diagnostic page that:

- detects your operating system, PHP version, architecture, thread-safety and the
  currently-loaded `php.ini`;
- shows the Windows or Linux steps relevant to you; and
- gives the verification commands above.

So if you ever see that page, follow its on-screen steps (or this document),
install/enable the extension, restart your web server, and reload.

---

## 8. Useful links

- PHP manual — MongoDB driver installation:
  <https://www.php.net/manual/en/mongodb.installation.php>
- PECL package (all releases + Windows DLLs):
  <https://pecl.php.net/package/mongodb>
- Driver compatibility / requirements:
  <https://www.mongodb.com/docs/drivers/php/>
