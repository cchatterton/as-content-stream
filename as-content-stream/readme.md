# AS Content Stream

Author: AlphaSys
Version: 0.1.16
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
- Stores the target language setting for future queue processing.
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
- Content streaming execution and destination matching are not implemented yet; this build creates source-site queue records only.
- Revision and autosave records are excluded from the queues.
- Future processors should clear queues in order: create, then update, then delete.

## Future Considerations

- Add the content streaming worker.
- Add duplicate queue coalescing if repeated edits create too many pending actions.
