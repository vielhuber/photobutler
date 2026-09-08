[![build status](https://github.com/vielhuber/photobutler/actions/workflows/ci.yml/badge.svg)](https://github.com/vielhuber/photobutler/actions)
[![github tag](https://img.shields.io/github/v/tag/vielhuber/photobutler)](https://github.com/vielhuber/photobutler/tags)
[![code style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![license](https://img.shields.io/github/license/vielhuber/photobutler)](https://github.com/vielhuber/photobutler/blob/main/LICENSE.md)
[![last commit](https://img.shields.io/github/last-commit/vielhuber/photobutler)](https://github.com/vielhuber/photobutler/commits)
[![php version support](https://img.shields.io/packagist/php-v/vielhuber/photobutler)](https://packagist.org/packages/vielhuber/photobutler)
[![packagist downloads](https://img.shields.io/packagist/dt/vielhuber/photobutler)](https://packagist.org/packages/vielhuber/photobutler)

# 📸 photobutler 📸

self-hosted photo albums with sqlite and automatic ai tagging. reads local folders and locally synced onedrive photos, groups folders into albums and generates german descriptions and tags. includes a responsive gallery, full-screen viewer, keyboard navigation, search, favorites, editable tags and original downloads. originals stay unchanged on your storage.

all application logic lives in one class: `src/PhotoButler.php`. the package follows `memhelper`, uses `vielhuber/aihelper` for ai and `vielhuber/simpleauth` for login. no framework, frontend build, external fonts or cdns.

## requirements

- php **8.5+** (required by `simpleauth`), composer 2.2+. development and ci use php 8.5.
- php extensions `pdo_sqlite`, `gd` with jpeg/webp support, `exif`, `mbstring`, `curl`, plus the requirements of `aihelper`.
- linux/wsl, readable photo sources and a writable `.data/` outside the webroot. allow at least 512 mb of php memory for large photos.
- supports jpeg, png, webp and gif (first frame), up to 60 megapixels. heic, raw and videos are skipped; export them to jpeg separately if needed. originals are never converted or deleted.

## installation

once published on packagist:

```bash
mkdir photobutler
cd photobutler
composer require vielhuber/photobutler
./vendor/bin/photobutler-init
```

a github repository, release tag and packagist entry still need to be created. until then, install through a local composer path repository:

```bash
mkdir -p /var/www/photobutler-app
cd /var/www/photobutler-app
composer config repositories.photobutler path /var/www/photobutler
composer require 'vielhuber/photobutler:@dev'
./vendor/bin/photobutler-init
```

the initializer creates `public/index.php`, `public/.htaccess`, `.data/.env`, the sqlite database and thumbnail directory. repeated runs preserve configuration and customized files. an unchanged entry point from the earlier multi-class version is updated automatically; customized entry points must call `run()` on a `PhotoButler` instance as shown below.

edit `.data/.env`:

```dotenv
PHOTO_PATHS='["/path/to/images"]'
AI_PROVIDER=
AI_MODEL=
AI_BASE_URL=
AI_API_KEY=
AUTH_USERNAME=
AUTH_PASSWORD=
JWT_SECRET=
```

set `AI_API_KEY`, `AUTH_USERNAME` and `AUTH_PASSWORD`. the initializer generates `JWT_SECRET`. incomplete login settings keep the app locked. credentials are synced with the single simpleauth user during login; changes in `.env` apply immediately and invalidate existing sessions. the api key stays server-side. `AI_MODEL` must support image input at the configured endpoint; the default is `gpt-6-astra`.

multiple photo sources use a json list:

```dotenv
PHOTO_PATHS='["/mnt/foo/bar/FOTOS","/srv/photos"]'
```

the app and simpleauth share the dotenv parser. `#` comments are supported. use single quotes around passwords or api keys containing spaces, `#` or `$`. use absolute, canonical paths without symbolic links. equally named subfolders from different sources appear in the same album.

## usage

```bash
./vendor/bin/photobutler-index --scan-only
./vendor/bin/photobutler-index --tag-only --limit=50
```

open your own webserver's url and sign in with `AUTH_USERNAME` and `AUTH_PASSWORD`. its document root must point to `public/`. photobutler does not start a server.

for a small sample:

```bash
./vendor/bin/photobutler-index --scan-only --scan-limit=24
./vendor/bin/photobutler-index --tag-only --limit=1
```

`--scan-limit` caps new or changed photos. a scan stopped at this limit does not hide missing files. `--limit` caps photos selected for ai tagging; `aihelper` may retry failed requests internally. without `--scan-only` or `--tag-only`, indexing runs first, followed by tagging (up to 50 photos by default).

### library

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use vielhuber\photobutler\PhotoButler;

$photos = new PhotoButler(rootDir: __DIR__);
$photos->index();
$photos->tag(limit: 50);

$results = $photos->photos(query: 'sea', album: 'holiday', favorites: false, page: 1);

foreach ($results as $photo) {
    echo $photo->name . PHP_EOL;
}

$photos->saveTags(id: 42, tags: 'sea, beach, summer');
$photos->favorite(id: 42, favorite: true);
```

search terms must match your filenames, descriptions or tags; generated descriptions and tags are german. album names follow your source folders.

`photos()` returns up to 60 `stdClass` objects per page, ordered by capture date and id. `photo(id)` returns one object or `null`. each object exposes `id`, `name`, `album`, `taken`, `description`, `tags`, `favorite`, `status`, `width` and `height`. `imagePath(id, original: true)` returns a checked original path or `null`; without `original`, it returns the thumbnail path. the library is intended for trusted server code; the web interface handles login and csrf protection.

the web entry point calls the same class:

```php
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

(new \vielhuber\photobutler\PhotoButler(dirname(__DIR__)))->run();
```

## onedrive and storage

select **always keep on this device** for the photo folder in onedrive. under wsl, mount the windows drive. cloud-only placeholders are not reliably readable files. indexing uses the filesystem and needs no microsoft login or graph api.

when hosting on another machine, make the photos available there through synchronization or a mount. a remote server cannot access your pc's wsl path automatically.

- sources are read-only. exif orientation and capture dates are respected; modification time is used when no capture date exists.
- path, size and modification time determine whether a photo needs reindexing. unchanged photos are not sent to ai again. identical content at different paths remains separate entries.
- a complete scan hides missing files. an unavailable source aborts the scan and preserves the existing index.
- moved files get new entries; favorites and manual tags are not transferred automatically. old entries and thumbnails remain available if the original path returns.
- ai receives **a newly generated jpeg preview with a maximum edge of 1280 pixels and no exif metadata**. originals stay on your storage. image content is sent to `ai.rebuhleiv.xyz`; processing and retention depend on the endpoint operator.
- manual tags replace displayed ai tags and survive reindexing and ai runs. ai descriptions and tags may be inaccurate.
- failed requests receive status `error` and are retried after at least one hour. the cli exits with status 1 while available photos have ai errors. api responses and credentials are not written to application logs.

## cron

```cron
*/5 * * * * cd /var/www/photobutler-app && /usr/bin/php -d memory_limit=512M vendor/bin/photobutler-index --limit=50 >> .data/worker.log 2>&1
```

alternatively, pass `--root=/var/www/photobutler-app`. run cron as the web app's user so the database and thumbnails remain writable. file locks prevent concurrent scans or concurrent tagging runs. the first import may take time; the web interface stays available. under wsl, indexing and cron require wsl and the photo sources to remain accessible.

## deployment

**point the document root to `public/`**, never the project directory. this keeps `.data/.env`, sqlite, photos and `vendor/` outside public access. photos are served through php after login. public hosting requires https.

apache configuration (configure https in your existing virtual host):

```apache
DocumentRoot /var/www/photobutler-app/public
<Directory /var/www/photobutler-app/public>
    Require all granted
    AllowOverride None
    Options -Indexes
    DirectoryIndex index.php
</Directory>
```

nginx, inside your existing https server block (adjust the php-fpm socket):

```nginx
root /var/www/photobutler-app/public;
index index.php;

location / {
    try_files $uri $uri/ =404;
}

location ~ ^/index\.php(?:/login)?$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_pass unix:/run/php/php8.5-fpm.sock;
}

location ~ /\. { deny all; }
location ~ \.php$ { return 404; }
```

the web user needs read access to photos and write access to `.data/`; originals need no write permissions. `.data/.env` is created with mode `0600`. simpleauth allows five failed login attempts per username and ip within 15 minutes. login requires javascript and uses `/index.php/login`; php must route this `PATH_INFO` request to `public/index.php`. the validated token stays in the server-side php session; the browser stores only the httponly session cookie. logout clears the session. behind a tls-terminating reverse proxy, configure php to recognize https correctly; forwarded headers are not trusted automatically.

updates after publication:

```bash
composer update vielhuber/photobutler --no-dev --prefer-dist --no-interaction
./vendor/bin/photobutler-init
```

back up the database and composer files first. to roll back the package, restore `composer.json` and `composer.lock`, then run `composer install --no-dev --prefer-dist`. runtime data and settings live outside `vendor/` and are preserved during updates.

## backup

for a consistent sqlite backup while the app is running:

```bash
php -r '$db = new PDO("sqlite:.data/database.sqlite"); $db->exec("VACUUM INTO '\''.data/backup.sqlite'\''");'
```

the target must not exist yet. store the backup and `.data/.env` securely. thumbnails can be rebuilt. back up originals separately; onedrive synchronization does not replace an independent backup. because sqlite uses wal mode, do not copy its live database file without its companion files.

## development

```bash
composer install
npm install
php bin/photobutler-init
php bin/photobutler-index --scan-only --scan-limit=24
```

`src/PhotoButler.php` contains the library and web application, `templates/` the views, `assets/` css, javascript and the favicon, `bin/` the composer executables, and `tests/` the automated checks. node is only needed for prettier.

## tests

```bash
composer test
composer lint
node --check assets/app.js
node --check assets/login.js
npm run format:check
composer validate --strict
```

tests use temporary photo sources and databases. they cover indexing, search, favorites, tags, changed or missing files, path boundaries, exif orientation, ai response validation, initialization, login, csrf and protected http access. live ai requests require the local `.data/.env` and are not part of the regular tests.
