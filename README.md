# local_yucardphoto — YU Card Photo Roster

**Package:** `local_yucardphoto`  
**Version:** 1.0.1 (Build: 2026040601)  
**Moodle:** 5.1+  
**Author:** ED&IT, York University  
**Licence:** GNU GPL v3 or later

---

## Overview

The **YU Card Photo Roster** plugin imports student ID-card photos from York University's external YU Card system into Moodle and surfaces them as a responsive, searchable photo grid on the course Participants page.

Photos are stored in the **Moodle file system** (not as database BLOBs). The database holds only the pluginfile URL and file-area path reference for each student.

---

## Features

| Feature | Detail |
|---|---|
| Nightly photo import | Scheduled task at midnight reads metadata first (`SISID` + `PHOTOMODIFIEDDATE`), computes the delta, then fetches one photo BLOB per SISID only when needed. Changed rows are processed in batches (default 500) in a single task run, then written to Moodle file storage and upserted in DB. |
| Per-course opt-in | A **Participant Photograph** section in Course Settings lets instructors and admins enable or disable the Photo View button per course. Default is **No (disabled)** for all existing and new courses. |
| Photo View button | When enabled for a course, a **Photo View** button appears in the Participants page toolbar for authorised roles. |
| Global Photo View kill switch | Site admins can globally disable Photo View regardless of course-level setting. When enabled, the button is hidden and direct access to `/local/yucardphoto/participants.php` is blocked. |
| Photo roster page | A responsive Bootstrap 5 grid (`/local/yucardphoto/participants.php`) showing each student's photo, name, SIS ID, and email. Supports search (first name, last name, SIS ID), sort (last name, first name, email, SIS ID), and pagination (20 or 100 per page). |
| Manual photo upload | Admins and managers can search for a Moodle user and upload a replacement JPEG/PNG photo via `/local/yucardphoto/upload.php`. The page shows the student's current stored photo so the admin can confirm what they are overriding. |
| Templated UI | Both the roster page and upload page are fully Mustache-templated (`templates/participants.mustache`, `templates/upload.mustache`). Theme designers can override them by placing a copy under `theme/<themename>/templates/local_yucardphoto/`. |
| Role-based access | Only **managers, course creators, editing teachers, and non-editing teachers** can see the Photo View button and roster. Students never see the roster. |
| Privacy / GDPR | A full privacy provider handles data export and deletion, keyed on `mdl_user.idnumber` (the York SIS ID). |

---

## File Structure

```
local/yucardphoto/
├── version.php                          Plugin version and component declaration
├── lib.php                              pluginfile handler, store_photo(), helpers
├── settings.php                         Admin settings (external DB config, upload link)
├── participants.php                     Photo roster page controller
├── upload.php                           Manual photo upload page controller
│
├── db/
│   ├── install.xml                      Database schema (2 tables)
│   ├── access.php                       Capability definitions
│   ├── tasks.php                        Nightly scheduled task registration
│   └── hooks.php                        Hook callback registrations
│
├── lang/en/
│   └── local_yucardphoto.php            All English language strings
│
├── templates/
│   ├── participants.mustache            Photo roster page template
│   └── upload.mustache                  Manual upload page template
│
├── amd/
│   ├── src/
│   │   ├── photoview_button.js          Injects Photo View button into participants toolbar
│   │   └── upload.js                    User-search autocomplete + current photo preview
│   └── build/
│       ├── photoview_button.min.js
│       └── upload.min.js
│
└── classes/
    ├── task/
    │   └── import_yucard_photos.php     Nightly import scheduled task
    ├── hook/
    │   ├── output/
    │   │   └── before_http_headers.php  Injects button AMD module on participants page
    │   └── course/
    │       ├── after_form_definition.php   Adds Participant Photograph section to course form
    │       └── after_form_submission.php   Saves enabled flag per course
    └── privacy/
        └── provider.php                 GDPR privacy provider
```

---

## Database Schema

### `mdl_local_yucardphoto`

Stores one record per student. Photos are **not** stored here — only the reference to the Moodle file system.

| Column | Type | Description |
|---|---|---|
| `id` | INT(10) | Auto-increment primary key |
| `sisid` | VARCHAR(50) | Student/SIS ID (unique index). At York this matches `mdl_user.idnumber`. |
| `firstname` | VARCHAR(100) | Student first name (from YU Card source) |
| `lastname` | VARCHAR(100) | Student last name (from YU Card source) |
| `moodle_file_url` | TEXT | Moodle pluginfile URL to the stored photo |
| `yucard_image_path` | TEXT | Internal file-area path (`/{sisid}.jpg`) |
| `yucard_lastupdated` | INT(10) | Unix timestamp of last photo update in the source system. Used to skip unchanged photos on nightly re-runs. |
| `timecreated` | INT(10) | Unix timestamp — record first created in Moodle |
| `timemodified` | INT(10) | Unix timestamp — record last modified in Moodle |

### `mdl_local_yucardphoto_coursesettings`

One row per course that has ever had the setting saved.

| Column | Type | Description |
|---|---|---|
| `id` | INT(10) | Auto-increment primary key |
| `courseid` | INT(10) | `mdl_course.id` (unique index) |
| `enabled` | INT(1) | `1` = Photo View button shown, `0` = hidden. Default `0`. |

---

## Capabilities

