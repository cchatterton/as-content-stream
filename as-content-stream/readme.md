# AS Content Stream

Author: AlphaSys
Version: 0.1.5
Status: POC

## Purpose

AS Content Stream is a network-enabled WordPress plugin scaffold for multisite content streaming. Its control page lives as a top-level menu in the core site admin.

## Key Features

- Discovers sites where WPML is active.
- Lists each site's own WPML language codes.
- Lists each site's registered content post types without grouping.
- Stores a target language as a network option.
- Records create, update, and delete content actions in a visible global queue.
- Captures individual and bulk edits/trash actions on the core source site.
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
- The options page appears only in the core site admin as AS Content Stream.
- The core site is the monitored source site for content changes.
- Deleted, archived, and spammed multisite sites are excluded from discovery and queue targets.
- GitHub releases must include `as-content-stream.zip` as a release asset.
- Content streaming execution is not implemented yet; this build creates queue records only.

## Future Considerations

- Add the content streaming worker.
- Add duplicate queue coalescing if repeated edits create too many pending actions.
