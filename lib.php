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
 * Library functions for local_yucardphoto.
 *
 * @package   local_yucardphoto
 * @copyright 2026 ED&IT, York University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Serve plugin files (student photos stored in the Moodle file system).
 *
 * Called by Moodle's pluginfile.php dispatcher when a URL of the form
 * /pluginfile.php/{contextid}/local_yucardphoto/photos/{itemid}/{filename}
 * is requested.
 *
 * Access is restricted to users who can view participants in the system
 * context (i.e. the same roles that can see the Photo View roster).
 *
 * @param stdClass      $course   Not used for system-context files.
 * @param stdClass      $cm       Not used.
 * @param context       $context  Must be the system context.
 * @param string        $filearea Must be 'photos'.
 * @param array         $args     [itemid, filename].
 * @param bool          $forcedownload
 * @param array         $options
 * @return void  Sends the file or throws an exception.
 */
function local_yucardphoto_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel !== CONTEXT_SYSTEM) {
        send_file_not_found();
    }

    if ($filearea !== 'photos') {
        send_file_not_found();
    }

    // Require login; role check is done in the roster page, but we still
    // restrict raw file access to logged-in users.
    require_login();
    if (!has_capability('moodle/course:viewparticipants', context_system::instance())) {
        send_file_not_found();
    }

    $itemid   = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs   = get_file_storage();
    $file = $fs->get_file($context->id, 'local_yucardphoto', $filearea, $itemid, $filepath, $filename);

    if (!$file || $file->is_directory()) {
        send_file_not_found();
    }

    // Cache for one day — photos rarely change.
    send_stored_file($file, 86400, 0, $forcedownload, $options);
}

/**
 * Check whether the Photo View feature is enabled for a given course.
 *
 * @param  int  $courseid
 * @return bool
 */
function local_yucardphoto_is_enabled_for_course(int $courseid): bool {
    global $DB;
    $rec = $DB->get_record('local_yucardphoto_coursesettings', ['courseid' => $courseid]);
    return $rec && (bool)$rec->enabled;
}

/**
 * Return the roles that are allowed to see the Photo View button and roster.
 * Checked against the course context.
 *
 * The capability 'local/yucardphoto:viewroster' is assigned to:
 *   manager, coursecreator, editingteacher, teacher (non-editing instructor).
 * Site admins always pass.
 *
 * @param  context_course $context
 * @return bool
 */
function local_yucardphoto_can_view_roster(context_course $context): bool {
    return has_capability('local/yucardphoto:viewroster', $context);
}

/**
 * Store a raw image binary as a Moodle file and return the pluginfile URL.
 *
 * The file is stored in the system context under:
 *   component  : local_yucardphoto
 *   filearea   : photos
 *   itemid     : hash-derived integer (crc32 of sisid) for stable addressing
 *   filepath   : /
 *   filename   : {sisid}.jpg  (or .png depending on detected mime)
 *
 * If a file for this sisid already exists it is deleted and replaced.
 *
 * @param  string $sisid     Student/SIS ID — used to name and address the file.
 * @param  string $imagedata Raw binary image data.
 * @param  string $mime      MIME type: 'image/jpeg' or 'image/png'.
 * @return string            Moodle pluginfile URL for the stored photo.
 */
function local_yucardphoto_store_photo(string $sisid, string $imagedata, string $mime = 'image/jpeg'): string {
    $syscontext = context_system::instance();
    $fs         = get_file_storage();
    $ext        = ($mime === 'image/png') ? 'png' : 'jpg';
    $filename   = $sisid . '.' . $ext;
    // Use abs(crc32) as a stable integer itemid derived from the sisid string.
    $itemid     = abs(crc32($sisid));

    // Delete any existing file for this student.
    $existing = $fs->get_file($syscontext->id, 'local_yucardphoto', 'photos', $itemid, '/', $filename);
    if ($existing) {
        $existing->delete();
    }
    // Also delete any old file with a different extension.
    $altext  = ($ext === 'jpg') ? 'png' : 'jpg';
    $altfile = $fs->get_file($syscontext->id, 'local_yucardphoto', 'photos', $itemid, '/', $sisid . '.' . $altext);
    if ($altfile) {
        $altfile->delete();
    }

    $filerecord = [
        'contextid' => $syscontext->id,
        'component' => 'local_yucardphoto',
        'filearea'  => 'photos',
        'itemid'    => $itemid,
        'filepath'  => '/',
        'filename'  => $filename,
        'mimetype'  => $mime,
        'timecreated'  => time(),
        'timemodified' => time(),
    ];

    $fs->create_file_from_string($filerecord, $imagedata);

    // Build and return the pluginfile URL.
    return moodle_url::make_pluginfile_url(
        $syscontext->id,
        'local_yucardphoto',
        'photos',
        $itemid,
        '/',
        $filename
    )->out(false);
}
