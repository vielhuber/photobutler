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

- `ONEDRIVE_CLIENT_ID`, `ONEDRIVE_TENANT`, `ONEDRIVE_FOLDER`: the API connection described below; no filesystem mount is required.
- `ONEDRIVE_LEGACY_ROOT`: optionally map an existing local catalog to the cloud folder without losing IDs or metadata.
- `PHOTO_PATHS`: legacy local directories only; leave as `[]` for OneDrive API operation. Imports compare relative paths, never original-content hashes.
- `AI_PROVIDER`, `AI_MODEL`, `AI_BASE_URL`, `AI_API_KEY`: your ai connection with an image-capable model. the template uses `cliproxyapi` and the cost-efficient `gpt-5.6-luna`; set your gateway url and key and confirm the model is available there.
- `AUTH_USERNAME`, `AUTH_PASSWORD`: login credentials. `JWT_SECRET` is generated automatically.

serve `public/` over https and route `/index.php/login` to `public/index.php`. the web user needs write access to private `.data/`; cloud originals require no filesystem mount.

## persistent login

signing in sets a host-only, HttpOnly, SameSite=Strict cookie for a fixed 365 days (Secure over HTTPS). it contains only a random 256-bit token; SQLite stores its keyed hash and expiry. requests validate it independently of PHP session storage, so browser restarts and server session cleanup do not end the login. the short-lived JWT remains only the existing sign-in handshake, not the year-long credential.

existing sessions are not silently extended: sign out and sign in once after this update to start the year. ordinary visits do not renew the deadline. logout revokes this browser's token server-side and deletes its cookies; changing AUTH_USERNAME, AUTH_PASSWORD or JWT_SECRET invalidates all logins. deleting cookies, private browsing, browser retention policies or loss of the token database may require an earlier sign-in. a stolen persistent cookie grants access until expiry or revocation, so use HTTPS and only stay signed in on trusted devices.

authentication checks use `composer test -- --filter AuthenticationTest` and `PLAYWRIGHT_MODULE=/path/to/playwright node tests/auth-browser.cjs`. the browser check uses isolated empty data and a disposable persistent profile. explicitly setting `PHOTOBUTLER_AUTH_LIVE=1` instead checks the configured HTTPS site using local credentials, without starting jobs or opening photos. it signs out its own test login and verifies revocation; it never deletes live server sessions.

## usage

open your server's url and sign in. photos load as you scroll; selected filters stay active. **Jobs** displays the PHP console commands, status, progress and remaining processing time for **Galerie einlesen**, **Thumbnails downloaden**, **KI-Tagging**, and **Gesichtertagging**. each command runs exactly one independent job on the server, without requiring an open browser. importing never starts analysis or preview generation.

job execution is CLI-only. browser start, pause and step endpoints return HTTP 410; the browser polls status every three seconds and retains the independently confirmed data resets. stop a CLI run before resetting its data. page navigation, reloads and closing the browser do not affect CLI processing.

every job displays a percentage and completed/total counts. AI tags and faces use their separate current photo queues, including errors and excluded/unsupported face results. failures do not count as completed, remain visible, and require an explicit restart after the existing one-hour retry delay. import progress compares a persisted snapshot of supported source files with their available index entries (one catalog entry per source path), not files checked in the current scan pass. for legacy local sources, an explicit start refreshes the source snapshot (metadata only), excludes missing/out-of-source files and MP4s, and resumes the existing scan checkpoint. page loads reuse this snapshot without enumerating source directories. before the first complete inventory, existing index paths are validated once and the remaining unknown total is explicitly estimated; known imported files are not reset to zero. added/removed files are reflected at the next manual start. counts describe the last captured source inventory, not a continuously monitored filesystem. preview runs process an indexed-photo snapshot (maximum ID at start), reuse existing caches (OneDrive downloads missing previews; legacy local sources render them), and retain their cursor when paused; restart a completed run to include new photos or regenerate missing cache files. no original is changed.

