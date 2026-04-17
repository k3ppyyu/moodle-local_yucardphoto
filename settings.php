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
 * Admin settings for local_yucardphoto.
 *
 * @package   local_yucardphoto
 * @copyright 2026 ED&IT, York University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Category groups all plugin admin pages together under Local plugins.
    $ADMIN->add('localplugins', new admin_category(
        'local_yucardphoto_category',
        get_string('pluginname', 'local_yucardphoto')
    ));

    // Main settings page — nested inside the category.
    $settings = new admin_settingpage(
        'local_yucardphoto',
        get_string('settings_heading', 'local_yucardphoto')
    );
    $ADMIN->add('local_yucardphoto_category', $settings);

    // Upload page — nested inside the category, site admins only.
    $ADMIN->add('local_yucardphoto_category', new admin_externalpage(
        'local_yucardphoto_upload',
        get_string('uploadphoto', 'local_yucardphoto'),
        new moodle_url('/local/yucardphoto/upload.php'),
        'moodle/site:config'
    ));

    // Cleanup page — nested inside the category, site admins only.
    $ADMIN->add('local_yucardphoto_category', new admin_externalpage(
        'local_yucardphoto_cleanup',
        get_string('cleanup_title', 'local_yucardphoto'),
        new moodle_url('/local/yucardphoto/admin/cleanup.php'),
        'moodle/site:config'
    ));


    $settings->add(new admin_setting_heading(
        'local_yucardphoto/settings_heading',
        get_string('settings_heading', 'local_yucardphoto'),
        ''
    ));

    // DB type — default to Oracle for York.
    $settings->add(new admin_setting_configselect(
        'local_yucardphoto/yucard_db_type',
        get_string('yucard_db_type', 'local_yucardphoto'),
        get_string('yucard_db_type_desc', 'local_yucardphoto'),
        'oci',
        ['mysqli' => 'MySQL / MariaDB', 'pgsql' => 'PostgreSQL', 'oci' => 'Oracle (OCI8)']
    ));

    // TNS connect string — Easy Connect format: host:port/service_name
    // e.g. yucarddb3qa.yorku.yorku.ca:1521/bbts
    $settings->add(new admin_setting_configtext(
        'local_yucardphoto/yucard_db_tns',
        get_string('yucard_db_tns', 'local_yucardphoto'),
        get_string('yucard_db_tns_desc', 'local_yucardphoto'),
        'yucarddb3qa.yorku.yorku.ca:1521/bbts'
    ));

    // Oracle schema that owns the photo table.
    $settings->add(new admin_setting_configtext(
        'local_yucardphoto/yucard_db_schema',
        get_string('yucard_db_schema', 'local_yucardphoto'),
        get_string('yucard_db_schema_desc', 'local_yucardphoto'),
        'envision'
    ));

    $settings->add(new admin_setting_configtext(
        'local_yucardphoto/yucard_db_user',
        get_string('yucard_db_user', 'local_yucardphoto'),
        get_string('yucard_db_user_desc', 'local_yucardphoto'),
        ''
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_yucardphoto/yucard_db_pass',
        get_string('yucard_db_pass', 'local_yucardphoto'),
        get_string('yucard_db_pass_desc', 'local_yucardphoto'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_yucardphoto/yucard_db_table',
        get_string('yucard_db_table', 'local_yucardphoto'),
        get_string('yucard_db_table_desc', 'local_yucardphoto'),
        'vw_yk_eclass_photos'
    ));

    $settings->add(new admin_setting_heading(
        'local_yucardphoto/col_heading',
        get_string('yucard_col_heading', 'local_yucardphoto'),
        get_string('yucard_col_heading_desc', 'local_yucardphoto')
    ));

    // Student ID column — maps to mdl_user.idnumber for name lookup.
    $settings->add(new admin_setting_configtext(
        'local_yucardphoto/yucard_col_sisid',
        get_string('yucard_col_sisid', 'local_yucardphoto'),
        get_string('yucard_col_sisid_desc', 'local_yucardphoto'),
        'SISID'
    ));

    // Photo BLOB column.
    $settings->add(new admin_setting_configtext(
        'local_yucardphoto/yucard_col_photo',
        get_string('yucard_col_photo', 'local_yucardphoto'),
        get_string('yucard_col_photo_desc', 'local_yucardphoto'),
        'PHOTO'
    ));

    // Last modified/updated timestamp column.
    $settings->add(new admin_setting_configtext(
        'local_yucardphoto/yucard_col_updated',
        get_string('yucard_col_updated', 'local_yucardphoto'),
        get_string('yucard_col_updated_desc', 'local_yucardphoto'),
        'PHOTOMODIFIEDDATE'
    ));

    // Quick-access links to the upload and cleanup pages (site admins only).
    $uploadurl  = new moodle_url('/local/yucardphoto/upload.php');
    $cleanupurl = new moodle_url('/local/yucardphoto/admin/cleanup.php');
    $settings->add(new admin_setting_description(
        'local_yucardphoto/toollinks',
        get_string('cleanup_tools_heading', 'local_yucardphoto'),
        html_writer::link($uploadurl,  get_string('uploadphoto',    'local_yucardphoto'), ['class' => 'btn btn-secondary me-2'])
        . html_writer::link($cleanupurl, get_string('cleanup_title', 'local_yucardphoto'), ['class' => 'btn btn-outline-secondary'])
    ));
}
