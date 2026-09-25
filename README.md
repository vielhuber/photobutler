[![build status](https://github.com/vielhuber/photobutler/actions/workflows/ci.yml/badge.svg)](https://github.com/vielhuber/photobutler/actions)
[![github tag](https://img.shields.io/github/v/tag/vielhuber/photobutler)](https://github.com/vielhuber/photobutler/tags)
[![code style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![license](https://img.shields.io/github/license/vielhuber/photobutler)](https://github.com/vielhuber/photobutler/blob/main/LICENSE.md)
[![last commit](https://img.shields.io/github/last-commit/vielhuber/photobutler)](https://github.com/vielhuber/photobutler/commits)
[![php version support](https://img.shields.io/packagist/php-v/vielhuber/photobutler)](https://packagist.org/packages/vielhuber/photobutler)
[![packagist downloads](https://img.shields.io/packagist/dt/vielhuber/photobutler)](https://packagist.org/packages/vielhuber/photobutler)

# 📸 photobutler 📸

self-hosted photo albums with sqlite, favorites, relevance filters and german ai tags. originals stay on your storage; previews are sent to your ai provider.

## installation

requires php 8.5+, composer and the extensions `pdo_sqlite`, `gd`, `exif`, `mbstring`, `curl`, `zip`, `fileinfo`. sticker rendering and efficient source inventories additionally require node.js 22+ (npm for sticker packages); the php process must be allowed to start `node` via `proc_open`.

```bash
mkdir photobutler && cd photobutler
composer require vielhuber/photobutler
npm install --omit=dev --ignore-scripts --prefix vendor/vielhuber/photobutler
./vendor/bin/photobutler-init
```

## configuration

edit `.data/.env` (see [.env.example](.env.example)):

- `PHOTO_PATHS`: a json list of absolute photo directories, e.g. `'["/srv/photos"]'`. imports read new or changed image contents for duplicate detection; this can hydrate onedrive originals, as can viewing and tagging.
- `AI_PROVIDER`, `AI_MODEL`, `AI_BASE_URL`, `AI_API_KEY`: your ai connection with an image-capable model. the template uses `cliproxyapi` and the cost-efficient `gpt-5.6-luna`; set your gateway url and key and confirm the model is available there.
- `AUTH_USERNAME`, `AUTH_PASSWORD`: login credentials. `JWT_SECRET` is generated automatically.

serve `public/` over https and route `/index.php/login` to `public/index.php`. the web user needs read access to photos and write access to `.data/`.

## persistent login

signing in sets a host-only, HttpOnly, SameSite=Strict cookie for a fixed 365 days (Secure over HTTPS). it contains only a random 256-bit token; SQLite stores its keyed hash and expiry. requests validate it independently of PHP session storage, so browser restarts and server session cleanup do not end the login. the short-lived JWT remains only the existing sign-in handshake, not the year-long credential.

existing sessions are not silently extended: sign out and sign in once after this update to start the year. ordinary visits do not renew the deadline. logout revokes this browser's token server-side and deletes its cookies; changing AUTH_USERNAME, AUTH_PASSWORD or JWT_SECRET invalidates all logins. deleting cookies, private browsing, browser retention policies or loss of the token database may require an earlier sign-in. a stolen persistent cookie grants access until expiry or revocation, so use HTTPS and only stay signed in on trusted devices.

authentication checks use `composer test -- --filter AuthenticationTest` and `PLAYWRIGHT_MODULE=/path/to/playwright node tests/auth-browser.cjs`. the browser check uses isolated empty data and a disposable persistent profile. explicitly setting `PHOTOBUTLER_AUTH_LIVE=1` instead checks the configured HTTPS site using local credentials, without starting jobs or opening photos. it signs out its own test login and verifies revocation; it never deletes live server sessions.

## usage

open your server's url and sign in. photos load as you scroll; selected filters stay active. **Jobs** displays the PHP console commands, status, progress and remaining processing time for **Galerie einlesen**, **Thumbnails generieren**, **KI-Tagging**, and **Gesichtertagging**. each command runs exactly one independent job on the server, without requiring an open browser. importing never starts analysis or preview generation.

job execution is CLI-only. browser start, pause and step endpoints return HTTP 410; the browser polls status every three seconds and retains the independently confirmed data resets. stop a CLI run before resetting its data. page navigation, reloads and closing the browser do not affect CLI processing.

every job displays a percentage and completed/total counts. AI tags and faces use their separate current photo queues, including errors and excluded/unsupported face results. failures do not count as completed, remain visible, and require an explicit restart after the existing one-hour retry delay. import progress compares a persisted snapshot of supported source files with their available index entries (including resolved duplicate paths), not files checked in the current scan pass. an explicit start refreshes the source snapshot (metadata only), excludes missing/out-of-source files and MP4s, and resumes the existing scan checkpoint. page loads reuse this snapshot without enumerating source directories. before the first complete inventory, existing index paths are validated once and the remaining unknown total is explicitly estimated; known imported files are not reset to zero. added/removed files are reflected at the next manual start. counts describe the last captured source inventory, not a continuously monitored filesystem. preview runs process an indexed-photo snapshot (maximum ID at start), reuse the existing thumbnail generator and caches, and retain their cursor when paused; restart a completed run to include new photos or regenerate missing cache files. no original is changed.

the browser no longer displays activity logs. the console prints progress, error counts and ETA after each processing step. the existing bounded internal log history remains in SQLite. no source paths, credentials, AI responses or biometric data are logged.

each card displays an estimated remaining processing duration based on a persisted, smoothed per-file measurement. pauses and idle time are excluded; no wall-clock completion time is promised. unknown rates or incomplete source inventories remain explicitly unknown. thumbnail-only runs keep a separate timing history, so old combined thumbnail/medium durations do not distort their ETA; existing per-photo checkpoints remain intact. import estimates track files still to be checked in the scan checkpoint, independently of the persistent imported-file percentage.

preview requests process up to 25 photos in batches of at most two images, yielding after a 250 ms budget (the dispatched images finish). the job checks for a pause between batches. two deterministic generation locks cap concurrent preview conversions at two, including on-demand requests; images sharing a lock are dispatched separately. cached thumbnails skip workers entirely. an `(available, id)` index avoids sorting the remaining collection for each selection. the CLI keeps `--limit` as a photo count. the job retains the concurrency limits and existing caches.

thumbnail job cache checks only inspect local cache-file existence, using the unchanged SHA-256 of the indexed path string (not file contents). they do not stat, resolve, read or decode originals, and start no renderer for cache hits. an existing animation remains a hit for animated requests, as before. checks retain configured-root and catalog-availability validation; source existence and symlink checks still apply when serving images or generating missing thumbnails. consequently, cached entries with now-offline or changed originals count as completed until the next manual import updates availability or selectively invalidates changed thumbnails. cache integrity is not checked. CLI steps run consecutively without browser round trips; timings measure active processing, not pauses.

photos larger than 640 pixels use the existing sharp dependency with one processing thread; smaller originals and standalone thumbnail requests retain the GD path to avoid helper startup overhead. the manual preview job reuses at most two local PHP workers and their renderers across requests through private Unix sockets. each worker checks configuration before every image, recreates its library connection when configuration changes, reads current SQLite photo rows, and exits after five idle seconds. workers never discover or start jobs themselves; opening or reloading a page dispatches no work. PHP CLI, Node and writable temporary storage are required. only thumbnails are generated, at most 640 pixels and JPEG quality 65. resizing uses a faster linear filter and JPEG/WebP shrink-on-load; orientation, metadata removal and transparency flattening are preserved. existing disk caches and the sticker renderer remain untouched.

JPEG originals up to 120 megapixels are supported; other photo formats retain the 60-megapixel limit. JPEGs above 60 megapixels always use the existing sharp renderer with shrink-on-load, including on-demand generation, never a full-size GD decode. the two conversion locks, one-thread renderer, timeouts, 640-pixel/quality-65 thumbnails and existing caches remain unchanged. optional PHP EXIF read failures no longer abort thumbnail generation; catalog metadata then uses the source dimensions and file modification time. the renderer retains its existing auto-orientation, valid EXIF handling remains unchanged and invalid pixel data is still rejected. no original is rewritten.

opening a photo updates the url (`?image=123`); direct links open the photo after sign-in, and browser back/forward controls the popup. the popup uses subtle animations even when reduced motion is enabled. previews and capture dates are read on demand; until then, dates use file modification times.

the CLI requires exactly one explicit job flag; calling it without a job does no work:

```bash
php bin/photobutler-index --scan-only
php bin/photobutler-index --tag-only
php bin/photobutler-index --faces-only
php bin/photobutler-index --previews-only
```

without a limit, a command runs until its job finishes. optional `--limit=N` bounds analysis/preview runs to N photos; `--scan-limit=N` bounds an import to N checked files. processing retains batches of at most 1,000 scan entries or 25 previews with at most two concurrent conversions. Ctrl+C or SIGTERM with the PHP pcntl extension pauses after the current step; rerun the same command to resume. without pcntl, interruption is abrupt and the last committed checkpoint is retained. a process-lifetime per-job lock rejects overlapping CLI/cron invocations and conflicting resets. interrupted processes are shown as paused even after a forced kill. exit code 0 indicates success or a requested limit, 1 a processing/start failure, and 130/143 an orderly signal interruption. use the absolute command displayed on the Jobs page for cron, with the same PHP version, extensions, Node.js PATH and filesystem permissions as the application. no cron job or background service is installed.

animated webp stickers and whatsapp sticker archives disguised as webp files are rendered locally with dotlottie and sharp: the grid, album covers and popup play a cached animated webp, while ai tagging uses a static preview. originals remain unchanged, including downloads. lottie rendering is limited to 320 pixels, 30 fps and 30 seconds, with a 4 mib json limit and a 45-second rendering timeout. no external animation service is used. when running a source checkout, install dependencies with `npm install --ignore-scripts` in the project directory.

photo previews use a maximum edge of 640 pixels and jpeg quality 65; sticker previews use 320 pixels and quality 65.

the sorting dropdown uses the gallery date: newest first (default), oldest first, calendar month january–december or december–january. calendar months group photos across years, newest first within each month. sorting applies to all filtered photos before pagination and changes without restarting ai tagging.

random sorting uses a URL seed and a hash of that seed and the catalog ID (never image contents), keeping the order stable across pagination and reloads. selecting random again after another sort creates a new order. concurrent imports or filter changes can change the result set; the seed does not freeze the catalog.

the visibility filter defaults to “Eingeblendete Fotos” (favorites only, `priority = 1`); “Alle anzeigen” remains the first dropdown option and includes exclusions; the middle option “Nicht bewertete Fotos” shows only `priority = 0`, while “Ausgeblendete Fotos” shows only `priority = -1`. the single persisted `priority` column stores neutral (0), excluded (-1) or favorite (1). overview heart and exclude buttons save via AJAX with immediate optimistic feedback; clicking an active button restores neutral, and changing status updates the active filters and thumbnail opacity before the response arrives. a failed save restores the previous state and displays an error. successful removals reconcile pagination after pending ratings finish. non-favorite gallery images have opacity 0.5; the viewer and slideshow remain undimmed. schema migration preserves favorites and assigns -1 only to neutral entries with catalog dates before 2023-01-01, the WhatsApp Animated Gifs directory, or .Statuses descendants / GIF files inside _WHATSAPP, without reading or deleting originals. scans apply these automatic rules only to neutral entries using the catalog date (file modification date until thumbnail metadata is available); other manual ratings survive scans and job resets. manual favorites (1) and exclusions (-1) are never overwritten by the automatic rules; restoring neutral (0) makes a matching photo eligible for automatic exclusion on the next scan. album navigation and home album cards are removed; existing album URLs remain usable. the favorites sidebar link is replaced by a filter for all photos (default), favorites only or non-favorites. filter controls have accessible names without visible prefix labels. the search form and web query parameter are removed; tag filters remain available. direct viewer links are checked against the active filters, including the default relevance filter.

slideshow displays only the media in a full-viewport presentation, requesting browser fullscreen where supported; metadata and popup navigation are hidden. stop or Escape returns to the gallery. it uses the current filters and sort, starts with the first result and loads subsequent gallery pages as needed. each image remains visible for six seconds after its image and metadata have loaded. stopping, closing the viewer, navigating, reaching the last image or a loading failure stops automatic playback; reload never starts it. original delivery, downloads and the existing preload limits are unchanged.

the desktop grid offers 3 to 9 columns (default 5); mobile stays at 2 columns. the selected column count and sidebar width are stored locally and applied before the first paint.

thumbnails are generated on demand and cached in `.data/thumbnails/` as files, not image blobs in sqlite. existing files are served immediately after access checks, even with an empty browser cache. while a preview is loading or being generated, the grid or popup shows a spinner; failed previews show an error instead. unchanged previews are reused from the private browser cache after authenticated etag revalidation (304, no image body). changed previews receive a new content hash; originals use the same private, authenticated cache revalidation; downloads remain uncached.

popup photos load the untouched original through the existing `?photo=ID&size=original` URL. legacy `size=detail` requests also serve originals and never generate medium images. non-displayable sticker archives fall back to the existing optimized thumbnail animation; original sticker downloads remain byte-for-byte unchanged. gallery thumbnails retain their 640-pixel/quality-65 limits and sticker previews retain their existing limits. old medium cache files are left untouched but no longer used. changed sources invalidate only the thumbnail and its animation; unchanged caches remain reusable.

these detail previews are also preloaded for all currently visible gallery photos, with at most two low-priority background downloads. the popup preloads three neighbours in each direction of its current filtered, sorted slide list (the loaded pages), waiting for the displayed image first. scrolling, resizing and gallery navigation replace pending candidates; completed image objects are released and the private browser HTTP cache is reused.

allow at least 120 seconds per request in your webserver and php configuration. failed ai requests can be retried after one hour.

### byte-identical image duplicates

imports retain one indexed image per SHA-256 content fingerprint for new paths; similar-looking images and videos are not deduplicated. fingerprints are stored by source path, size and modification time, and survive catalog resets. the first fingerprint requires a complete source read and may download an online-only OneDrive file; unchanged fingerprints are reused. same-size changes with deliberately preserved modification times are not detected (the existing import freshness contract). thumbnail cache hits still check local cache existence only and never hash or stat originals.

existing indexed duplicates are audited separately with `php bin/photobutler-deduplicate` (no jobs start). only equal-size image candidates are read; interrupted audits reuse saved fingerprints. run with `--apply` to remove duplicates only after a conflict-free audit. the oldest catalog ID remains canonical; its path, URL, metadata and caches remain unchanged. priorities, manual tags, AI tags and descriptions must match exactly. any face records or face-processing state on a duplicate, differing metadata, unavailable source, or running job blocks the entire deletion phase for explicit review. no automatic decision discards a favorite, exclusion, tag or person correction. do not resolve conflicts by resetting jobs.

before deletion, a private `duplicate-backup-*` directory in `.data` receives an integrity-checked complete SQLite snapshot. exclusions, reserved IDs, cache-removal queue and index deletions commit atomically. duplicate thumbnails and animations are then moved out of the active cache into that backup, never originals or legacy medium images; retained cache keys are protected. an interrupted cache phase resumes with another `--apply` call. keep the backup until recovery is no longer needed. restoration requires stopping application writers, restoring the saved database through SQLite's backup mechanism (not overwriting a live WAL database), and moving the saved thumbnails back to `.data/thumbnails`; this restores the entire snapshot and therefore also rolls back later metadata edits.

excluded source paths remain part of import totals and count as completed when their canonical photo is available with the matching fingerprint. a changed copy is eligible as a new image; a catalog reset retains exclusions and canonical identities. automatic path/date relevance rules belong to the retained canonical photo, not to every excluded copy. content hashing runs exclusively during import or explicit duplicate cleanup.

## updates

```bash
composer update vielhuber/photobutler
npm install --omit=dev --ignore-scripts --prefix vendor/vielhuber/photobutler
./vendor/bin/photobutler-init
```

## local face recognition

face detection and biometric grouping run locally on the CPU, independently of AI tags. the gateway's documented interfaces and the available model catalog do not establish a biometric face-embedding endpoint; the tagging model is never used to compare identities. no photo or embedding is sent to an additional provider.

install the pinned runtime using **Python 3.12 on Linux x86_64**, as the same operating-system user that runs PHP:

```bash
python3.12 scripts/setup-faces.py
# composer installation, with the application's private data directory:
python3.12 vendor/vielhuber/photobutler/scripts/setup-faces.py /absolute/app/.data
```

this downloads only packages and models, never processes photos, and needs no GPU or persistent service. PHP must allow `proc_open`; the runtime lives in `.data/face-runtime`. package hashes are enforced by pip (`opencv-python-headless==4.13.0.92`, `numpy==2.2.6`); model filenames, upstream commit and SHA-256 hashes are recorded in `scripts/face-models.json` and verified both at installation and inference. `YuNet 2023mar` is deliberately used with OpenCV 4.x, not the OpenCV-5-specific 2026 model. model license texts are installed alongside the weights. preserve the Python wheels' included third-party notices when redistributing a runtime.

primary references: [OpenCV CPU/headless packages](https://github.com/opencv/opencv-python#installation-and-usage), [YuNet compatibility and MIT license](https://github.com/opencv/opencv_zoo/tree/47534e27c9851bb1128ccc0102f1145e27f23f98/models/face_detection_yunet), [SFace and Apache-2.0 license](https://github.com/opencv/opencv_zoo/tree/47534e27c9851bb1128ccc0102f1145e27f23f98/models/face_recognition_sface), [OpenCV 4.13 Apache-2.0 license](https://github.com/opencv/opencv/blob/4.13.0/LICENSE), [face alignment and comparison API](https://docs.opencv.org/4.13.0/d0/dd4/tutorial_dnn_face.html), [gateway interfaces](https://github.com/router-for-me/CLIProxyAPI).

each browser request processes one step of the explicitly selected job. existing tags are not requested again for face backfill or face retries; errors and retry delays are independent. `photobutler-index --tag-only` processes only tags; `--faces-only --limit=50` processes only faces. rerun explicitly to continue. an unchanged completed photo (including no faces or unsupported contents) is not analyzed again. stop waits for the current step; navigation into persons keeps the worker alive. allow 120 seconds per PHP request. inference is limited to two CPU threads, 60 megapixels at decode, a 1600-pixel detection image and 55 seconds; landmarks are mapped back to the EXIF-oriented original for full-resolution alignment. animated WebP and Lottie stickers use a separately rendered static frame from the existing local renderer (up to another 45 seconds), not full frame analysis. originals and all existing image/download URLs stay unchanged.

open **Personen** to inspect crops and photo counts, manually name groups, explicitly merge them (including same-name suggestions), split or move individual faces, and ignore false detections. same names alone never merge groups. person filters combine with relevance, tags, albums, favorites and sorting before pagination. image details link to the person's photos. group matching compares the normalized centroid of all current eligible faces with a cosine threshold of **0.50** and a **0.04** margin over the runner-up. every cross-pair must still reach **0.30**, preventing a chain of weak similarities from joining incompatible faces. multiple portraits in one file (for example scanned album pages, collages or reflections) may belong to the same person. grouping uses biometric similarity and manual corrections, not the assumption that one file contains each person only once. photo counts and person filters still count each source file only once. these are heuristic operating thresholds, not a guarantee of identity or an accuracy benchmark on a labeled private collection.

Existing fragmented groups can be consolidated locally without rerunning inference or paid tagging:

```sh
php bin/photobutler-index --regroup-faces
```

The strongest compatible groups are merged first; similarly plausible but incompatible alternatives remain separate. named/manual groups keep their IDs and assignments and may receive automatic fragments, but two manually curated groups never merge automatically. split/moved groups and explicit separations are excluded. consolidation is repeatable and atomic, aborting if eligible groups change during computation. protect a database backup before regrouping; uncertain leftovers can still be merged manually.

manual assignments and ignored detections survive retries on unchanged files. split/moved groups are excluded from future automatic assignments, so corrections cannot silently be bridged again; additional photos can be assigned manually. a model change never compares incompatible embeddings. manual decisions that cannot be safely transferred, particularly after file changes, remain visible as older assignments in the person view, outside current counts and filters. review these rather than assuming they refer to the new file. unavailable photos are excluded from current counts and crop access.

### face data and backups

embeddings, normalized positions, model/file versions, crops and manual decisions are stored only in the private SQLite database, behind the existing session authentication; all modifying endpoints require CSRF. face crops use `no-store`, and embeddings are not included in browser metadata. keep `.data` outside the web root with restrictive owner permissions and encrypted, access-controlled backups (including SQLite WAL files). use SQLite's backup facility or stop writers for a consistent backup; do not copy only a live database file and omit its WAL.

**KI-Tags und Gesichter zurücksetzen …** on the Jobs page clears all generated tags/descriptions and biometric data after confirmation, including person names and manual face corrections. originals, favorites and manually entered photo tags remain intact. the current step finishes first; other active tagging processes prevent the reset. the reset is atomic, refreshes the gallery without a document reload, and pauses both analysis jobs persistently until explicitly started again. existing backups can still contain the cleared data.

**Gesichtsdaten löschen** in image details deletes that photo's face records/crops and orphaned groups, and excludes it from automatic recreation. **Gesichter erneut prüfen** explicitly reenables analysis without resetting tags. SQLite secure deletion is enabled, but old WAL snapshots, filesystem snapshots and backups can retain historical biometrics: expire or securely remove those copies according to the same retention policy. loss of the database also loses manual corrections, so protect backups accordingly.

### face checks

```bash
composer lint
PHOTOBUTLER_TEST_FACE_RUNTIME="$PWD/.data/face-runtime" composer test
npm test
npm run format:check
# approved public/local test images, never a private production collection:
PHOTOBUTLER_FACE_FIXTURES=/path/to/approved-samples .data/face-runtime/bin/python tests/faces-inference.py
PHOTOBUTLER_FACE_FIXTURES=/path/to/approved-samples PLAYWRIGHT_MODULE=/path/to/playwright node tests/faces-browser.cjs
```

provide `sample-a.jpg` and `sample-b.jpg`, each with one different person, for the optional inference/browser checks. the inference check also uses a brightness variant, EXIF rotation, two-person composition and blank/unsupported inputs. the browser check creates and removes an isolated local database, uses the actual CPU runtime and real image/worker requests, and makes no AI-provider calls. Playwright is a test-only externally supplied tool, not a production dependency. without `PHOTOBUTLER_TEST_FACE_RUNTIME`, the real-CPU PHPUnit test is explicitly skipped; the deterministic grouping/state tests still run.

validation on 2026-09-09 used the public [OpenCV face sample](https://github.com/opencv/opencv/blob/master/samples/data/lena.jpg) and [second OpenCV sample](https://github.com/opencv/opencv/blob/master/samples/data/messi5.jpg), without adding them to the repository. the brightness variant scored 0.9803 against its source; the different sample scored 0.1303. two individual process measurements took 0.46–0.56 seconds with about 159 MiB peak RSS each (no sticker rendering included); these are point measurements, not collection-wide performance or accuracy guarantees.

job checks cover CLI execution, lock-protected status, rejected browser controls and independent resets. `tests/jobs-browser.cjs` checks CLI jobs with real CPU face inference; `tests/job-logs-browser.cjs` checks the log-free status page, blocked CLI workers, duplicate/reset rejection, SIGTERM/resume and cache reuse without requiring the face runtime. both browser suites use isolated temporary photos/databases and real HTTP requests, with no external AI calls or mocked processing. provide `PHOTOBUTLER_BROWSER_ARTIFACTS` to retain desktop/mobile screenshots. successful paid-provider inference is deliberately outside this fixture test; an unconfigured provider exercises the independent error/retry path.