the browser no longer displays activity logs. the console prints a labeled progress bar, completed/total counts, errors and ETA for every job. OneDrive scan totals and remaining time are provisional estimates from discovered folder metadata (or the next page for incremental changes), explicitly marked approximate and adjusted as more entries arrive. Scan work stays separate from the photo catalog; 100% appears only after completion. The OneDrive thumbnail job shows download progress without original staging or conversion phases. the existing bounded internal log history remains in SQLite. no source paths, credentials, AI responses or biometric data are logged.

each card displays an estimated remaining processing duration based on a persisted, smoothed per-file measurement. pauses and idle time are excluded; no wall-clock completion time is promised. unknown rates or incomplete source inventories remain explicitly unknown. thumbnail-only runs keep a separate timing history, so old combined thumbnail/medium durations do not distort their ETA; existing per-photo checkpoints remain intact. import estimates track files still to be checked in the scan checkpoint, independently of the persistent imported-file percentage.

cloud preview steps process up to 100 thumbnail downloads without an original-byte budget, using Graph batches of at most 20 metadata requests and four concurrent signed thumbnail downloads; legacy local steps process up to 25 photos with a 250 ms processing budget. the job checks for a pause between batches. two deterministic generation locks cap concurrent preview conversions at two, including on-demand requests; images sharing a lock are dispatched separately. cached thumbnails skip workers entirely. an `(available, id)` index avoids sorting the remaining collection for each selection. the CLI keeps `--limit` as a photo count. the job retains the concurrency limits and existing caches.

thumbnail job cache checks only inspect local cache-file existence, using the unchanged SHA-256 of the indexed path string (not file contents). they do not stat, resolve, read or decode originals, and start no renderer for cache hits. an animation without its static preview is not sufficient for the thumbnail job, because analysis requires that static preview. checks retain configured-root and catalog-availability validation; source existence and symlink checks still apply when serving images or generating missing thumbnails. consequently, cached entries with now-offline or changed originals count as completed until the next manual import updates availability. for legacy local sources, reusing the same path intentionally retains the thumbnail even if file contents or timestamps change; use the explicit thumbnail reset to regenerate such content. cache integrity is not checked. CLI steps run consecutively without browser round trips; timings measure active processing, not pauses.

For legacy local sources only, photos larger than 640 pixels use the existing sharp dependency with one processing thread; smaller originals and standalone thumbnail requests retain the GD path to avoid helper startup overhead. the manual preview job reuses at most two local PHP workers and their renderers across requests through private Unix sockets. each worker checks configuration before every image, recreates its library connection when configuration changes, reads current SQLite photo rows, and exits after five idle seconds. workers never discover or start jobs themselves; opening or reloading a page dispatches no work. PHP CLI, Node and writable temporary storage are required. only thumbnails are generated, at most 640 pixels and JPEG quality 65. resizing uses a faster linear filter and JPEG/WebP shrink-on-load; orientation, metadata removal and transparency flattening are preserved. existing disk caches and the sticker renderer remain untouched.

JPEG originals up to 120 megapixels are supported; other photo formats retain the 60-megapixel limit. JPEGs above 60 megapixels always use the existing sharp renderer with shrink-on-load, including on-demand generation, never a full-size GD decode. the two conversion locks, one-thread renderer, timeouts, 640-pixel/quality-65 thumbnails and existing caches remain unchanged. optional PHP EXIF read failures no longer abort thumbnail generation; catalog metadata then uses the source dimensions and file modification time. the renderer retains its existing auto-orientation, valid EXIF handling remains unchanged and invalid pixel data is still rejected. no original is rewritten.

opening a photo updates the url (`?image=123`); direct links open the photo after sign-in, and browser back/forward controls the popup. the popup uses subtle animations even when reduced motion is enabled. previews and capture dates are read on demand; until then, dates use file modification times.

the CLI requires exactly one explicit job flag; calling it without a job does no work:

```bash
php bin/photobutler-index --scan-only
php bin/photobutler-index --tag-only
php bin/photobutler-index --faces-only
php bin/photobutler-index --previews-only
```

