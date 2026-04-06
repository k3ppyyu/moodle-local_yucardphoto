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
 * Privacy provider for local_yucardphoto.
 *
 * @package   local_yucardphoto
 * @copyright 2026 ED&IT, York University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_yucardphoto\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider — the plugin stores student photos keyed by SIS ID.
 *
 * Because records are keyed by sisid (not by mdl_user.id), we match users
 * via the idnumber field (which holds the SIS/student ID at York University).
 * If your installation maps sisid differently, adjust get_sisid_for_user().
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\subsystem\provider,
    \core_privacy\local\request\core_userlist_provider {

    // -----------------------------------------------------------------------
    // Metadata
    // -----------------------------------------------------------------------

    /**
     * Describe the data this plugin stores.
     *
     * @param  collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table(
            'local_yucardphoto',
            [
                'sisid'              => 'privacy:metadata:local_yucardphoto:sisid',
                'firstname'          => 'privacy:metadata:local_yucardphoto:firstname',
                'lastname'           => 'privacy:metadata:local_yucardphoto:lastname',
                'moodle_file_url'    => 'privacy:metadata:local_yucardphoto:moodle_file_url',
                'yucard_image_path'  => 'privacy:metadata:local_yucardphoto:yucard_image_path',
                'yucard_lastupdated' => 'privacy:metadata:local_yucardphoto:yucard_lastupdated',
                'timecreated'        => 'privacy:metadata:local_yucardphoto:timecreated',
                'timemodified'       => 'privacy:metadata:local_yucardphoto:timemodified',
            ],
            'privacy:metadata:local_yucardphoto'
        );

        $collection->add_external_location_link(
            'external_yucard_db',
            [],
            'privacy:metadata:external_yucard_db'
        );

        return $collection;
    }

    // -----------------------------------------------------------------------
    // Context list
    // -----------------------------------------------------------------------

    /**
     * Get contexts containing data for the given user.
     *
     * Photos are stored in the system context.
     *
     * @param  int         $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sisid = self::get_sisid_for_user($userid);
        if (!$sisid) {
            return $contextlist;
        }

        global $DB;
        if ($DB->record_exists('local_yucardphoto', ['sisid' => $sisid])) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    // -----------------------------------------------------------------------
    // User list
    // -----------------------------------------------------------------------

    /**
     * Get the list of users who have data within the given context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!($context instanceof \context_system)) {
            return;
        }

        // Join on idnumber (SIS ID).
        $sql = "SELECT u.id
                  FROM {user} u
                  JOIN {local_yucardphoto} y ON y.sisid = u.idnumber
                 WHERE u.deleted = 0";

        $userlist->add_from_sql('id', $sql, []);
    }

    // -----------------------------------------------------------------------
    // Export
    // -----------------------------------------------------------------------

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        $sisid  = self::get_sisid_for_user($userid);
        if (!$sisid) {
            return;
        }

        $record = $DB->get_record('local_yucardphoto', ['sisid' => $sisid]);
        if (!$record) {
            return;
        }

        $context = \context_system::instance();
        writer::with_context($context)->export_data(
            [get_string('pluginname', 'local_yucardphoto')],
            (object)[
                'sisid'              => $record->sisid,
                'firstname'          => $record->firstname,
                'lastname'           => $record->lastname,
                'moodle_file_url'    => $record->moodle_file_url,
                'yucard_lastupdated' => $record->yucard_lastupdated
                    ? userdate($record->yucard_lastupdated) : '',
                'timecreated'        => userdate($record->timecreated),
                'timemodified'       => userdate($record->timemodified),
            ]
        );

        // Export the stored photo file.
        $fs     = get_file_storage();
        $itemid = abs(crc32($sisid));
        $files  = $fs->get_area_files($context->id, 'local_yucardphoto', 'photos', $itemid, '', false);
        foreach ($files as $file) {
            writer::with_context($context)->export_file(
                [get_string('pluginname', 'local_yucardphoto')],
                $file
            );
        }
    }

    // -----------------------------------------------------------------------
    // Delete
    // -----------------------------------------------------------------------

    /**
     * Delete all data in the given context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        if (!($context instanceof \context_system)) {
            return;
        }
        global $DB;
        // Delete all photo files.
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'local_yucardphoto', 'photos');
        // Delete all records.
        $DB->delete_records('local_yucardphoto');
    }

    /**
     * Delete all data for the given user in the given contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        $sisid  = self::get_sisid_for_user($userid);
        if (!$sisid) {
            return;
        }

        $context = \context_system::instance();
        $fs      = get_file_storage();
        $itemid  = abs(crc32($sisid));

        $fs->delete_area_files($context->id, 'local_yucardphoto', 'photos', $itemid);
        $DB->delete_records('local_yucardphoto', ['sisid' => $sisid]);
    }

    /**
     * Delete data for multiple users within a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!($context instanceof \context_system)) {
            return;
        }

        $fs = get_file_storage();
        foreach ($userlist->get_userids() as $userid) {
            $sisid = self::get_sisid_for_user($userid);
            if (!$sisid) {
                continue;
            }
            $itemid = abs(crc32($sisid));
            $fs->delete_area_files($context->id, 'local_yucardphoto', 'photos', $itemid);
            $DB->delete_records('local_yucardphoto', ['sisid' => $sisid]);
        }
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Return the SIS ID for a Moodle user (stored in mdl_user.idnumber at York).
     *
     * @param  int         $userid
     * @return string|null
     */
    private static function get_sisid_for_user(int $userid): ?string {
        global $DB;
        $idnumber = $DB->get_field('user', 'idnumber', ['id' => $userid, 'deleted' => 0]);
        return ($idnumber !== false && $idnumber !== '') ? (string)$idnumber : null;
    }
}
