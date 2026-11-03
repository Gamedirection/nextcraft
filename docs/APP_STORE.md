# Publishing NextCraft to the Nextcloud App Store

Goal: list NextCraft on [apps.nextcloud.com](https://apps.nextcloud.com), so admins can install it from **Apps** inside Nextcloud. There is no manual review; an app is listed once it's registered and its releases are signed.

## Status

| Step | Status |
|---|---|
| Move Craft builds out of the app folder (integrity check) | **To do: blocker** |
| Neutral launcher label in the app menu | To decide |
| Release packaging (tarball with built `js/`) | To do |
| Signing certificate | To do (needs GameDirection's GitHub account) |
| Register app on apps.nextcloud.com | To do |
| First signed release (v0.2.0) | To do |

## 1. Blocker: code integrity

Store apps are signed. Nextcloud's integrity check flags any file in a signed app's folder that isn't in its signature. Today NextCraft unpacks the Craft apps into `<apps>/nextcraft/craft/<app>/<version>/`, so every admin would see "Some files have not passed the integrity check".

Fix: keep the Craft builds outside the app folder and serve them from there.

- Store unpacked builds in app data (`data/appdata_<instanceid>/nextcraft/craft/…`, already used for the cached zips) instead of `craft/` in the app folder.
- Serve them through a NextCraft route (`/apps/nextcraft/craft/<app>/<version>/<path>`), extending today's `AssetController`: correct MIME types (`.wasm` → `application/wasm`), `ETag`/`Last-Modified`, long `Cache-Control` for content-hashed files, gzip where the server allows.
- Keep it same-origin, so the editor/shim design is unchanged. Only the URLs `InstallService::webBase()` returns change.
- Remove `craft/.htaccess` and the shim's 404 fallback (no longer needed).
- `RestoreApps` repair step: no longer needed for upgrades (app data survives them); keep it for a missing-folder repair.
- Measure first-load time of the large builds (LightCraft ≈ 70 MB, PhotoCraft ≈ 50 MB) through PHP versus static serving. Browsers cache them afterwards.

## 2. Branding

The app-menu entry is labelled **ArtCraft Launcher**, and the launcher shows storytold's logo. storytold's repo states its brand assets (`docs/brand/`) are trademarks, not MIT/Apache. For the public listing:

- label the menu entry neutrally (e.g. "Craft Launcher"), or ask storytold for permission;
- the launcher stays an optional, separately built add-on (`launcher/` submodule), not part of the store package.

## 3. Signing certificate (one time)

```sh
openssl req -nodes -newkey rsa:4096 -keyout nextcraft.key -out nextcraft.csr -subj "/CN=nextcraft"
```

- Open a PR adding `nextcraft.csr` to https://github.com/nextcloud/app-certificate-requests. Nextcloud replies with `nextcraft.crt`, usually within a few days.
- Keep `nextcraft.key` private: never commit it; store it in `pass` or a password manager.

## 4. Register the app (one time)

On https://apps.nextcloud.com (sign in with GitHub as GameDirection) ▸ **Register app**:

- Certificate: contents of `nextcraft.crt`.
- Signature: `echo -n "nextcraft" | openssl dgst -sha512 -sign nextcraft.key | openssl base64`

## 5. Each release

1. Bump `<version>` in `appinfo/info.xml` and update `CHANGELOG.md`.
2. `npm ci && npm run build`.
3. Build the tarball: top-level folder `nextcraft/`, containing `appinfo lib templates css img js LICENSE README.md CHANGELOG.md`. No `src/`, `node_modules/`, source maps, `launcher/` or `deploy/`.
4. Sign the code inside the app (creates `appinfo/signature.json`):
   `occ integrity:sign-app --path=<unpacked nextcraft/> --privateKey=nextcraft.key --certificate=nextcraft.crt`
   Then repack the tarball.
5. Sign the tarball: `openssl dgst -sha512 -sign nextcraft.key nextcraft-<v>.tar.gz | openssl base64`
6. Attach the tarball to a GitHub release `v<version>` on Gamedirection/nextcraft.
7. On apps.nextcloud.com ▸ **Upload release**: the tarball's download URL plus the signature from step 5.

These steps can be scripted (`deploy/release.sh`) and run from GitHub Actions with the key as a repository secret.

## 6. Listing quality

- `info.xml`: add `<screenshot>` entries (Open with… chooser, the editor with WordCraft/VectorCraft, the save dialog, admin settings), `<website>` and `<documentation>` links.
- Description: keep the plain statement that the Craft apps are downloaded from storytold's GitHub releases at install time and checksum-verified. Store apps may download external code (Nextcloud Office's built-in server and Recognize do), but must say so.
- Supported versions: `min-version="34" max-version="35"`. Test each new Nextcloud major before raising it.

## References

- Developer manual, App Store publishing: https://nextcloudappstore.readthedocs.io/en/latest/developer.html
- Code signing: https://docs.nextcloud.com/server/latest/developer_manual/app_publishing_maintenance/code_signing.html
- Certificate requests: https://github.com/nextcloud/app-certificate-requests