without a limit, a command runs until its job finishes. optional `--limit=N` bounds analysis/preview runs to N photos; `--scan-limit=N` bounds an import to N checked files. cloud imports process one metadata page per step (`--scan-limit` is a soft page-boundary limit); cloud previews download at most 100 missing thumbnails per step, with four concurrent downloads and no conversions; existing caches are checked in one streamed catalog query and skipped before downloads, without the 100-photo scan cap. Explicit --limit still counts all checked photos, including cache hits. local sources retain their existing scan/preview step limits. Ctrl+C or SIGTERM with the PHP pcntl extension cancels active OneDrive transfers and retry waits promptly, preserving saved thumbnails and the last checkpoint; local jobs pause after the current step; rerun the same command to resume. without pcntl, interruption is abrupt and the last committed checkpoint is retained. a process-lifetime per-job lock rejects overlapping CLI/cron invocations and conflicting resets. interrupted processes are shown as paused even after a forced kill. exit code 0 indicates success or a requested limit, 1 a processing/start failure, and 130/143 an orderly signal interruption. use the absolute command displayed on the Jobs page for cron, with the same PHP version, extensions, Node.js PATH and filesystem permissions as the application. no cron job or background service is installed.

animated webp stickers and whatsapp sticker archives disguised as webp files are rendered locally with dotlottie and sharp: the grid, album covers and popup play a cached animated webp, while ai tagging uses a static preview. originals remain unchanged, including downloads. lottie rendering is limited to 320 pixels, 30 fps and 30 seconds, with a 4 mib json limit and a 45-second rendering timeout. no external animation service is used. when running a source checkout, install dependencies with `npm install --ignore-scripts` in the project directory.

OneDrive previews use the provider’s large JPEG or PNG thumbnails (normally up to 800 pixels), stored without resizing or recompression. Legacy local photo previews use a maximum edge of 640 pixels and jpeg quality 65; sticker previews use 320 pixels and quality 65.

the sorting dropdown uses the gallery date: newest first (default), oldest first, calendar month january–december or december–january. calendar months group photos across years, newest first within each month. sorting applies to all filtered photos before pagination and changes without restarting ai tagging.

random sorting uses a URL seed and a hash of that seed and the catalog ID (never image contents), keeping the order stable across pagination and reloads. selecting random again after another sort creates a new order. concurrent imports or filter changes can change the result set; the seed does not freeze the catalog.

the visibility filter defaults to “Eingeblendete Fotos” (favorites only, `priority = 1`); “Alle anzeigen” remains the first dropdown option and includes exclusions; the middle option “Nicht bewertete Fotos” shows only `priority = 0`, while “Ausgeblendete Fotos” shows only `priority = -1`. the single persisted `priority` column stores neutral (0), excluded (-1) or favorite (1). overview heart and exclude buttons save via AJAX with immediate optimistic feedback; clicking an active button restores neutral, and changing status updates the active filters and thumbnail opacity before the response arrives. a failed save restores the previous state and displays an error. successful removals reconcile pagination after pending ratings finish. non-favorite gallery images have opacity 0.5; the viewer and slideshow remain undimmed. schema migration preserves favorites and assigns -1 only to neutral entries with catalog dates before 2023-01-01, the WhatsApp Animated Gifs directory, or .Statuses descendants / GIF files inside _WHATSAPP, without reading or deleting originals. scans apply these automatic rules only to neutral entries using the catalog date (file modification date until thumbnail metadata is available); other manual ratings survive scans and job resets. manual favorites (1) and exclusions (-1) are never overwritten by the automatic rules; restoring neutral (0) makes a matching photo eligible for automatic exclusion on the next scan. album navigation and home album cards are removed; existing album URLs remain usable. the favorites sidebar link is replaced by a filter for all photos (default), favorites only or non-favorites. filter controls have accessible names without visible prefix labels. the search form and web query parameter are removed; tag filters remain available. direct viewer links are checked against the active filters, including the default relevance filter.

slideshow displays only the media in a full-viewport presentation, requesting browser fullscreen where supported; metadata and popup navigation are hidden. stop or Escape returns to the gallery. it uses the current filters and sort, starts with the first result and loads subsequent gallery pages as needed. each image remains visible for six seconds after its image and metadata have loaded. stopping, closing the viewer, navigating, reaching the last image or a loading failure stops automatic playback; reload never starts it. original delivery, downloads and the existing preload limits are unchanged.

