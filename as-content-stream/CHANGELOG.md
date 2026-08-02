# Changelog

All notable changes to Content Stream are recorded here.

## 0.1.73 - 2026-08-02

- Adds a CPT Settings tab for per-post-type stream status policy.
- Defaults stream status to Draft for all post types except impact, which defaults to Publish.
- Enforces CPT stream status during create, update, discovery mapping, and site Clean actions.
- Adds Wrong Status to Sites & WPML health snapshots.

## 0.1.72 - 2026-08-02

- Keeps Run Discovery on the Settings tab after the discovery rebuild completes.

## 0.1.71 - 2026-08-02

- Aligns Sites & WPML health snapshots with Streaming Map logic by starting from active map rows for published source content.
- Adds Expected, Mapped Missing, and Diff columns to Sites & WPML.
- Uses Mapped Missing for active map rows whose destination post is missing, trashed, wrong type, or not in the streaming language.
- Aligns the per-site Clean action with the same active map scope used by site health.

## 0.1.70 - 2026-08-02

- Adds cached destination-site health counts for mapped published, mapped draft, and not-mapped target-language posts.
- Refreshes destination health snapshots during Discovery and refreshes only the cleaned site after Clean.
- Adds per-site Clean controls that draft mapped destination posts and move not-mapped target-language posts to Trash.
- Adds a blocking animated overlay for Run Discovery and Clean actions.

## 0.1.69 - 2026-08-02

- Skips already mapped destination sites when a Discovery parent job is exploded into processing jobs.
- Renames the Network Status Discovery button to Run Discovery.

## 0.1.68 - 2026-08-02

- Adds a Network Status Difference row for Streaming Map reconciliation.
- Calculates Difference as published content times active WPML sites minus Streaming Map plus Discovery coverage.

## 0.1.67 - 2026-08-02

- Makes Processing Queue a live AJAX view so jobs appear, update, and leave while the tab is open.
- Makes Discovery, Create, Update, and Delete queues live AJAX views while preserving background lazy loading.

## 0.1.66 - 2026-08-02

- Removes the Discovery post-type summary table and Discovery-tab Re-run button.
- Removes source queue status summaries and bulk Clear Pending controls from queue tabs.
- Adds per-row Delete controls to Discovery, Create, Update, and Delete queues.
- Changes Log to open empty and live-watch newly completed jobs, while post ID lookup remains historical.

## 0.1.65 - 2026-08-02

- Removes visible batch-loading text from lazy-loaded table tabs.
- Adds an animated loading indicator below lazy tables while additional rows are loading.
- Freezes lazy-loaded table results to the page-load snapshot so new rows wait for a full refresh.

## 0.1.64 - 2026-08-02

- Loads heavy table tabs in 50-row batches so the first screen renders quickly.
- Adds AJAX row loading for queue, processing, log, and Streaming Map tables.
- Keeps initial and lazy-loaded table rows rendered from the same server-side row templates.

## 0.1.63 - 2026-08-02

- Stops writing featured-image attachment relationships into the Streaming Map.
- Reconciles existing attachment and stale active rows out of the active Streaming Map.
- Counts and lists Streaming Map rows only for current published source content, current target language, and active WPML destinations.

## 0.1.62 - 2026-08-02

- Splits the Heartbeat queue bars into a lightweight half-second AJAX pulse so those two bars update together.
- Refreshes queue pulse counts when the Heartbeat timer animation resets.
- Moves heavier Settings dashboard status refreshes to a slower background cadence.

## 0.1.61 - 2026-08-02

- Adds a Published Content count as the first item in the Network Status tile.

## 0.1.60 - 2026-08-02

- Changes the Heartbeat next-check bar to a pure CSS animation based on the saved heartbeat interval.

## 0.1.59 - 2026-08-02

- Smooths Settings dashboard updates with half-second polling and an in-flight request guard.
- Animates the Heartbeat progress bar locally between AJAX refreshes.

## 0.1.58 - 2026-08-02

- Makes the Heartbeat progress bar fill from left to right.

## 0.1.57 - 2026-08-02

- Renames the first Settings tile to Options.
- Adds a configurable heartbeat interval in seconds, defaulting to 60.
- Schedules automatic processing using the configured heartbeat interval.
- Replaces the numeric heartbeat countdown with a green progress bar paced by the configured interval.

## 0.1.56 - 2026-08-02

- Rolls back adding the post title search query to Processing Queue post type links.

## 0.1.55 - 2026-08-02

- Adds the processing row post title as the `s` search query on Processing Queue post type links.

