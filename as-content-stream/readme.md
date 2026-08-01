# Content Stream

Author: AlphaSys
Version: 0.1.43
Status: POC

## Purpose

Content Stream is a network-enabled WordPress plugin scaffold for multisite content streaming. Its control page lives as a top-level menu in the core site admin.

## Key Features

- Discovers sites where WPML is active.
- Lists each site's own WPML language codes.
- Records create, update, and delete content actions in a visible global queue.
- Captures individual and bulk edits/trash actions on the core source site.
- Splits queue visibility into Create Queue, Update Queue, and Delete Queue tabs.
- Shows a temporary Discovery Queue tab with compact stats when published source content is not fully mapped.
- Limits Discovery to public post types currently registered on the core site.
- Provides a manual Re-run Discovery button to clear and rebuild Discovery queue rows.
- Runs Discovery only on plugin activation or a manual Re-run Discovery click.
- Shows all-tab queue summary counts in Network Status.
- Shows live queue status and processing blockage in Heartbeat.
- Keeps Settings and Sites & WPML tabs visible alongside the queue tabs.
- Shows post title, post name, and an edit link in queue rows.
- De-duplicates pending queue rows for the same source post/action/post type.
- Keeps new content in the Create Queue only while its create action is pending.
- Preserves the original source slug for delete processing.
- Stores the target language setting from available destination languages for future queue processing.
- Ensures active WPML destination sites have the no-login stream author and integration role.
- Adds manual Run controls to Create, Update, and Delete Queue rows to move items into Processing Queue.
- Queue-style tables expose Job IDs and use Post Title as the source edit link.
- Discovery uses legacy WFC Push Post metadata first, then slug/language matching, then blocks behind a normal create job when destination content is missing.
- Links source and destination sites from the Processing Queue and Log tabs.
- Creates/updates/trashes destination posts from processing jobs with stream identifiers and the integration author.
- Keeps failed processing jobs out of Log and available for retry in Processing Queue.
- Copies source post content and postmeta to destination posts with SQL.
- Adds manual Run and Delete controls to every item in the Processing Queue.
- Tracks source/destination relationships in a dedicated links table with an interactive Streamed Content tab.
- Copies featured images into destination uploads and indexes them as destination attachments.
- Blocks jobs on missing related-post meta dependencies and creates priority blocker jobs.
- Supports WordPress-native updates from GitHub releases.

## Folder Structure

```text
as-content-stream/
├── as-content-stream.php
├── readme.md
├── CHANGELOG.md
├── assets/
│   └── admin.css
└── includes/
    ├── class-as-content-stream.php
    └── class-as-content-stream-github-updater.php
```

## Important Notes

- The plugin is intended to be network activated.
- The options page appears only in the core site admin as Content Stream.
- The core site is the monitored source site for content changes.
- The Sites & WPML tab lists destination sites only and excludes the core source site.
- Deleted, archived, and spammed multisite sites are excluded from discovery and queue targets.
- WPML active status reflects live plugin activation for that site, not historical WPML settings.
- GitHub releases must include `as-content-stream.zip` as a release asset.
- Processing jobs copy source post content and postmeta with SQL; destination posts are forced to draft.
- Streamed creates and updates always force destination posts to draft.
- Source/destination relationships are stored in `wp_as_content_stream_links`, not postmeta.
- Processing Queue exposes job IDs, blocked-by IDs, post type, and manual Run controls for dependency handling.
- Revision, autosave, media attachment, and WordPress structural post types are excluded from the queues.
- Future processors should clear queues in order: create, then update, then delete.
- Target language defaults to the most common language across destination WPML sites until manually saved.
- Automatic cron processing is toggled from the Heartbeat tile.
- Processing Queue shows pending, in-progress, skipped, and failed per-site jobs with manual Run controls; Log shows completed processing jobs only.
- Log shows the latest 100 completed jobs and can be cleared from the Log tab.

## Future Considerations

- Expand the content streaming worker to handle taxonomies, media, custom fields, and deeper WPML relationships.
- Add duplicate queue coalescing if repeated edits create too many pending actions.