the desktop grid offers 3 to 9 columns (default 5); mobile stays at 2 columns. the selected column count and sidebar width are stored locally and applied before the first paint.

thumbnails are generated by the explicit thumbnail job and cached in `.data/thumbnails/` as files, not image blobs in sqlite. existing files are served immediately after access checks, even with an empty browser cache. while a preview is loading, the grid or popup shows a spinner; failed previews show an error instead. unchanged previews are reused from the private browser cache after authenticated etag revalidation (304, no image body). changed previews receive a new content hash; cloud originals use metadata-based authenticated cache revalidation without hashing/downloading the original; downloads remain uncached.

popup photos load the untouched original through the existing `?photo=ID&size=original` URL. legacy `size=detail` requests also serve originals and never generate medium images. non-displayable sticker archives fall back to the existing optimized thumbnail animation; original sticker downloads remain byte-for-byte unchanged. legacy local gallery thumbnails retain their 640-pixel/quality-65 limits and sticker previews retain their existing limits. old medium cache files are left untouched but no longer used. unchanged cloud content retains its thumbnails and animations; content changes invalidate only the affected photo. legacy path-only imports retain their existing caches.

originals are preloaded on mouseover and for at most two neighbours per direction in the open popup; stale candidates are discarded. scrolling, resizing and gallery navigation replace pending candidates; completed image objects are released and the private browser HTTP cache is reused.

allow at least 120 seconds per request in your webserver and php configuration. failed ai requests can be retried after one hour.

### OneDrive API setup

1. [Register an application](https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade) for your account type, open **Authentication → Advanced settings → Allow public client flows → Yes → Save**, and add delegated **Microsoft Graph → Files.Read** (no write permissions or client secret).
2. Set `ONEDRIVE_CLIENT_ID`, `ONEDRIVE_TENANT=consumers` (personal account; tenant ID for business) and `ONEDRIVE_FOLDER=FOTOS` (your own folder relative to the drive root) in `.data/.env`. Use the **Application (client) ID**, not the Object ID.
3. Run `php bin/photobutler-index --onedrive-login` and leave the terminal open. Open the displayed Microsoft URL in any browser, enter the displayed code, sign in with the OneDrive account and approve read access. Wait for **OneDrive verbunden** in the terminal; only then is setup complete. For Composer installations, use `php vendor/bin/photobutler-index` instead.
4. Run `--scan-only`, then `--previews-only`; run `--tag-only` and `--faces-only` independently when needed. Cron uses the same commands and operating-system user. Reauthorize with `--onedrive-login` if consent expires or is revoked.

Tokens stay in private `.data/`, never in Git or the browser. `Files.Read` is account-wide; PhotoButler limits the catalog to the selected folder. Shared-folder shortcuts are rejected. Existing installations may set `ONEDRIVE_LEGACY_ROOT` to the old source root before the first cloud import; no reset is required. Changing the selected cloud source requires a separate installation rather than silently reassigning an existing catalog.

Cloud imports read paginated metadata only and retain a delta checkpoint. Drive/item IDs preserve identity through renames; content versions selectively invalidate changed previews, never the entire cache. A failed enumeration preserves the visible catalog. Thumbnail runs download OneDrive's large JPEG or PNG previews for every indexed format, including photos, videos and stickers, directly into the existing cache. No original files, temporary original batches or conversion workers are used for cloud thumbnails. Each resumable step downloads at most 100 missing previews; free disk space is checked before downloads. The CLI first checks local cache existence in bulk, preserving catalog validation and the last checkpoint. Cache-only passes make no Graph requests and do not overwrite download-rate estimates. Metadata requests are grouped into Graph batches (at most 20 subrequests); signed previews download four at a time. Throttled subrequests/downloads are retried independently with Retry-After. Thumbnail downloads allow eight attempts; without Retry-After, delays increase from 5 to at most 60 seconds. The CLI shows the HTTP status and each wait. Exhausted retries pause the job with the actual HTTP status; 503/504 are not mislabeled as throttling. Provider waits above 120 seconds still pause rather than retrying early. Existing previews are reused unchanged. Missing, invalid or failed preview transfers are counted as errors and skipped without an original-download fallback; authentication, metadata and catalog-version errors pause the job. Downloads are size-bounded, JPEG/PNG-validated and saved atomically; partial files are removed after failure. The historical `.jpg` cache filename remains unchanged for both formats; image delivery and analysis detect the actual content type. PNG previews retain their bytes and transparency without conversion. Restarting a completed job retries missing previews. Old temporary originals from earlier interrupted runs are not reused or automatically deleted. OneDrive originals are never modified or deleted. No Windows companion or Files On-Demand synchronization is involved. Legacy thumbnail jobs on Windows mounts are blocked until API setup, preventing accidental mass hydration.