## 0.1.54 - 2026-08-02

- Reconciles active Streaming Map rows when a destination site no longer has WPML active by marking those rows inactive.
- Shows only active rows in Streaming Map so the map reflects currently managed sync relationships.
- Lets Discovery reactivate an inactive map row when WPML is re-enabled and the mapped destination post still exists.
- Refreshes Discovery once when the active WPML destination target set changes.
- Counts only active Streaming Map rows in Settings dashboard totals.
- Adds post ID lookup to Log, matching retained completed jobs by source post ID or mapped destination post ID.

## 0.1.53 - 2026-08-02

- Fixed post type links in Processing Queue, Log, and Streaming Map to open the destination site's list view using the row's streaming language.

## 0.1.52 - 2026-08-02

- Collapses Streaming Map rows by current source post, destination site, post type, and language instead of letting separate actions appear as duplicate map rows.
- Cleans up duplicate Streaming Map rows on admin load and after future map writes.
- Splits destination site and destination post ID into separate columns on Processing Queue, Log, and Streaming Map.
- Links post type values to the matching post-type list view using the row's streaming language.
- De-duplicates legacy destination match IDs before deciding a Discovery job has multiple legacy matches.
- Removes Original Post Name from queue tables.

## 0.1.51 - 2026-08-02

- Forces existing destination posts to draft when Discovery maps by legacy metadata or slug.
- Forces existing destination posts to draft when create processing finds and maps an existing destination.
- Forces non-media Streaming Map writes to draft the destination post so Discovery and manual map paths share the rule.
- Keeps SQL create/update streaming behavior forcing destination posts to draft.

## 0.1.50 - 2026-08-02

- Removed success notices from manual Run actions so they quietly reload the target tab.
- Kept explicit notices for settings saves, queue clears, Discovery rebuilds, and log clears.

## 0.1.49 - 2026-08-02

- Changed the Heartbeat processing gauge to show Queued / Blocked / Failed counts.
- Counts blocked and failed processing jobs together for the red pressure bar so failed blockers are visible from Settings.

## 0.1.48 - 2026-08-01

- Removed skipped as a current processing outcome.
- Changed create processing so an existing live destination is mapped and completed instead of skipped.
- Changed create processing so trashed destination matches are treated as unusable and a new destination can be created.
- Re-queues legacy skipped processing rows on admin load so they can resolve under the current rules.

## 0.1.47 - 2026-08-01

- Renamed the visible Streamed Content tab and Settings summary row to Streaming Map.
- Removed the latest-100 cap from the Streaming Map tab so all mapping rows are shown.
- Changed already-mapped create processing jobs to complete successfully and unblock dependent Discovery jobs.

## 0.1.46 - 2026-08-01

- Refreshed every Settings dashboard tile from the heartbeat AJAX call once per second.
- Added live updates for Network Status site counts, queue totals, Processing Queue totals, Streamed Content, Log, and Heartbeat gauges.
- Kept the Target Language picklist in sync with current destination language counts without requiring a page reload.
- Added a subtle value-change pulse so live dashboard updates are easier to spot.
- Matched Settings tile action button widths.

## 0.1.45 - 2026-08-01

- Matched the Target Language action button width with the other Settings tile actions.
- Changed automatic processing so each cron tick adds at most one parent queue batch, then drains pending unblocked Processing Queue jobs.
- Added AJAX updates to Network Status counts and refreshed Settings metrics every two seconds.

## 0.1.44 - 2026-08-01

- Rebuilt the Target Language tile with explicit body/action layout so Save Settings anchors to the bottom.

## 0.1.43 - 2026-08-01

- Reworked the Settings tab panels into a more compact, polished admin layout.
- Changed Heartbeat metrics into paired gauge blocks with labels attached to their bars.
- Simplified Network Status values so each tab row has a single right-aligned count.
- Reduced button visual weight while keeping actions anchored at the bottom of each tile.

## 0.1.42 - 2026-08-01

- Split Network Status into one row per queue/tab metric.
- Anchored the Target Language save action to the bottom of its tile.
- Tightened Heartbeat metric labels so they sit with their progress bars.

## 0.1.41 - 2026-08-01

- Replaced Discovery post-type stat tiles with a compact table.
- Tightened Settings tile spacing while keeping tile actions anchored bottom-left.
- Changed Network Status and Heartbeat metrics to compact borderless tables with right-aligned values.
- Simplified Heartbeat labels to the existing queue states.

## 0.1.40 - 2026-08-01

