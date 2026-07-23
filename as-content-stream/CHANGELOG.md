# Changelog

All notable changes to AS Content Stream are recorded here.

## 0.1.8 - 2026-07-23

- Renamed the visible core-site admin menu to Content Stream.
- Removed stale target-language wording from the simplified queue flow.

## 0.1.7 - 2026-07-23

- Simplified queue capture so main-site create, update, and delete events always create queue rows.
- Removed target language, WPML, destination site, and post type matching from queue capture.
- Replaced the single queue tab with Create Queue, Update Queue, and Delete Queue tabs.

## 0.1.6 - 2026-07-23

- Changed WPML active detection to use each site's own language configuration.
- Removed network-wide WPML plugin presence as a site active signal.
- Removed the WPML language filter fallback from site discovery to avoid main-site language leakage.

## 0.1.5 - 2026-07-23

- Added GitHub release updater support for WordPress-native plugin updates.
- Added Plugin URI and Update URI headers for GitHub distribution.

## 0.1.4 - 2026-07-23

- Added trash event capture so bulk move-to-trash actions are queued as delete actions.
- Confirmed bulk edits are handled through the post insert/update capture path.

## 0.1.3 - 2026-07-23

- Excluded deleted, archived, and spammed sites from discovery and queue targets.
- Switched content capture to `wp_after_insert_post` for more reliable page and post queueing.
- Added a Last Capture status panel to show why the latest source-site change queued or skipped.

## 0.1.2 - 2026-07-23

- Moved the core site control page to a top-level AS Content Stream admin menu.
- Limited content change queue capture to the core source site.

## 0.1.1 - 2026-07-23

- Moved the options page to the core site admin under Settings.
- Changed WPML language discovery to read per-site language data.
- Removed public, private, and other post type grouping.
- Added standards-aligned readme and changelog files.

## 0.1.0 - 2026-07-23

- Added initial network-enabled queue and discovery scaffold.
