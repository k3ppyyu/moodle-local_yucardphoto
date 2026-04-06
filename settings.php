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
    $settings = new admin_settingpage(
        'local_yucardphoto',
        get_string('pluginname', 'local_yucardphoto')
    );

    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_heading(
        'local_yucardphoto/settings_heading',
        get_string('settings_heading', 'local_yucardphoto'),
        ''
    ));

    // DB type.
    $settings->add(new admin_setting_configselect(
        'local_yucardphoto/yucard_db_type',
        get_string('yucard_db_type', 'local_yucardphoto'),
        get_string('yucard_db_type_desc', 'local_yucardphoto'),
        'mysqli',
        ['mysqli' => 'MySQL / MariaDB', 'pgsql' => 'PostgreSQL', 'oci' => 'Oracle (OCI8)']
    ));

    $settings->add(new admin_setting_configtext(
        'local_yucardphoto/yucard_db_host',
        get_string('yucard_db_host', 'local_yucardphoto'),
        get_string('yucard_db_host_desc', 'local_yucardphoto'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_yucardphoto/yucard_db_name',
        get_string('yucard_db_name', 'local_yucardphoto'),
        get_string('yucard_db_name_desc', 'local_yucardphoto'),
        ''
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
        'yucard_photos'
    ));

    $settings->add(new admin_setting_configtext(
        'local_yucardphoto/yucard_col_sisid',
        get_string('yucard_col_sisid', 'local_yucardphoto'),
        get_string('yucard_col_sisid_desc', 'local_yucardphoto'),
        'student_id'
    ));

    $settings->add(new admin_setting_configtext(
        'local_yucardphoto/yucard_col_firstname',
        get_string('yucard_col_firstname', 'local_yucardphoto'),
        get_string('yucard_col_firstname_desc', 'local_yucardphoto'),
        'first_name'
    ));

    $settings->add(new admin_setting_configtext(
        'local_yucardphoto/yucard_col_lastname',
        get_string('yucard_col_lastname', 'local_yucardphoto'),
        get_string('yucard_col_lastname_desc', 'local_yucardphoto'),
        'last_name'
    ));

    $settings->add(new admin_setting_configtext(
        'local_yucardphoto/yucard_col_photo',
        get_string('yucard_col_photo', 'local_yucardphoto'),
        get_string('yucard_col_photo_desc', 'local_yucardphoto'),
        'photo_blob'
    ));

    $settings->add(new admin_setting_configtext(
        'local_yucardphoto/yucard_col_updated',
        get_string('yucard_col_updated', 'local_yucardphoto'),
        get_string('yucard_col_updated_desc', 'local_yucardphoto'),
        'last_updated'
    ));

    // Link to manual upload page.
    $uploadurl = new moodle_url('/local/yucardphoto/upload.php');
    $settings->add(new admin_setting_description(
        'local_yucardphoto/uploadlink',
        '',
        html_writer::link($uploadurl, get_string('uploadphoto', 'local_yucardphoto'), ['class' => 'btn btn-secondary'])
    ));
}