- Replaced the Heartbeat batch fraction with live queue and processing pressure metrics.
- Added all-tab summary counts to the Network Status tile.
- Removed the target language explanatory line from Settings.
- Aligned tile action buttons to the bottom-left of their panels.
- Added Site ID as the first column on Sites & WPML.

## 0.1.39 - 2026-08-01

- Changed Discovery to map only and no longer create destination content directly.
- When Discovery cannot find a destination, it creates a normal blocking create job and waits for it to complete.
- Keeps related-content dependency lookup and blocking inside the normal create/update processing path.

## 0.1.38 - 2026-08-01

- Seeded Discovery once during plugin activation from the core site.
- Confirmed Discovery rebuilds only run on activation or manual Re-run Discovery clicks.

## 0.1.37 - 2026-08-01

- Removed automatic Discovery rebuilds from normal Content Stream admin page loads to keep tab clicks fast.
- Added Re-run Discovery to Settings so Discovery can be rebuilt manually even when the Discovery tab is hidden.

## 0.1.36 - 2026-08-01

- Limited Discovery to public post types currently registered on the core site.
- Cleaned stale Discovery queue rows for non-public or inactive post types when the admin page loads.
- Added a manual Re-run Discovery button to clear and rebuild Discovery queue rows.
- Increased the Discovery queue view and sorted it by post type/source ID so tile counts are easier to reconcile with rows.

## 0.1.35 - 2026-08-01

- Added a temporary Discovery Queue tab for published source content missing full Streamed Content coverage.
- Added discovery tiles by post type showing published, mapped, and unmapped counts.
- Added `discover` queue and processing actions ahead of create, update, and delete.
- Uses legacy WFC Push Post metadata first, then slug and target-language matching, before creating destination drafts.
- Keeps Discovery processing in the same manual/cron flow as the other queues.

## 0.1.34 - 2026-08-01

- Added Job IDs to Create, Update, and Delete Queue tables.
- Standardized source content links so Post Title is the edit link and Source is a plain post ID.
- Renamed Source Title columns to Post Title across processing-facing tables.
- Put date columns second wherever a table has a date, including Streamed Content.
- Changed Streamed Content to use the last Processing Queue job ID as the first column.

## 0.1.33 - 2026-08-01

- Renamed the visible Links tab to Streamed Content.
- Added Source Title to Processing Queue, Log, and Streamed Content tables.
- Reworked Processing Queue, Log, and Streamed Content column order for stronger continuity.

## 0.1.32 - 2026-08-01

- Renamed visible admin references to Content Stream.
- Forced destination post status to draft on both streamed creates and updates.
- Added Post Type to Log and Links tables.
- Moved Links Run controls to the rightmost column and aligned related table column order.
- Removed the visible border around the Processing Queue trash icon.

## 0.1.31 - 2026-08-01

- Added a trash icon control to delete individual Processing Queue jobs.
- Unblocked jobs that were waiting on a removed Processing Queue job.

## 0.1.30 - 2026-08-01

- Excluded WordPress structural post types, including `wp_navigation`, from source capture and dependency blockers.
- Ignored non-streamable processing jobs as no-op complete so older accidental blockers can unblock downstream jobs.
- Limited meta relationship dependency detection and ID rewriting to streamable source post types.

## 0.1.29 - 2026-08-01

- Added a Post Type column to the Processing Queue.
- Simplified Processing Queue source links to show only the source post ID.
- Disabled Processing Queue Run controls for blocked jobs.

## 0.1.28 - 2026-08-01

- Removed the redundant Run One Manual Step control from the Heartbeat tile.
- Removed the Heartbeat mode label and last-batch timing display.
- Removed the Stream Author tile from Settings while keeping the readiness scan silent.
- Forced Content Stream admin actions and redirects back to the core site admin after manual processing.

## 0.1.27 - 2026-08-01

- Added Run controls to Create, Update, and Delete Queue rows to explode a source queue item into Processing Queue.
- Removed the standalone Processing settings tile.
- Moved automatic/manual mode controls into the Heartbeat tile.
- Simplified Heartbeat display by removing current phase and last-message progress copy.
- Renamed batch progress to show items in Processing Queue.

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

- Moved the core site control page to a top-level Content Stream admin menu.
- Limited content change queue capture to the core source site.

## 0.1.1 - 2026-07-23

- Moved the options page to the core site admin under Settings.
- Changed WPML language discovery to read per-site language data.
- Removed public, private, and other post type grouping.
- Added standards-aligned readme and changelog files.

## 0.1.0 - 2026-07-23

- Added initial network-enabled queue and discovery scaffold.
