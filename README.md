# NextCraft

By [GameDirection](https://github.com/Gamedirection).

Open and edit your Nextcloud files in the open-source [Craft apps](https://github.com/storytold): WordCraft, GridCraft, DeckCraft, PdfCraft, PhotoCraft, LightCraft, VectorCraft, DesignCraft, FigCraft, CADCraft, EffectCraft, FilmCraft, SoundCraft, TextureCraft, MaterialCraft and Craft Anything. They run in the browser (WebAssembly) on your Nextcloud's own address, so the Nextcloud login is the only login.

- **Open with…** on any supported file in Files: `.docx` in WordCraft or Nextcloud Office, `.ai`/`.eps` in VectorCraft, `.indd`/`.idml` in DesignCraft, `.af` in PhotoCraft or VectorCraft, `.psd` in PhotoCraft, `.xlsx` in GridCraft, `.dxf` in CADCraft, `.pdf` in PdfCraft, raw photos in LightCraft…
- **Default per file type.** Clicking a file follows the user's choice: ask each time (with "always use this"), a Craft app, or Nextcloud's own viewer/editor. Users change it in Personal settings ▸ NextCraft. Office formats ask by default; formats Nextcloud can't open go straight to their Craft app.
- **Save.** Saving under the same name updates the file in Nextcloud. Versions are kept, and if someone else changed it meanwhile you get a conflict prompt instead of an overwrite. Anything else (Save As, exports, formats the app can't write back) asks: **Save to Nextcloud** (any folder) or **Download to device**.
- **Open from inside the apps.** An app's own Open/Place dialog offers "From Nextcloud" or "From this device".
- **Every app works the same way.** Whether it is WordCraft or SoundCraft, opening from Files, the app's own Open/Import dialog and every save or export go through NextCraft.
- **Locks.** With the Files lock app enabled, an opened file is locked for others while you edit it.
- **Live sessions.** FigCraft's live co-editing can use a self-hosted `figcraft-server` (see below). For co-editing Office documents, use Nextcloud Office (Collabora), which stays available in "Open with…".

## Install

Requirements: Nextcloud 34–35, PHP zip extension, outbound HTTPS to `api.github.com`, `github.com` and `objects.githubusercontent.com`, and about 400 MB free in the apps folder if you install every app.

```sh
git clone <this repository> nextcraft && cd nextcraft
deploy/install.sh --docker nextcloud          # Nextcloud in a container called "nextcloud"
deploy/install.sh --path /var/www/nextcloud   # or Nextcloud on this machine
```

The script builds the front end (with local Node ≥ 20, or in a `node:22-alpine` container), copies the app into the writable apps folder, enables it and installs every Craft app. Options: `--apps "wordcraft gridcraft"`, `--apps none`, `--user <web server user>`, `--skip-build`. Re-run it to upgrade NextCraft; installed Craft apps are kept.

To try it on a few accounts first: `occ app:enable nextcraft --groups admin`.

## ArtCraft Launcher (optional)

`launcher/` is a git submodule: GameDirection's self-maintained build of the ArtCraft Launcher ([Gamedirection/craft-launcher](https://github.com/Gamedirection/craft-launcher), branch `NextCraft`). It is not an official storytold release. Installed, it appears as **ArtCraft Launcher** at the end of Nextcloud's app menu: the whole Craft suite on one page, where **Open** starts each installed app in NextCraft (apps that aren't installed keep their download links). NextCraft works without it.

```sh
git submodule update --init launcher
deploy/install-launcher.sh --docker nextcloud      # or --path /var/www/nextcloud
```

It needs Docker for the Rust/WebAssembly build. To update, pull the submodule (`git submodule update --remote launcher`) and run the script again. Launcher builds are never replaced by `occ nextcraft:update` or the update check.

## Managing the Craft apps

Administration settings ▸ NextCraft lists every app with its installed and latest version, plus Install, Update, Roll back and Remove buttons. From the command line:

```sh
occ nextcraft:status --check            # installed vs latest
occ nextcraft:install --all             # or: occ nextcraft:install wordcraft vectorcraft
occ nextcraft:update                    # update everything installed
occ nextcraft:update vectorcraft --tag v0.19.0
occ nextcraft:update vectorcraft --rollback
occ nextcraft:remove texturecraft
```

Each app is downloaded from its GitHub release (`<app>-web-<version>.zip`), checked against the release's `SHA256SUMS.txt` and unpacked into `<apps folder>/nextcraft/craft/<app>/<version>/`. The previous version stays on disk for rollback. A copy of each zip is kept in app data, so a NextCraft upgrade (which replaces the app folder) restores them without downloading again.

A daily background job looks for new releases. It notifies admins, or installs them when **Install new releases automatically** is on. Without a GitHub token the server may make 60 GitHub API requests per hour; a token with no scopes (admin settings) raises that.

## Live sessions (FigCraft)

FigCraft co-editing needs a `figcraft-server` reachable over `wss://`. `deploy/figcraft/` has a compose file and proxy snippets. Put the server's address in Administration settings ▸ NextCraft ▸ Live sessions.

## How it works

- `lib/catalog.json` is the single source of truth: apps, the extensions each opens, which ones it can't write back, default handlers and the MIME types NextCraft registers (`config/mimetypemapping.json`, merged, removed again on disable).
- Files gets two actions: a default click action (ranked before Nextcloud's Viewer, and off when the user chose Nextcloud's handler) and an **Open with…** submenu.
- `/apps/nextcraft/edit/<app>?path=…` frames the app from the same origin and moves the file over WebDAV:
  - **WordCraft, GridCraft, PdfCraft and CADCraft** receive the document over their `?host=parent` postMessage protocol (`<app>:ready`, `:open`, `:save`, `:dirty`, `:failed`); the others get it by a drop onto the app.
  - **Every app** loads `js/nextcraft-shim.js`, which NextCraft adds to the app's `index.html` at install. It turns the app's downloads into NextCraft saves, answers its file dialogs with a Nextcloud/device choice, and hides the browser's File System Access pickers so the apps use those paths.
- `craft/.htaccess` lets Apache serve every file of a build (Nextcloud's root `.htaccess` would 404 unknown extensions). On other web servers the shim falls back to `/apps/nextcraft/asset/…` for those files.

## Security notes

The Craft apps run on the Nextcloud origin with the signed-in user's session, so they can reach that user's files like any Nextcloud app. NextCraft only installs official releases from the storytold GitHub organization, verified against their published checksums. Admins choose which apps and versions are installed. The app files themselves are public static assets (open-source code, no user data); the editor page and file access require login.

## Development

```sh
npm ci
npm run dev        # watch build
npm test           # unit tests (vitest)
```

## License and credits

NextCraft © 2026 GameDirection, licensed under AGPL-3.0-or-later (see `LICENSE`).

The Craft apps and the ArtCraft Launcher are © storytold and contributors (MIT / Apache-2.0). NextCraft downloads the apps from their official releases at install time and does not distribute them; the launcher submodule is GameDirection's fork with a small patch (see its `NEXTCRAFT.md`). NextCraft is not affiliated with or endorsed by storytold.