| Capability | Context | Default roles | Description |
|---|---|---|---|
| `local/yucardphoto:viewroster` | Course | manager, coursecreator, editingteacher, teacher | View the Photo View button and the roster page |
| `local/yucardphoto:uploadphoto` | System | manager | Manually upload / override student photos via upload.php |

Site administrators always have both capabilities.

---

## Moodle File Storage

Photos are stored in the **system context** file area:

```
component : local_yucardphoto
filearea  : photos
itemid    : abs(crc32(sisid))   — stable integer derived from the SIS ID
filepath  : /
filename  : {sisid}.jpg  (or .png)
```

The pluginfile URL served to the browser is:

```
/pluginfile.php/{syscontextid}/local_yucardphoto/photos/{itemid}/{sisid}.jpg
```

Access requires `local/yucardphoto:viewroster` (site admins always pass). The pluginfile handler allows users with this capability in system context or in at least one course context.

---

## Installation

### 1. Place the plugin

Copy the `yucardphoto` folder into `<moodle_root>/local/`.

### 2. Run the Moodle upgrade (inside Docker)

```bash
php admin/cli/upgrade.php --non-interactive
```

### 3. Purge caches

```bash
php admin/cli/purge_caches.php
```

### 4. Configure the external database

Go to **Site administration → Local plugins → YU Card Photo Roster** and fill in:

- External DB type (Oracle/OCI8)
- Oracle TNS connect string
- Oracle schema, username, and password
- Source table/view name
- Column names for SISID, photo BLOB, and last-updated timestamp
- Optional global roster switch: **Disable Photo View globally**

### 5. (Optional) Run the import task manually to test

```bash
php admin/cli/scheduled_task.php --execute='\local_yucardphoto\task\import_yucard_photos'
```

### 6. Enable Photo View for a course

Go to the course → **Settings** → scroll to **Participant Photograph** → set to **Yes — show Photo View button** → Save.

---

## Usage

### Photo View roster (instructors / managers)

1. Go to **Participants** in any course where Photo View is enabled.
2. Click the **Photo View** button in the toolbar.
3. Use the search box to filter by first name, last name, or SIS ID.
4. Use the **Sort by** dropdown to reorder the grid.
5. Use **Photos per page** to switch between 20 (4 × 5 grid) and 100.
6. Click **Back to Participants** to return to the standard list.

### Manual photo upload (admins / managers)

1. Go to **Site administration → Local plugins → YU Card Photo Roster** and click **Upload Student Photo**, or navigate directly to `/local/yucardphoto/upload.php`.
2. Type a student's name, username, or SIS ID in the search box.
3. Select the student from the autocomplete list. If a photo already exists it will be shown as a preview.
4. Choose a JPEG or PNG file (max 2 MB).
5. Click **Save photo**. The photo is immediately available on the roster page.

---

## Nightly Import Task

**Schedule:** Daily at 00:00 (midnight server time).  
**Class:** `\local_yucardphoto\task\import_yucard_photos`

The task:
1. Connects to the configured Oracle database.
2. Reads metadata only (`SISID`, `PHOTOMODIFIEDDATE`) from source.
3. Compares metadata to local records and builds a `needsupdate` set.
4. Processes `needsupdate` in chunks using `yucard_batch_size` (default `500`).
5. For each SISID in each chunk:
   - Skip if no Moodle user with matching `user.idnumber`.
   - Fetch one BLOB using a targeted query (`WHERE SISID = :sisid`).
   - Detect MIME, store file in Moodle file storage, insert/update local DB record.
6. Logs per-batch timing and a final summary, including total duration.

See `docs/PHOTOIMPORT_ARCHITECTURE` for the detailed import flow and rationale.

The task can be run manually via the Moodle scheduled tasks admin page or the CLI command above.

---

## Theming / Overrides

Both page templates can be overridden by the active theme:

| Template | Override path |
|---|---|
| `local_yucardphoto/participants` | `theme/<theme>/templates/local_yucardphoto/participants.mustache` |
| `local_yucardphoto/upload` | `theme/<theme>/templates/local_yucardphoto/upload.mustache` |

The AMD modules can be overridden via Moodle's standard AMD override mechanism.

---

## Privacy / GDPR

This plugin stores personally identifiable information (student name, SIS ID, photo). The privacy provider (`classes/privacy/provider.php`) implements:

- **Metadata declaration** — describes all stored fields and the external data source.
- **Context list** — returns the system context if a photo record exists for the user (matched via `mdl_user.idnumber`).
- **User list** — lists all users with stored photos in the system context.
- **Data export** — exports the record fields and the stored photo file.
- **Data deletion** — deletes the record and the associated file from the Moodle file system.

---

## Changelog

### 1.0.1 — 2026-04-06

- Updated release metadata to `1.0.1 (Build: 2026040601)`.
- Import task now processes changed rows in configurable batches (`yucard_batch_size`, default 500) in one run.
- Added per-batch timing/progress logs and total run duration summary.
- Import strategy clarified and enforced: metadata-first delta + single-BLOB fetch per SISID.
- Added global admin setting to disable Photo View across all courses.
- Added global disable enforcement in both button injection hook and direct roster page access.

### 1.0.0 — 2026-04-06

- Initial release.
- Nightly import task with `yucard_lastupdated` change detection.
- Per-course Photo View enable/disable setting (default: disabled).
- Responsive photo roster with search, sort, and pagination.
- Manual photo upload page with current-photo preview.
- Full Mustache templating for both pages.
- GDPR privacy provider.
- Capabilities: `viewroster` (course), `uploadphoto` (system).
