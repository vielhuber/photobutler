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

requires php 8.5+, composer and the extensions `pdo_sqlite`, `gd`, `exif`, `mbstring`, `curl`, `zip`, `fileinfo`. sticker rendering additionally requires node.js 22+ and npm; the php process must be allowed to start `node` via `proc_open`.

```bash
mkdir photobutler && cd photobutler
composer require vielhuber/photobutler
npm install --omit=dev --ignore-scripts --prefix vendor/vielhuber/photobutler
./vendor/bin/photobutler-init
```

## configuration

edit `.data/.env` (see [.env.example](.env.example)):

- `PHOTO_PATHS`: a json list of absolute photo directories, e.g. `'["/srv/photos"]'`. scans only list files; viewing and tagging onedrive photos can trigger downloads.
- `AI_PROVIDER`, `AI_MODEL`, `AI_BASE_URL`, `AI_API_KEY`: your ai connection with an image-capable model. the template uses `cliproxyapi` and the cost-efficient `gpt-5.6-luna`; set your gateway url and key and confirm the model is available there.
- `AUTH_USERNAME`, `AUTH_PASSWORD`: login credentials. `JWT_SECRET` is generated automatically.

serve `public/` over https and route `/index.php/login` to `public/index.php`. the web user needs read access to photos and write access to `.data/`.

## usage

open your server's url and sign in. photos load automatically as you scroll; search and album filters stay active. scan folders from the gallery; scans resume after stopping. ai tagging starts automatically when opening the gallery with pending photos and after a completed scan. keep the page open while processing; closing it stops further requests. progress and controls remain visible at the bottom of the sidebar (on mobile, in a fixed bottom bar). refresh the gallery afterwards. navigation, search, refresh and sign-in/out update the page through javascript without restarting an active tagging run during gallery navigation. opening a photo updates the url (`?image=123`); direct links open the photo after sign-in, and browser back/forward controls the popup. the popup uses subtle animations even when reduced motion is enabled. previews and capture dates are read on demand; until then, dates use file modification times.

animated webp stickers and whatsapp sticker archives disguised as webp files are rendered locally with dotlottie and sharp: the grid, album covers and popup play a cached animated webp, while ai tagging uses a static preview. originals remain unchanged, including downloads. lottie rendering is limited to 320 pixels, 30 fps and 30 seconds, with a 4 mib json limit and a 45-second rendering timeout. no external animation service is used. when running a source checkout, install dependencies with `npm install --ignore-scripts` in the project directory.

photo previews use a maximum edge of 640 pixels and jpeg quality 65; sticker previews use 320 pixels and quality 65.

the desktop grid offers 5, 6 or 7 columns (default 5); mobile stays at 2 columns. the selected column count and sidebar width are stored locally and applied before the first paint.

thumbnails are generated on demand and cached in `.data/thumbnails/` as files, not image blobs in sqlite. existing files are served immediately after access checks, even with an empty browser cache. while a preview is loading or being generated, the grid, album cover or popup shows a spinner; failed previews show an error instead. unchanged previews are reused from the private browser cache after authenticated etag revalidation (304, no image body). changed previews receive a new content hash; originals and downloads remain uncached.

allow at least 120 seconds per request in your webserver and php configuration. failed ai requests can be retried after one hour.

## updates

```bash
composer update vielhuber/photobutler
npm install --omit=dev --ignore-scripts --prefix vendor/vielhuber/photobutler
./vendor/bin/photobutler-init
```
