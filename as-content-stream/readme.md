# AS Content Stream

Author: AlphaSys
Version: 0.1.24
Status: POC

## Purpose

AS Content Stream is a network-enabled WordPress plugin scaffold for multisite content streaming. Its control page lives as a top-level menu in the core site admin.

## Key Features

- Discovers sites where WPML is active.
- Lists each site's own WPML language codes.
- Records create, update, and delete content actions in a visible global queue.
- Captures individual and bulk edits/trash actions on the core source site.
- Splits queue visibility into Create Queue, Update Queue, and Delete Queue tabs.
- Keeps Settings and Sites & WPML tabs visible alongside the queue tabs.
- Shows post title, post name, and an edit link in queue rows.
- De-duplicates pending queue rows for the same source post/action/post type.
- Keeps new content in the Create Queue only while its create action is pending.
- Preserves the original source slug for delete processing.
- Stores the target language setting from available destination languages for future queue processing.
- Ensures active WPML destination sites have the no-login stream author and integration role.
- Runs the manual processing test against a single sampled destination job.
- Links source and destination sites from the Processing Queue and Log tabs.
- Creates/updates/trashes destination posts from processing jobs with stream identifiers and the integration author.
- Keeps failed processing jobs out of Log and available for retry in Processing Queue.
- Copies source post content and postmeta to destination posts with SQL.
- Adds a manual Run control to every item in the Processing Queue.
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
- Processing jobs copy source post content and postmeta with SQL; newly-created destination posts are forced to draft.
- Revision and autosave records are excluded from the queues.
- Future processors should clear queues in order: create, then update, then delete.
- Target language defaults to the most common language across destination WPML sites until manually saved.
- Processing cron can be toggled on and off from Settings.
- Processing Queue shows pending, in-progress, skipped, and failed per-site jobs with manual Run controls; Log shows completed processing jobs only.
- Log shows the latest 100 completed jobs and can be cleared from the Log tab.

## Future Considerations

- Expand the content streaming worker to handle taxonomies, media, custom fields, and deeper WPML relationships.
- Add duplicate queue coalescing if repeated edits create too many pending actions.