AI tagging and face recognition read only existing local JPEG or PNG thumbnails. Missing previews never trigger an original download, including in gallery requests. Opening/downloading an original streams it from OneDrive; cached gallery previews and face crops remain local. Keep `.data/` on local Linux storage outside any OneDrive sync folder. Smaller previews can reduce detection of small faces.

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

each CLI iteration processes one step of its explicitly selected job. existing tags are not requested again for face backfill or face retries; errors and retry delays are independent. `photobutler-index --tag-only` processes only tags; `--faces-only --limit=50` processes only faces. rerun explicitly to continue. an unchanged completed photo (including no faces or unsupported contents) is not analyzed again. Ctrl+C waits for the current CLI step; browser navigation has no effect on the job. allow 120 seconds per PHP request. inference is limited to two CPU threads, 60 megapixels at decode, a 1600-pixel detection image and 55 seconds; alignment and detection use only the existing cached JPEG or PNG thumbnail. animated WebP and Lottie stickers also use that cached static preview, with no original reads or additional rendering. originals and all existing image/download URLs stay unchanged.

open **Personen** to inspect crops and photo counts, manually name groups, explicitly merge them (including same-name suggestions), split or move individual faces, and ignore false detections. same names alone never merge groups. person filters combine with relevance, tags, albums, favorites and sorting before pagination. image details link to the person's photos. group matching compares the normalized centroid of all current eligible faces with a cosine threshold of **0.50** and a **0.04** margin over the runner-up. every cross-pair must still reach **0.30**, preventing a chain of weak similarities from joining incompatible faces. multiple portraits in one file (for example scanned album pages, collages or reflections) may belong to the same person. grouping uses biometric similarity and manual corrections, not the assumption that one file contains each person only once. photo counts and person filters still count each source file only once. these are heuristic operating thresholds, not a guarantee of identity or an accuracy benchmark on a labeled private collection.

Existing fragmented groups can be consolidated locally without rerunning inference or paid tagging:

```sh
php bin/photobutler-index --regroup-faces
```

The strongest compatible groups are merged first; similarly plausible but incompatible alternatives remain separate. named/manual groups keep their IDs and assignments and may receive automatic fragments, but two manually curated groups never merge automatically. split/moved groups and explicit separations are excluded. consolidation is repeatable and atomic, aborting if eligible groups change during computation. protect a database backup before regrouping; uncertain leftovers can still be merged manually.

manual assignments and ignored detections survive retries on unchanged files. split/moved groups are excluded from future automatic assignments, so corrections cannot silently be bridged again; additional photos can be assigned manually. a model change never compares incompatible embeddings. manual decisions that cannot be safely transferred, particularly after file changes, remain visible as older assignments in the person view, outside current counts and filters. review these rather than assuming they refer to the new file. unavailable photos are excluded from current counts and crop access.

### face data and backups

embeddings, normalized positions, model/file versions, crops and manual decisions are stored only in the private SQLite database, behind the existing session authentication; all modifying endpoints require CSRF. face crops use `no-store`, and embeddings are not included in browser metadata. keep `.data` outside the web root with restrictive owner permissions and encrypted, access-controlled backups (including SQLite WAL files). use SQLite's backup facility or stop writers for a consistent backup; do not copy only a live database file and omit its WAL.

The Jobs page offers separate confirmed resets for import, thumbnails, AI tags and face recognition. Stop the affected CLI job first. Resets retain originals, favorites, manual tags and protected person corrections and never restart a job automatically.

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
