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
 * Admin cleanup page for local_yucardphoto.
 *
 * Allows site admins to:
 *  - View a summary of stored photos (total, orphaned files, DB rows without files).
 *  - Delete all photos and DB rows (full reset).
 *  - Delete only orphaned file-system files (file exists but no DB row).
 *  - Delete only stale DB rows (DB row exists but file is missing).
 *  - Delete photos for specific SISIDs.
 *
 * @package   local_yucardphoto
 * @copyright 2026 ED&IT, York University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/yucardphoto/lib.php');

require_login();

// Restrict to site admins only.
if (!is_siteadmin()) {
    throw new \moodle_exception('accessdenied', 'admin');
}

$syscontext = context_system::instance();

$PAGE->set_url(new moodle_url('/local/yucardphoto/admin/cleanup.php'));
$PAGE->set_context($syscontext);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('cleanup_title', 'local_yucardphoto'));
$PAGE->set_heading(get_string('cleanup_title', 'local_yucardphoto'));


$action  = optional_param('action',  '',  PARAM_ALPHANUMEXT);
$sisids  = optional_param('sisids',  '',  PARAM_TEXT);
$confirm = optional_param('confirm', 0,   PARAM_INT);
$sesskey = optional_param('sesskey', '',  PARAM_RAW);

$PAGE->set_url(new moodle_url('/local/yucardphoto/admin/cleanup.php'));
$PAGE->set_title(get_string('cleanup_title', 'local_yucardphoto'));
$PAGE->set_heading(get_string('cleanup_title', 'local_yucardphoto'));

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Build a summary of the current state of stored photos.
 */
function yucardphoto_cleanup_stats(): array {
    global $DB;
    $syscontext = context_system::instance();
    $fs         = get_file_storage();

    // All DB rows.
    $dbrecs = $DB->get_records('local_yucardphoto', null, 'sisid ASC', 'id,sisid,firstname,lastname,timemodified');

    // All stored files.
    $allfiles = $fs->get_area_files($syscontext->id, 'local_yucardphoto', 'photos', false, 'itemid', false);

    // Index DB rows by sisid.
    $dbbysissid = [];
    foreach ($dbrecs as $r) {
        $dbbysissid[$r->sisid] = $r;
    }

    // Index files by sisid (derived from filename).
    $filesbysissid = [];
    $totalsize     = 0;
    foreach ($allfiles as $f) {
        $sisid = pathinfo($f->get_filename(), PATHINFO_FILENAME);
        $filesbysissid[$sisid] = $f;
        $totalsize += $f->get_filesize();
    }

    // Orphaned files — file exists but no DB row.
    $orphanfiles = [];
    foreach ($filesbysissid as $sisid => $f) {
        if (!isset($dbbysissid[$sisid])) {
            $orphanfiles[] = [
                'sisid'    => $sisid,
                'filename' => $f->get_filename(),
                'size'     => display_size($f->get_filesize()),
            ];
        }
    }

    // Stale DB rows — DB row exists but file is missing.
    $stalerows = [];
    foreach ($dbbysissid as $sisid => $r) {
        if (!isset($filesbysissid[$sisid])) {
            $stalerows[] = [
                'sisid'     => $sisid,
                'firstname' => $r->firstname,
                'lastname'  => $r->lastname,
            ];
        }
    }

    return [
        'totaldb'       => count($dbrecs),
        'totalfiles'    => count($filesbysissid),
        'totalsize'     => display_size($totalsize),
        'orphanfiles'   => $orphanfiles,
        'orphancount'   => count($orphanfiles),
        'stalerows'     => $stalerows,
        'stalecount'    => count($stalerows),
        'healthy'       => count($dbbysissid) - count($stalerows),
    ];
}

/**
 * Delete all photos — both file-system files and DB rows.
 * Returns [filesdeleted, rowsdeleted].
 */
function yucardphoto_delete_all(): array {
    global $DB;
    $syscontext   = context_system::instance();
    $fs           = get_file_storage();
    $filesdeleted = 0;
    $rowsdeleted  = 0;

    $allfiles = $fs->get_area_files($syscontext->id, 'local_yucardphoto', 'photos', false, 'itemid', false);
    foreach ($allfiles as $f) {
        $f->delete();
        $filesdeleted++;
    }

    $rowsdeleted = $DB->count_records('local_yucardphoto');
    $DB->delete_records('local_yucardphoto');

    return [$filesdeleted, $rowsdeleted];
}

/**
 * Delete only orphaned file-system files (no DB row).
 * Returns filesdeleted count.
 */
function yucardphoto_delete_orphan_files(): int {
    global $DB;
    $syscontext = context_system::instance();
    $fs         = get_file_storage();
    $deleted    = 0;

    $allfiles = $fs->get_area_files($syscontext->id, 'local_yucardphoto', 'photos', false, 'itemid', false);
    foreach ($allfiles as $f) {
        $sisid = pathinfo($f->get_filename(), PATHINFO_FILENAME);
        if (!$DB->record_exists('local_yucardphoto', ['sisid' => $sisid])) {
            $f->delete();
            $deleted++;
        }
    }
    return $deleted;
}

