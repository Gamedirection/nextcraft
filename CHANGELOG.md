# Changelog

## 0.2.0 – unreleased

- MaterialCraft (`.mcraft`, `.mcmat`).
- Every app opens Nextcloud files from its own Open/Import dialog and saves to Nextcloud or the device (File System Access pickers are routed through NextCraft too).
- ArtCraft Launcher (GameDirection build) as an optional submodule with `deploy/install-launcher.sh`; it sits at the end of the app menu, and editor pages show Files as the active app.
- "ArtCraft Launcher" in the app menu: the launcher opens each installed Craft app in NextCraft.
- `occ nextcraft:install <app> --zip <file> --build-version <v>` installs a local web build; local builds are left alone by updates.

## 0.1.0 – unreleased

- First version: Open with… for Craft apps, per-user defaults, save to Nextcloud or device, app installer/updater.
