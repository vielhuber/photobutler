[![build status](https://github.com/vielhuber/photobutler/actions/workflows/ci.yml/badge.svg)](https://github.com/vielhuber/photobutler/actions)
[![github tag](https://img.shields.io/github/v/tag/vielhuber/photobutler)](https://github.com/vielhuber/photobutler/tags)
[![code style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![license](https://img.shields.io/github/license/vielhuber/photobutler)](https://github.com/vielhuber/photobutler/blob/main/LICENSE.md)
[![last commit](https://img.shields.io/github/last-commit/vielhuber/photobutler)](https://github.com/vielhuber/photobutler/commits)
[![php version support](https://img.shields.io/packagist/php-v/vielhuber/photobutler)](https://packagist.org/packages/vielhuber/photobutler)
[![packagist downloads](https://img.shields.io/packagist/dt/vielhuber/photobutler)](https://packagist.org/packages/vielhuber/photobutler)

# 📸 photobutler 📸

self-hosted photo albums with sqlite, search, favorites and german ai tags. originals stay on your storage; previews are sent to your ai provider.

## installation

requires php 8.5+, composer and the extensions `pdo_sqlite`, `gd`, `exif`, `mbstring`, `curl`.

```bash
mkdir photobutler && cd photobutler
composer require vielhuber/photobutler
./vendor/bin/photobutler-init
```

## configuration

edit `.data/.env` (see [.env.example](.env.example)):

- `PHOTO_PATHS`: a json list of absolute photo directories, e.g. `'["/srv/photos"]'`. scans only list files; viewing and tagging onedrive photos can trigger downloads.
- `AI_PROVIDER`, `AI_MODEL`, `AI_BASE_URL`, `AI_API_KEY`: your ai connection with an image-capable model.
- `AUTH_USERNAME`, `AUTH_PASSWORD`: login credentials. `JWT_SECRET` is generated automatically.

serve `public/` over https and route `/index.php/login` to `public/index.php`. the web user needs read access to photos and write access to `.data/`.

## usage

open your server's url and sign in. scan folders and start ai tagging from the gallery. scans resume after stopping. keep the page open while processing; refresh afterwards. previews and capture dates are read on demand; until then, dates use file modification times.

allow at least 120 seconds per request in your webserver and php configuration. failed ai requests can be retried after one hour.

## updates

```bash
composer update vielhuber/photobutler
./vendor/bin/photobutler-init
```
