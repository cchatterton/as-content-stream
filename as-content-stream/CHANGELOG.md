# Changelog

All notable changes to AS Content Stream are recorded here.

## 0.1.26 - 2026-08-01

- Added processing queue job IDs, blocked-by IDs, and priority ordering.
- Added blocked processing jobs and automatic unblocking after dependency jobs complete.
- Added priority blocker job creation for related source posts referenced in postmeta.
- Rewrites copied related-post meta values to destination post IDs once links exist.
- Copies featured image files, attachment rows, attachment meta, and destination thumbnail references.

## 0.1.25 - 2026-08-01

- Added a dedicated source/destination links table.
- Added a Links tab with post ID lookup across source and destination IDs.
- Added per-link Run controls to manually push a single mapped relationship.
- Moved canonical destination lookup and relationship tracking away from postmeta and into the links table.
- Added link IDs to processing jobs where known.

## 0.1.24 - 2026-08-01

- Changed Processing Queue controls to show a manual Run button for every non-complete job.
- Switched destination post create/update handling to direct SQL row copying from the source post.
- Copied source postmeta rows directly to the destination postmeta table.
- Forced newly-created destination posts to draft while keeping the integration author.

## 0.1.23 - 2026-08-01

- Kept failed and skipped jobs in Processing Queue instead of Log.
- Added a Re-run control for failed and skipped processing jobs.
- Limited destination streaming to title, dates, operational identifiers, and the integration author.
- Hydrated older processing jobs from the source post when missing stream UUID payload data.
- Kept source queue parents in progress until every child processing job completes successfully.

## 0.1.22 - 2026-08-01

- Changed the test button to use the main processing flow with a one-job processing limit.
- Exploded the full destination batch before processing one test job so the parent remains in progress while child jobs are pending.
- Added lightweight destination create, update, and trash handling with source/destination stream identifiers.
- Added exact destination edit links when a destination post exists.

## 0.1.21 - 2026-08-01

- Changed the manual processing test tick to create and process only one sampled destination job.
- Kept manual test ticks from consuming the source queue item.
- Added new-tab source and destination links to Processing Queue and Log rows.

## 0.1.20 - 2026-08-01

- Added a Clear Log button for terminal processing jobs.
- Clarified that the Log tab displays the latest 100 completed processing jobs.
- Added a Settings-page scan that ensures the stream author user and integration role on active WPML destination sites.

## 0.1.19 - 2026-08-01

- Added a Processing Queue tab for per-destination jobs.
- Added a Log tab for completed processing jobs.
- Added processing on/off control, heartbeat telemetry, and a one-shot test tick while processing is off.
- Added cron scaffolding that explodes source queue items into per-site processing jobs and runs a no-op processor.

## 0.1.18 - 2026-08-01

- Fixed target language select overflow in the Settings tile.
- Removed the Last Capture tile from Settings.

## 0.1.17 - 2026-08-01

- Removed the Source Site tile from Settings.
- Changed target language to a picklist built from destination WPML site languages.
- Defaulted target language to the most common available destination language until overridden and saved.

## 0.1.16 - 2026-07-23

- Excluded the core source site from the Sites & WPML destination list.
- Restored the target language setting on the Settings tab.
- Kept target language storage separate from queue capture guardrails.

## 0.1.15 - 2026-07-23

- Captured trash actions before WordPress mutates slugs with trash suffixes.
- Added original post name to queue payloads and queue tables.
- Documented create, update, delete queue processing order.

## 0.1.14 - 2026-07-23

- Prevented newly-created content from also appearing in the Update Queue while its create action is pending.
- Delete actions now remove stale pending create/update rows for the same source post.

## 0.1.13 - 2026-07-23

- Fixed WPML language display when active language settings are stored as numeric arrays.
- Changed WPML status to reflect live plugin activation rather than historical WPML settings.
- Removed the post type list from the Sites & WPML tab.

## 0.1.12 - 2026-07-23

- Added pending queue de-duplication for repeated save hooks on the same source post/action/post type.
- Existing pending queue rows now refresh their timestamp and payload instead of inserting duplicates.

## 0.1.11 - 2026-07-23

- Tightened WPML active detection to require completed site-level WPML configuration.
- Stopped treating existing WPML language tables alone as an active WPML site.

## 0.1.10 - 2026-07-23

- Excluded revision and autosave records from queue capture.
- Added post title, post name, and source edit links to queue tables.

## 0.1.9 - 2026-07-23

- Restored Settings and Sites & WPML tabs alongside the three queue tabs.
- Added source-site and network status details to the Settings tab.

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
