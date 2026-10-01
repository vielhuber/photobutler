[![build status](https://github.com/vielhuber/photobutler/actions/workflows/ci.yml/badge.svg)](https://github.com/vielhuber/photobutler/actions)
[![github tag](https://img.shields.io/github/v/tag/vielhuber/photobutler)](https://github.com/vielhuber/photobutler/tags)
[![code style](https://img.shields.io/badge/code_style-psr--12-ff69b4.svg)](https://www.php-fig.org/psr/psr-12/)
[![license](https://img.shields.io/github/license/vielhuber/photobutler)](https://github.com/vielhuber/photobutler/blob/main/LICENSE.md)
[![last commit](https://img.shields.io/github/last-commit/vielhuber/photobutler)](https://github.com/vielhuber/photobutler/commits)
[![php version support](https://img.shields.io/packagist/php-v/vielhuber/photobutler)](https://packagist.org/packages/vielhuber/photobutler)
[![packagist downloads](https://img.shields.io/packagist/dt/vielhuber/photobutler)](https://packagist.org/packages/vielhuber/photobutler)

# 📸 photobutler 📸

self-hosted photo albums with sqlite, favorites, relevance filters and an automatic ai rating. originals stay in your OneDrive; previews are sent to your ai provider. the application is pure php and runs on ordinary shared hosting.

## installation

requires php 8.5+, composer and the extensions `pdo_sqlite`, `gd`, `mbstring`, `curl`, `fileinfo`. no node.js, npm, ffmpeg, background service or root access is needed at runtime. optional local face recognition additionally needs python 3.12 with pip (see below).

```bash
mkdir photobutler && cd photobutler
composer require vielhuber/photobutler
./vendor/bin/photobutler-init
```

## configuration

edit `.data/.env` (see [.env.example](.env.example)):

- `ONEDRIVE_CLIENT_ID`, `ONEDRIVE_TENANT`, `ONEDRIVE_FOLDER`: the API connection described below; no filesystem mount is required.
- `ONEDRIVE_LEGACY_ROOT`: optionally map a catalog created by an older local-folder installation to the cloud folder without losing IDs or metadata.
- `AI_PROVIDER`, `AI_MODEL`, `AI_BASE_URL`, `AI_API_KEY`: your ai connection with an image-capable model. the template uses `cliproxyapi` and the fast `claude-haiku-4-5-20251001`; set your gateway url and key and confirm the model is available there.
- `AUTH_USERNAME`, `AUTH_PASSWORD`: login credentials. `JWT_SECRET` is generated automatically.
- `CRON_SECRET`: token for the cron url (see [cron](#cron)); generated automatically, at least 32 characters.

serve `public/` over https and route `/index.php/login` to `public/index.php`. the web user needs write access to private `.data/`; cloud originals require no filesystem mount.

## deployment on shared hosting (all-inkl)

verified on 2026-09-30 on an all-inkl account (php 8.5.9 cli and fpm, python 3.12.3 with pip 24, no node.js): all required extensions are available, `proc_open` is allowed for the web process, requests of 130 seconds complete, and face inference runs from both ssh and php-fpm.

1. in the KAS, create a (sub)domain with the document root `/photobutler/public`, select php 8.5, and enable an ssl certificate with https redirect.
2. connect via ssh and install into the account's web space next to (not inside) other document roots:

    ```bash
    cd /www/htdocs/<account>
    mkdir photobutler && cd photobutler
    composer require vielhuber/photobutler
    ./vendor/bin/photobutler-init
    ```

3. edit `.data/.env` (for example with `vim .data/.env`): OneDrive, ai and login settings. `.data/` stays outside the document root; `photobutler-init` creates it with owner-only permissions.
4. connect OneDrive as described in [OneDrive API setup](#onedrive-api-setup) with `php vendor/bin/photobutler-index --onedrive-login`.
5. optional: install the face runtime with `python3.12 vendor/vielhuber/photobutler/scripts/setup-faces.py "$PWD/.data"` (about 230 MB in `.data/face-runtime`).
6. in the KAS, add a cronjob that calls `https://<your-domain>/?cron=<CRON_SECRET>` (value from `.data/.env`), for example every 15 minutes. the first import and thumbnail download can also be started immediately via ssh with `php vendor/bin/photobutler-index --scan-only` and `--previews-only`; cron and ssh runs never overlap.

updates:

```bash
cd /www/htdocs/<account>/photobutler
composer update vielhuber/photobutler
./vendor/bin/photobutler-init
```

## cron

`https://<your-domain>/?cron=<CRON_SECRET>` resumes all four jobs in order (import, thumbnails, faces, ai rating) within one request. each call starts or resumes the jobs, runs steps for about 60 seconds and then pauses the running job at its checkpoint for the next call; a started step (for example a slow ai request) is finished first, so a call can take up to about two minutes. the ai rating is skipped while `AI_PROVIDER`, `AI_MODEL`, `AI_BASE_URL` or `AI_API_KEY` is empty, face recognition while its runtime is not installed. a job that is currently running on the console is skipped. the response is a plain-text status line per job.

a wrong token returns 403, a missing or too short `CRON_SECRET` 503. the token is part of the url and can appear in webserver access logs; keep it private and rotate it in `.data/.env` if it leaks. any scheduler that can request a url works, including an external one.

## persistent login

signing in sets a host-only, HttpOnly, SameSite=Strict cookie for a fixed 365 days (Secure over HTTPS). it contains only a random 256-bit token; SQLite stores its keyed hash and expiry. requests validate it independently of PHP session storage, so browser restarts and server session cleanup do not end the login. the short-lived JWT remains only the existing sign-in handshake, not the year-long credential.

existing sessions are not silently extended: sign out and sign in once after this update to start the year. ordinary visits do not renew the deadline. logout revokes this browser's token server-side and deletes its cookies; changing AUTH_USERNAME, AUTH_PASSWORD or JWT_SECRET invalidates all logins. deleting cookies, private browsing, browser retention policies or loss of the token database may require an earlier sign-in. a stolen persistent cookie grants access until expiry or revocation, so use HTTPS and only stay signed in on trusted devices.

authentication checks use `composer test -- --filter AuthenticationTest`.

## usage

open your server's url and sign in. photos load as you scroll; selected filters stay active. **Jobs** displays the PHP console commands, status, progress and remaining processing time for **Galerie einlesen**, **Thumbnails downloaden**, **Gesichtertagging** and **KI-Bewertung**, in this order. each command runs exactly one independent job on the server, without requiring an open browser. importing never starts analysis or preview generation.

jobs run on the console or through the [cron url](#cron), never from the gallery. browser start, pause and step endpoints return HTTP 410; the browser polls status every three seconds and retains the independently confirmed data resets. stop a running job before resetting its data. page navigation, reloads and closing the browser do not affect processing.

every job displays a percentage and completed/total counts. AI ratings and faces use their separate current photo queues, including errors and excluded/unsupported face results. failures do not count as completed, remain visible, and require an explicit restart after the existing one-hour retry delay. import progress counts the photos of the last complete OneDrive catalog. preview runs process an indexed-photo snapshot (maximum ID at start), reuse existing caches, download missing previews and retain their cursor when paused; restart a completed run to include new photos or fetch missing cache files. no original is changed.

the browser no longer displays activity logs. the console prints a labeled progress bar, completed/total counts, errors and ETA for every job. OneDrive scan totals and remaining time are provisional estimates from discovered folder metadata (or the next page for incremental changes), explicitly marked approximate and adjusted as more entries arrive. Scan work stays separate from the photo catalog; 100% appears only after completion. The OneDrive thumbnail job shows download progress without original staging or conversion phases. the existing bounded internal log history remains in SQLite. no source paths, credentials, AI responses or biometric data are logged.

each card displays an estimated remaining processing duration based on a persisted, smoothed per-file measurement. pauses and idle time are excluded; no wall-clock completion time is promised. unknown rates or incomplete source inventories remain explicitly unknown. thumbnail-only runs keep a separate timing history, so old combined thumbnail/medium durations do not distort their ETA; existing per-photo checkpoints remain intact. import estimates track files still to be checked in the scan checkpoint, independently of the persistent imported-file percentage.

preview steps process up to 100 thumbnail downloads, using Graph batches of at most 20 metadata requests and four concurrent signed thumbnail downloads. the job checks for a pause between batches. an `(available, id)` index avoids sorting the remaining collection for each selection. the CLI keeps `--limit` as a photo count.

thumbnail job cache checks inspect local cache-file existence and version-bound fallback states, using the unchanged SHA-256 of the indexed path string (not file contents). cached entries count as completed until the next import removes deleted files; cache integrity is not checked. CLI steps run consecutively without browser round trips; timings measure active processing, not pauses.

opening a photo updates the url (`?image=123`); direct links open the photo after sign-in, and browser back/forward controls the popup. the popup uses subtle animations even when reduced motion is enabled. previews and capture dates are read on demand; until then, dates use file modification times.

the CLI requires exactly one explicit job flag; calling it without a job does no work:

```bash
php bin/photobutler-index --scan-only
php bin/photobutler-index --tag-only
php bin/photobutler-index --faces-only
php bin/photobutler-index --previews-only
```

without a limit, a command runs until its job finishes. optional `--limit=N` bounds analysis/preview runs to N photos; `--scan-limit=N` bounds an import to N checked metadata entries (a soft limit at page boundaries, since imports process one metadata page per step). previews download at most 100 missing thumbnails per step, with four concurrent downloads and no conversions; existing caches are checked in one streamed catalog query and skipped before downloads. explicit `--limit` still counts all checked photos, including cache hits. Ctrl+C or SIGTERM with the PHP pcntl extension cancels active OneDrive transfers and retry waits promptly, preserving saved thumbnails and the last checkpoint; rerun the same command to resume. without pcntl, interruption is abrupt and the last committed checkpoint is retained. a process-lifetime per-job lock rejects overlapping console/cron invocations and conflicting resets. interrupted processes are shown as paused even after a forced kill. exit code 0 indicates success or a requested limit, 1 a processing/start failure, and 130/143 an orderly signal interruption.

previews use OneDrive's large JPEG or PNG thumbnails (normally up to 800 pixels), stored without resizing or recompression. animated webp originals play natively in the popup; whatsapp sticker archives and other formats the browser cannot display fall back to their static preview. originals remain unchanged, including downloads.

the sorting dropdown uses the gallery date: newest first (default), oldest first, calendar month january–december or december–january. calendar months group photos across years, newest first within each month. sorting applies to all filtered photos before pagination and changes without restarting the ai rating.

random sorting uses a URL seed and a hash of that seed and the catalog ID (never image contents), keeping the order stable across pagination and reloads. selecting random again after another sort creates a new order. concurrent imports or filter changes can change the result set; the seed does not freeze the catalog.

the visibility filter defaults to “Eingeblendete Fotos” (favorites only, `priority = 1`); “Alle anzeigen” remains the first dropdown option and includes exclusions; the middle option “Nicht bewertete Fotos” shows only `priority = 0`, while “Ausgeblendete Fotos” shows only `priority = -1`. the single persisted `priority` column stores neutral (0), excluded (-1) or favorite (1). overview heart and exclude buttons save via AJAX with immediate optimistic feedback; clicking an active button restores neutral, and changing status updates the active filters and thumbnail opacity before the response arrives. a failed save restores the previous state and displays an error. successful removals reconcile pagination after pending ratings finish. non-favorite gallery images have opacity 0.5; the viewer and slideshow remain undimmed. schema migration preserves favorites and assigns -1 only to neutral entries with capture dates before 2025-01-01 (the one-time legacy schema migration used 2023-01-01), the WhatsApp Animated Gifs directory, or .Statuses descendants / sticker folders (`… Stickers`) / GIF files inside _WHATSAPP, and to photos without any known capture date, without reading or deleting originals. scans apply these automatic rules only to neutral entries using the capture date, which every import recalculates in this order: a plausible EXIF date from OneDrive (1990 up to today), a date in the file name (for example `IMG_20161001_152611`, `PXL_20250810_…`, `IMG-20230819-WA0007`), the nearest dated folder (`2008.06` → june 2008, `2022.Q2` → april 2022, `2024` or `2016 - title` → january of that year); without any of these the upload/modification time is shown and the photo counts as undated; other manual ratings survive scans and job resets. manual favorites (1) and exclusions (-1) are never overwritten by the automatic rules; restoring neutral (0) makes a matching photo eligible for automatic exclusion on the next scan. album navigation and home album cards are removed; existing album URLs remain usable. the favorites sidebar link is replaced by a filter for all photos (default), favorites only or non-favorites. filter controls have accessible names without visible prefix labels, except the date range: **Von** and **Bis** limit the gallery to capture dates within that range once **Filtern** is clicked or enter is pressed, so typing a date never reloads the page halfway (both inclusive, either may stay empty; `from`/`to` url parameters in `yyyy-mm-dd`, invalid values are ignored). the range combines with all other filters, counts, pagination, slideshow and direct viewer links. the search form, the web query parameter and keyword tags are removed. direct viewer links are checked against the active filters, including the default relevance filter.

**KI-Bewertung** (`--tag-only`) decides for every unrated photo (`priority = 0`) whether it is shown (heart, `priority = 1`) or hidden (`priority = -1`). it sends the cached preview, the file name and the number of named persons recognized on it to the ai provider. memes, screenshots, documents, receipts, advertising, product photos, informational snapshots and misfires are hidden; photos of people (also from behind), pets, experiences, trips, visited places, accommodation and landscapes are shown, and doubtful cases are shown. the short reason appears in the image details as „KI-Bewertung: …“. photos you rated yourself are never sent or changed, and a manual change always wins. resetting the job returns only ratings that the ai set and you left unchanged to neutral, so the run can be repeated after changing `RATING_PROMPT` in `src/PhotoButler.php`. with `claude-haiku-4-5-20251001` one photo takes about 1.2 seconds (about 3.5 hours for 10,000 photos). in a visual check of 150 random photos from a real collection, the hidden ones were screenshots, documents, advertising, product photos, memes and similar; the remaining debatable cases were matters of taste such as food or flowers.

slideshow displays only the media in a full-viewport presentation, requesting browser fullscreen where supported; metadata and popup navigation are hidden. stop or Escape returns to the gallery. it uses the current filters and sort, starts with the first result and loads subsequent gallery pages as needed. each image remains visible for six seconds after its image and metadata have loaded. stopping, closing the viewer, navigating, reaching the last image or a loading failure stops automatic playback; reload never starts it. original delivery, downloads and the existing preload limits are unchanged.

the desktop grid offers 3 to 9 columns (default 5); mobile stays at 2 columns. the selected column count and sidebar width are stored locally and applied before the first paint.

thumbnails are downloaded by the explicit thumbnail job and cached in `.data/thumbnails/` as files, not image blobs in sqlite. existing files are served immediately after access checks, even with an empty browser cache. while a preview is loading, the grid or popup shows a spinner; failed previews show an error instead. unchanged previews are reused from the private browser cache after authenticated etag revalidation (304, no image body). changed previews receive a new content hash; cloud originals use metadata-based authenticated cache revalidation without hashing/downloading the original; downloads remain uncached.

popup photos stream the untouched original from OneDrive through the existing `?photo=ID&size=original` URL. legacy `size=detail` requests also serve originals. non-displayable originals fall back to the thumbnail; original downloads remain byte-for-byte unchanged. old medium and animation cache files are left untouched but no longer used. unchanged cloud content retains its thumbnails; content changes invalidate only the affected photo.

originals are preloaded on mouseover and for at most two neighbours per direction in the open popup; stale candidates are discarded. scrolling, resizing and gallery navigation replace pending candidates; completed image objects are released and the private browser HTTP cache is reused.

allow at least 120 seconds per request in your webserver and php configuration. failed ai requests can be retried after one hour.

### OneDrive API setup

1. [Register an application](https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade) for your account type, open **Authentication → Advanced settings → Allow public client flows → Yes → Save**, and add delegated **Microsoft Graph → Files.Read** (no write permissions or client secret).
2. Set `ONEDRIVE_CLIENT_ID`, `ONEDRIVE_TENANT=consumers` (personal account; tenant ID for business) and `ONEDRIVE_FOLDER=FOTOS` (your own folder relative to the drive root) in `.data/.env`. Use the **Application (client) ID**, not the Object ID.
3. Run `php bin/photobutler-index --onedrive-login` and leave the terminal open. Open the displayed Microsoft URL in any browser, enter the displayed code, sign in with the OneDrive account and approve read access. Wait for **OneDrive verbunden** in the terminal; only then is setup complete. For Composer installations, use `php vendor/bin/photobutler-index` instead.
4. Run `--scan-only`, then `--previews-only`; run `--tag-only` and `--faces-only` independently when needed, or let the [cron url](#cron) do all of it. Reauthorize with `--onedrive-login` if consent expires or is revoked.

Tokens stay in private `.data/`, never in Git or the browser. `Files.Read` is account-wide; PhotoButler limits the catalog to the selected folder. Shared-folder shortcuts are rejected. Existing installations may set `ONEDRIVE_LEGACY_ROOT` to the old source root before the first cloud import; no reset is required. Changing the selected cloud source requires a separate installation rather than silently reassigning an existing catalog.

Cloud imports read paginated metadata only and retain a delta checkpoint. Drive/item IDs preserve identity through renames; content versions selectively invalidate changed previews, never the entire cache. A failed enumeration preserves the visible catalog. Files deleted in OneDrive or moved out of the selected folder are removed completely after the next complete import: catalog entry, ratings, preview, face data and their now-empty unnamed person groups. Named persons are kept. An interrupted or incomplete enumeration never removes anything. Thumbnail runs download OneDrive's large JPEG or PNG previews for every indexed format, including photos, videos and stickers, directly into the existing cache. No original files, temporary original batches or conversion workers are used for cloud thumbnails. Each resumable step downloads at most 100 missing previews; free disk space is checked before downloads. The CLI first checks local cache existence in bulk, preserving catalog validation and the last checkpoint. Cache-only passes make no Graph requests and do not overwrite download-rate estimates. Metadata requests are grouped into Graph batches (at most 20 subrequests); signed previews download four at a time. Throttled subrequests/downloads are retried independently with Retry-After. Thumbnail downloads allow eight attempts; without Retry-After, delays increase from 5 to at most 60 seconds. The CLI shows the HTTP status and each wait. Exhausted retries pause the job with the actual HTTP status; 503/504 are not mislabeled as throttling. Provider waits above 120 seconds still pause rather than retrying early. Existing previews are reused unchanged. OneDrive previews explicitly unavailable (an empty preview listing or HTTP 406) are stored as version-bound fallback states and count as completed, not errors. The gallery displays the existing generic photo icon; no placeholder image is passed to AI tagging or face recognition. Repeated runs skip these fallback entries without network requests. A changed content version or explicit thumbnail reset clears the fallback. Invalid image data, transport and storage failures remain errors; authentication and catalog-version errors pause the job. There is no original-download fallback. Downloads are size-bounded, JPEG/PNG-validated and saved atomically; partial files are removed after failure. The historical `.jpg` cache filename remains unchanged for both formats; image delivery and analysis detect the actual content type. PNG previews retain their bytes and transparency without conversion. Restarting a completed job retries missing previews except persisted fallback entries. Old temporary originals from earlier interrupted runs are not reused or automatically deleted. OneDrive originals are never modified or deleted. No Windows companion or Files On-Demand synchronization is involved.

the AI rating and face recognition read only existing local JPEG or PNG thumbnails. Missing previews never trigger an original download, including in gallery requests. Opening/downloading an original streams it from OneDrive; cached gallery previews and face crops remain local. Keep `.data/` on local Linux storage outside any OneDrive sync folder. Smaller previews can reduce detection of small faces.

## updates

```bash
composer update vielhuber/photobutler
./vendor/bin/photobutler-init
```

`photobutler-init` adds a missing `CRON_SECRET` to existing installations. local-folder sources (`PHOTO_PATHS`), local thumbnail rendering, sticker animation rendering and `photobutler-deduplicate` have been removed; existing catalogs switch to OneDrive via `ONEDRIVE_LEGACY_ROOT` without losing IDs or ratings.

## local face recognition

face detection and biometric grouping run locally on the CPU, independently of the AI rating. the gateway's documented interfaces and the available model catalog do not establish a biometric face-embedding endpoint; the rating model is never used to compare identities. no photo or embedding is sent to an additional provider.

install the pinned runtime using **Python 3.12 with pip on Linux x86_64**, as the same operating-system user that runs PHP. no virtualenv (and therefore no `ensurepip`) is required, which keeps it working on shared hosting:

```bash
python3.12 scripts/setup-faces.py
# composer installation, with the application's private data directory:
python3.12 vendor/vielhuber/photobutler/scripts/setup-faces.py /absolute/app/.data
```

this downloads only packages and models, never processes photos, and needs no GPU or persistent service. pip installs the packages into `.data/face-runtime/packages` (`pip install --target`), the models go to `.data/face-runtime/models`; php starts `python3.12` via `proc_open` with that package directory as `PYTHONPATH`. existing `face-runtime` virtualenvs from older versions can be deleted after rerunning the setup. package hashes are enforced by pip (`opencv-python-headless==4.13.0.92`, `numpy==2.2.6`); model filenames, upstream commit and SHA-256 hashes are recorded in `scripts/face-models.json` and verified both at installation and inference. `YuNet 2023mar` is deliberately used with OpenCV 4.x, not the OpenCV-5-specific 2026 model. model license texts are installed alongside the weights. preserve the Python wheels' included third-party notices when redistributing a runtime.

primary references: [OpenCV CPU/headless packages](https://github.com/opencv/opencv-python#installation-and-usage), [YuNet compatibility and MIT license](https://github.com/opencv/opencv_zoo/tree/47534e27c9851bb1128ccc0102f1145e27f23f98/models/face_detection_yunet), [SFace and Apache-2.0 license](https://github.com/opencv/opencv_zoo/tree/47534e27c9851bb1128ccc0102f1145e27f23f98/models/face_recognition_sface), [OpenCV 4.13 Apache-2.0 license](https://github.com/opencv/opencv/blob/4.13.0/LICENSE), [face alignment and comparison API](https://docs.opencv.org/4.13.0/d0/dd4/tutorial_dnn_face.html), [gateway interfaces](https://github.com/router-for-me/CLIProxyAPI).

each CLI iteration processes one step of its explicitly selected job. existing AI ratings are not requested again for face backfill or face retries; errors and retry delays are independent. `photobutler-index --tag-only` processes only AI ratings; `--faces-only --limit=50` processes only faces. rerun explicitly to continue. an unchanged completed photo (including no faces or unsupported contents) is not analyzed again. only photos that are not hidden (“Ausgeblendete Fotos”, `priority = -1`) are analyzed and counted; hiding a photo keeps its existing face data, showing it again queues it for analysis. photos without an OneDrive preview count as unsupported instead of failing. Ctrl+C waits for the current CLI step; browser navigation has no effect on the job. allow 120 seconds per PHP request. inference is limited to two CPU threads, 60 megapixels at decode, a 1600-pixel detection image and 55 seconds; alignment and detection use only the existing cached JPEG or PNG thumbnail. stickers also use that cached static preview, with no original reads or additional rendering. originals and all existing image/download URLs stay unchanged.

open **Personen** to inspect crops and photo counts, manually name groups, explicitly merge them (including same-name suggestions), split or move individual faces, and ignore false detections. same names alone never merge groups. person filters combine with relevance, albums, favorites and sorting before pagination. image details link to the person's photos. group matching compares the normalized centroid of all current eligible faces with a cosine threshold of **0.363** (the same-identity threshold recommended in the [OpenCV 4.13 face tutorial](https://docs.opencv.org/4.13.0/d0/dd4/tutorial_dnn_face.html) for SFace; it favors merging over fragmentation) and a **0.02** margin over the runner-up (a face equally similar to two people, for example siblings, starts its own group). in addition, the average similarity to all members of the group must reach **0.33**, so a few similar faces cannot connect different people; a hard minimum for every single pair is deliberately not used, because profile and frontal views of the same person often score below 0.30. once you have curated persons, new faces are matched against them first: a named, visible person wins when the mean similarity to its **3** most similar faces reaches **0.40** and beats every other named person and the single most similar face of any hidden person by the **0.02** margin. this ignores the automatic-matching lock that splits and moves put on a group, because a name marks a curated identity. otherwise the centroid rule above runs over hidden and unnamed groups only, so new photos of a hidden stranger keep collecting in that hidden group. existing assignments are never changed by this; manual corrections survive re-analysis. in a curated collection (10 named and 195 hidden persons, about 9,800 faces), a replay in which each of the newest 20% of faces only sees faces taken before it assigned 96.2% of named faces correctly, mixed up no named persons and put 1.9% of hidden faces into a named group; a 5-fold check gave 97.1% and 2.1%. without any curation during the four months of the newest 20% the rate drops to 92.1% (3.0% of hidden faces in named groups), mostly because children's faces change, so correcting new photos now and then keeps matching accurate. faces narrower than **4%** of the image (about 32 pixels in the OneDrive previews) are stored but never assigned automatically: their embeddings are unreliable and they are mostly strangers in the background. **Personen** lists unnamed groups only from **3** photos on at least **2** different days; named or manually corrected groups are always listed, and **Ausgeblendete Gruppen anzeigen** switches to exactly the other groups (rarely seen and manually hidden ones) for naming or merging; **Eingeblendete Personen anzeigen** switches back. a person's preview image is the newest photo on which the face covers at least **10%** of the image width (the larger face wins on the same date); without such a face the newest smaller one is used. **Person ausblenden** in a person's details hides that person manually from **Personen**, the person filter and the names in the image viewer, even when named; new faces keep being assigned to the hidden group, so a hidden stranger does not reappear as a new group. they appear under **Ausgeblendete Gruppen** with a marker, and **Person einblenden** reverses it. faces are detected with a minimum confidence of **0.8** (lowered from 0.9: in a sample of 300 photos it found 84% more faces, nearly all real, among them half-covered, tilted and side views; the few non-faces are mostly small and never assigned automatically). photos analyzed with the old threshold are queued once more for the face job; this second pass and **Gesichter erneut prüfen** only add faces that do not overlap a known one (overlap of at least 30%), so every existing face, assignment, name, hidden group and ignored detection stays exactly as it is. only a changed image file is analyzed from scratch. all values are constants in `src/FaceStore.php` and `scripts/analyze-faces.py`; they were tuned by visual review of about 1,200 faces from a real family collection. after changing them, reset the face job and run it again, because `--regroup-faces` can only merge groups, not split them. multiple portraits in one file (for example scanned album pages, collages or reflections) may belong to the same person. grouping uses biometric similarity and manual corrections, not the assumption that one file contains each person only once. photo counts and person filters still count each source file only once. these are heuristic operating thresholds, not a guarantee of identity or an accuracy benchmark on a labeled private collection.

Existing fragmented groups can be consolidated locally without rerunning inference or the AI rating:

```sh
php bin/photobutler-index --regroup-faces
```

The strongest compatible groups are merged first; similarly plausible but incompatible alternatives remain separate. named/manual groups keep their IDs and assignments and may receive automatic fragments, but two manually curated groups never merge automatically. split/moved groups and explicit separations are excluded. consolidation is repeatable and atomic, aborting if eligible groups change during computation. protect a database backup before regrouping; uncertain leftovers can still be merged manually.

manual assignments and ignored detections survive retries on unchanged files. split/moved groups are excluded from future automatic assignments, so corrections cannot silently be bridged again; additional photos can be assigned manually. a model change never compares incompatible embeddings. manual decisions that cannot be safely transferred, particularly after file changes, remain visible as older assignments in the person view, outside current counts and filters. review these rather than assuming they refer to the new file. unavailable photos are excluded from current counts and crop access.

### face data and backups

embeddings, normalized positions, model/file versions, crops and manual decisions are stored only in the private SQLite database, behind the existing session authentication; all modifying endpoints require CSRF. face crops use `no-store`, and embeddings are not included in browser metadata. keep `.data` outside the web root with restrictive owner permissions and encrypted, access-controlled backups (including SQLite WAL files). use SQLite's backup facility or stop writers for a consistent backup; do not copy only a live database file and omit its WAL.

The Jobs page offers separate confirmed resets for import, thumbnails, AI ratings and face recognition. Stop the affected CLI job first. Resets retain originals, ratings you set or changed and protected person corrections and never restart a job automatically.

**Gesichtsdaten löschen** in image details deletes that photo's face records/crops and orphaned groups, and excludes it from automatic recreation. **Gesichter erneut prüfen** explicitly reenables analysis without resetting the AI rating. SQLite secure deletion is enabled, but old WAL snapshots, filesystem snapshots and backups can retain historical biometrics: expire or securely remove those copies according to the same retention policy. loss of the database also loses manual corrections, so protect backups accordingly.

### face checks

```bash
composer lint
PHOTOBUTLER_TEST_FACE_RUNTIME="$PWD/.data/face-runtime" composer test
npm run format:check
# approved public/local test images, never a private production collection:
PHOTOBUTLER_FACE_FIXTURES=/path/to/approved-samples PYTHONPATH=.data/face-runtime/packages python3.12 tests/faces-inference.py
```

provide `sample-a.jpg` and `sample-b.jpg`, each with one different person, for the optional inference check. it also uses a brightness variant, EXIF rotation, two-person composition and blank/unsupported inputs. all tests are php (phpunit) except this optional python inference check; `npm run format:check` (prettier) is the only node.js tool left and is not needed on the server. without `PHOTOBUTLER_TEST_FACE_RUNTIME`, the real-CPU PHPUnit test is explicitly skipped; the deterministic grouping/state tests still run.

validation on 2026-09-09 used the public [OpenCV face sample](https://github.com/opencv/opencv/blob/master/samples/data/lena.jpg) and [second OpenCV sample](https://github.com/opencv/opencv/blob/master/samples/data/messi5.jpg), without adding them to the repository. the brightness variant scored 0.9803 against its source; the different sample scored 0.1303. two individual process measurements took 0.46–0.56 seconds with about 159 MiB peak RSS each; on the all-inkl host a single detection took 0.43 seconds; these are point measurements, not collection-wide performance or accuracy guarantees.

job checks cover CLI execution, lock-protected status, rejected browser controls, independent resets, the cron url and OneDrive transfers against a local php https fixture server (batches, parallel downloads, retries, signals), with no external AI or Graph calls.
