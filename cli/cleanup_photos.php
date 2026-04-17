<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * CLI script to clean up local_yucardphoto data.
 *
 * Usage (run from inside the Docker container):
 *
 *   # Dry-run — show what would be deleted without touching anything:
 *   php local/yucardphoto/cli/cleanup_photos.php --dry-run
 *
 *   # Delete ALL photos (file system files + mdl_local_yucardphoto rows):
 *   php local/yucardphoto/cli/cleanup_photos.php --all
 *
 *   # Delete photos for specific SISIDs only:
 *   php local/yucardphoto/cli/cleanup_photos.php --sisids=200502815,200507111
 *
 *   # Delete only orphaned file-system files (no matching DB row):
 *   php local/yucardphoto/cli/cleanup_photos.php --orphans
 *
 * Always run with --dry-run first to preview what will be removed.
 *
 * @package   local_yucardphoto
 * @copyright 2026 ED&IT, York University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir  . '/clilib.php');

// ── Parse CLI options ────────────────────────────────────────────────────────
list($options, $unrecognised) = cli_get_params(
    [
        'all'     => false,
        'orphans' => false,
        'sisids'  => '',
        'dry-run' => false,
        'help'    => false,
    ],
    [
        'h' => 'help',
        'n' => 'dry-run',
    ]
);

if ($options['help'] || (!$options['all'] && !$options['orphans'] && empty($options['sisids']))) {
    echo <<<EOT
Delete local_yucardphoto photo files from the Moodle file system and/or DB rows.

Options:
  --all             Delete ALL photo files and ALL mdl_local_yucardphoto rows.
  --orphans         Delete file-system files that have no matching DB row.
  --sisids=x,y,z    Delete photos (files + DB rows) for the listed SISIDs only.
  --dry-run  (-n)   Preview what would be deleted — no changes made.
  --help     (-h)   Show this help.

Examples:
  php local/yucardphoto/cli/cleanup_photos.php --dry-run --all
  php local/yucardphoto/cli/cleanup_photos.php --all
  php local/yucardphoto/cli/cleanup_photos.php --sisids=200502815,200507111
  php local/yucardphoto/cli/cleanup_photos.php --orphans

EOT;
    exit(0);
}

$dryrun     = (bool)$options['dry-run'];
$doall      = (bool)$options['all'];
$doorphans  = (bool)$options['orphans'];
$sisidlist  = array_filter(array_map('trim', explode(',', (string)$options['sisids'])));

if ($dryrun) {
    cli_writeln('DRY-RUN mode — no changes will be made.');
    cli_writeln('');
}

$syscontext = context_system::instance();
$fs         = get_file_storage();

$filesdeleted = 0;
$rowsdeleted  = 0;

// ── Helper: delete one student's file + DB row ───────────────────────────────
$delete_one = function(string $sisid) use ($DB, $fs, $syscontext, $dryrun, &$filesdeleted, &$rowsdeleted) {
    $itemid = abs(crc32($sisid));

    // Find all files for this itemid in the photos filearea.
    $files = $fs->get_area_files($syscontext->id, 'local_yucardphoto', 'photos', $itemid, '', false);
    foreach ($files as $file) {
        cli_writeln("  FILE  : " . $file->get_filename() . " (itemid={$itemid}, size=" . $file->get_filesize() . " bytes)");
        if (!$dryrun) {
            $file->delete();
        }
        $filesdeleted++;
    }

    // Delete the DB row.
    $rec = $DB->get_record('local_yucardphoto', ['sisid' => $sisid]);
    if ($rec) {
        cli_writeln("  DB ROW: sisid={$sisid}, firstname={$rec->firstname}, lastname={$rec->lastname}");
        if (!$dryrun) {
            $DB->delete_records('local_yucardphoto', ['sisid' => $sisid]);
        }
        $rowsdeleted++;
    }
};

// ── Mode: --all ──────────────────────────────────────────────────────────────
if ($doall) {
    cli_writeln('Mode: DELETE ALL photos and DB rows');
    cli_writeln('');

    // Get all DB rows.
    $allrecs = $DB->get_records('local_yucardphoto', null, 'sisid ASC', 'sisid');
    if (empty($allrecs)) {
        cli_writeln('No rows found in mdl_local_yucardphoto — nothing to do.');
    } else {
        foreach ($allrecs as $rec) {
            $delete_one($rec->sisid);
        }
    }

    // Also catch any orphaned files with no DB row.
    $allfiles = $fs->get_area_files($syscontext->id, 'local_yucardphoto', 'photos', false, 'itemid', false);
    foreach ($allfiles as $file) {
        // Check if we already handled this itemid via a DB row.
        // If the file still exists (dry-run) or has no DB row, clean it up.
        $stillexists = $fs->get_file($syscontext->id, 'local_yucardphoto', 'photos',
            $file->get_itemid(), $file->get_filepath(), $file->get_filename());
        if ($stillexists && !$dryrun) {
            cli_writeln("  ORPHAN FILE: " . $file->get_filename() . " (itemid=" . $file->get_itemid() . ")");
            $file->delete();
            $filesdeleted++;
        } elseif ($dryrun) {
            // In dry-run we just note it; it may or may not be a real orphan.
        }
    }
}

// ── Mode: --sisids ───────────────────────────────────────────────────────────
if (!empty($sisidlist)) {
    cli_writeln('Mode: DELETE photos for specific SISIDs: ' . implode(', ', $sisidlist));
    cli_writeln('');

    foreach ($sisidlist as $sisid) {
        $exists = $DB->record_exists('local_yucardphoto', ['sisid' => $sisid]) ||
                  !empty($fs->get_area_files($syscontext->id, 'local_yucardphoto', 'photos', abs(crc32($sisid)), '', false));
        if (!$exists) {
            cli_writeln("  SKIP: sisid={$sisid} — no file or DB row found.");
            continue;
        }
        $delete_one($sisid);
    }
}

// ── Mode: --orphans ──────────────────────────────────────────────────────────
if ($doorphans) {
    cli_writeln('Mode: DELETE orphaned files (file exists but no DB row)');
    cli_writeln('');

    $allfiles = $fs->get_area_files($syscontext->id, 'local_yucardphoto', 'photos', false, 'itemid', false);
    foreach ($allfiles as $file) {
        // Derive SISID from filename (strip extension).
        $sisid = pathinfo($file->get_filename(), PATHINFO_FILENAME);
        $hasrow = $DB->record_exists('local_yucardphoto', ['sisid' => $sisid]);
        if (!$hasrow) {
            cli_writeln("  ORPHAN: " . $file->get_filename() . " (itemid=" . $file->get_itemid() . ", sisid={$sisid})");
            if (!$dryrun) {
                $file->delete();
            }
            $filesdeleted++;
        }
    }
}

// ── Summary ──────────────────────────────────────────────────────────────────
cli_writeln('');
if ($dryrun) {
    cli_writeln("DRY-RUN complete — would delete {$filesdeleted} file(s), {$rowsdeleted} DB row(s).");
    cli_writeln('Re-run without --dry-run to apply changes.');
} else {
    cli_writeln("Cleanup complete — deleted {$filesdeleted} file(s), {$rowsdeleted} DB row(s).");
}