/**
 * Delete stale DB rows (DB row exists but file is missing from file system).
 * Returns rowsdeleted count.
 */
function yucardphoto_delete_stale_rows(): int {
    global $DB;
    $syscontext = context_system::instance();
    $fs         = get_file_storage();
    $deleted    = 0;

    $allrecs = $DB->get_records('local_yucardphoto', null, '', 'id,sisid');
    foreach ($allrecs as $rec) {
        $itemid = abs(crc32($rec->sisid));
        $files  = $fs->get_area_files($syscontext->id, 'local_yucardphoto', 'photos', $itemid, '', false);
        if (empty($files)) {
            $DB->delete_records('local_yucardphoto', ['id' => $rec->id]);
            $deleted++;
        }
    }
    return $deleted;
}

/**
 * Delete photos for a comma-separated list of SISIDs.
 * Returns [filesdeleted, rowsdeleted, notfound[]].
 */
function yucardphoto_delete_by_sisids(array $sisidlist): array {
    global $DB;
    $syscontext   = context_system::instance();
    $fs           = get_file_storage();
    $filesdeleted = 0;
    $rowsdeleted  = 0;
    $notfound     = [];

    foreach ($sisidlist as $sisid) {
        $sisid  = trim($sisid);
        $itemid = abs(crc32($sisid));
        $files  = $fs->get_area_files($syscontext->id, 'local_yucardphoto', 'photos', $itemid, '', false);
        $hasrow = $DB->record_exists('local_yucardphoto', ['sisid' => $sisid]);

        if (empty($files) && !$hasrow) {
            $notfound[] = $sisid;
            continue;
        }
        foreach ($files as $f) {
            $f->delete();
            $filesdeleted++;
        }
        if ($hasrow) {
            $DB->delete_records('local_yucardphoto', ['sisid' => $sisid]);
            $rowsdeleted++;
        }
    }
    return [$filesdeleted, $rowsdeleted, $notfound];
}

// ── Handle confirmed POST actions ────────────────────────────────────────────
$notification = '';
$notiftype    = 'success';

if ($confirm && confirm_sesskey()) {
    switch ($action) {
        case 'deleteall':
            [$fd, $rd] = yucardphoto_delete_all();
            $notification = get_string('cleanup_deleted_all', 'local_yucardphoto', (object)['files' => $fd, 'rows' => $rd]);
            break;

        case 'deleteorphans':
            $fd = yucardphoto_delete_orphan_files();
            $notification = get_string('cleanup_deleted_orphans', 'local_yucardphoto', $fd);
            break;

        case 'deletestale':
            $rd = yucardphoto_delete_stale_rows();
            $notification = get_string('cleanup_deleted_stale', 'local_yucardphoto', $rd);
            break;

        case 'deletesisids':
            $list = array_filter(array_map('trim', explode(',', $sisids)));
            if (empty($list)) {
                $notification = get_string('cleanup_nosisids', 'local_yucardphoto');
                $notiftype    = 'warning';
            } else {
                [$fd, $rd, $nf] = yucardphoto_delete_by_sisids($list);
                $notification = get_string('cleanup_deleted_sisids', 'local_yucardphoto',
                    (object)['files' => $fd, 'rows' => $rd, 'notfound' => implode(', ', $nf)]);
            }
            break;
    }
}

// ── Build stats for display ───────────────────────────────────────────────────
$stats = yucardphoto_cleanup_stats();

// ── Build template context ────────────────────────────────────────────────────
$baseurl    = new moodle_url('/local/yucardphoto/admin/cleanup.php');
$sesskey    = sesskey();

$templatectx = [
    'settingsurl'    => (new moodle_url('/admin/settings.php', ['section' => 'local_yucardphoto']))->out(false),
    'notification'   => $notification,
    'notiftype'      => $notiftype,
    'totaldb'        => $stats['totaldb'],
    'totalfiles'     => $stats['totalfiles'],
    'totalsize'      => $stats['totalsize'],
    'healthy'        => $stats['healthy'],
    'orphancount'    => $stats['orphancount'],
    'stalecount'     => $stats['stalecount'],
    'orphanfiles'    => array_values($stats['orphanfiles']),
    'stalerows'      => array_values($stats['stalerows']),
    'hasorphans'     => $stats['orphancount'] > 0,
    'hasstale'       => $stats['stalecount'] > 0,
    'hasany'         => $stats['totaldb'] > 0 || $stats['totalfiles'] > 0,
    'formaction'     => $baseurl->out(false),
    'sesskey'        => $sesskey,
];

// ── Render ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_yucardphoto/cleanup', $templatectx);
echo $OUTPUT->footer();
