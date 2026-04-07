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
 * Language strings for local_yucardphoto.
 *
 * @package   local_yucardphoto
 * @copyright 2026 ED&IT, York University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Plugin name.
$string['pluginname'] = 'YU Card Photo Roster';

// Task.
$string['task_import_yucard_photos'] = 'Import YU Card photos from external database';

// Course setting.
$string['coursesettings_heading']         = 'Photograph Roster';
$string['coursesettings_heading_desc']    = 'Controls whether the Photo View roster button is shown on the Participants page for this course.';
$string['enable_photo_view']              = 'Enable Photo View roster';
$string['enable_photo_view_desc']         = 'When enabled, authorised roles (instructors, managers, etc.) will see a "Photo View" button on the Participants page that opens the YU Card photo roster for this course.';
$string['enable_photo_view_yes']          = 'Yes';
$string['enable_photo_view_no']           = 'No';
$string['enable_photo_view_help']        = 'Yes - Show photo view button, No - Hide photo view button.';

// Participants page.
$string['photoview']           = 'Photograph Roster';
$string['photoviewtitle']      = 'Photograph Roster - {$a}';
$string['viewphoto']           = 'View Photo';
$string['backtoparticipants'] = 'Participants';
$string['search']             = 'Search';
$string['searchplaceholder']  = 'Search…';
$string['sortby']             = 'Sort by';
$string['sortbyfirstname']    = 'First name';
$string['sortbylastname']     = 'Last name';
$string['sortbyemail']        = 'Email';
$string['sortbysisid']        = 'Student ID';
$string['sortnophoto']        = 'No photo (missing first)';
$string['perpage']            = 'Photos per page';
$string['perpage_grid4']      = '4 × 5 (20 per page)';
$string['perpage_grid_all']   = 'All (max 100 per page)';
$string['nophotos']           = 'No student photos found for this course.';
$string['noresults']          = 'No students matched your search.';
$string['sisid']              = 'Student ID';
$string['email']              = 'Email';
$string['studentcount']       = '{$a} student(s) found';
$string['nophotocount']       = '{$a} student(s) missing a photo';
$string['showall']            = 'Show all {$a}';
$string['showpaged']          = 'Show 20 per page';
$string['students']           = 'Students';
$string['pageinfo']           = 'This page shows the photo roster for enrolled students in this course. Use the search box to find students by first name, last name, or student ID. Use the Sort by dropdown to change the order. Students without a photo on file are highlighted - click the warning badge to view them first.';

// Upload page.
$string['uploadphoto']        = 'Upload Student Photo';
$string['uploadphototitle']   = 'YU Card Photo - Manual Upload';
$string['searchstudent']      = 'Search Moodle user';
$string['searchstudent_help'] = 'Enter a username, student ID (profile field), first name or last name to find a Moodle user.';
$string['selectuser']         = 'Select student';
$string['choosephoto']        = 'Photo file';
$string['choosephoto_help']   = 'Upload a JPEG or PNG photo for this student. Max 2 MB. The photo will be stored in the Moodle file system. If a photo already exists for this student it will be replaced.';
$string['overridephoto']      = 'Current photo (will be replaced)';
$string['uploadsubmit']       = 'Save photo';
$string['uploadsuccess']      = 'Photo saved successfully for {$a}.';
$string['uploaderror']        = 'Could not save the photo. Please try again.';
$string['usernotfound']       = 'No Moodle user matched that search term.';
$string['invalidfiletype']    = 'Only JPEG and PNG files are accepted.';
$string['minsearchchars']     = 'Please enter at least 3 characters to search.';
$string['noneselected']       = 'No student selected yet - search above and click a name.';

// Admin settings.
$string['settings_heading']        = 'YU Card Photo Import Settings';
$string['yucard_db_host']          = 'External DB host';
$string['yucard_db_host_desc']     = 'Hostname or IP address of the YU Card source database server.';
$string['yucard_db_name']          = 'External DB name / service name';
$string['yucard_db_name_desc']     = 'Database name (MySQL/PostgreSQL) or Oracle service/SID.';
$string['yucard_db_user']          = 'External DB username';
$string['yucard_db_user_desc']     = 'Username used to connect to the external YU Card database.';
$string['yucard_db_pass']          = 'External DB password';
$string['yucard_db_pass_desc']     = 'Password for the external YU Card database user. Stored encrypted.';
$string['yucard_db_type']          = 'External DB type';
$string['yucard_db_type_desc']     = 'Database driver to use when connecting to the external YU Card system.';
$string['yucard_db_table']         = 'Source table / view name';
$string['yucard_db_table_desc']    = 'Name of the table or view in the external database that contains student photo data.';
$string['yucard_col_sisid']        = 'Student ID column';
$string['yucard_col_sisid_desc']   = 'Column name in the source table that holds the student/SIS ID.';
$string['yucard_col_firstname']    = 'First name column';
$string['yucard_col_firstname_desc'] = 'Column name for the student first name.';
$string['yucard_col_lastname']     = 'Last name column';
$string['yucard_col_lastname_desc'] = 'Column name for the student last name.';
$string['yucard_col_photo']        = 'Photo blob column';
$string['yucard_col_photo_desc']   = 'Column name that contains the raw image binary (BLOB).';
$string['yucard_col_updated']      = 'Last-updated timestamp column';
$string['yucard_col_updated_desc'] = 'Column name that holds the last-modified timestamp for the photo record.';

// Privacy.
$string['privacy:metadata:local_yucardphoto']                       = 'The YU Card Photo Roster plugin stores photo references linked to a student identifier (SIS ID) and basic name information.';
$string['privacy:metadata:local_yucardphoto:sisid']                 = 'The student\'s SIS/student ID number.';
$string['privacy:metadata:local_yucardphoto:firstname']             = 'The student\'s first name as recorded in the YU Card system.';
$string['privacy:metadata:local_yucardphoto:lastname']              = 'The student\'s last name as recorded in the YU Card system.';
$string['privacy:metadata:local_yucardphoto:moodle_file_url']       = 'The Moodle file URL pointing to the stored photo in the Moodle file system.';
$string['privacy:metadata:local_yucardphoto:yucard_image_path']     = 'The internal file-system path of the stored photo within Moodle\'s file area.';
$string['privacy:metadata:local_yucardphoto:yucard_lastupdated']    = 'Unix timestamp of when the photo was last updated in the YU Card source system.';
$string['privacy:metadata:local_yucardphoto:timecreated']           = 'Unix timestamp of when this record was first created in Moodle.';
$string['privacy:metadata:local_yucardphoto:timemodified']          = 'Unix timestamp of when this record was last modified in Moodle.';
$string['privacy:metadata:external_yucard_db']                      = 'Photo data (binary image blobs) and basic student information are read from an external YU Card database. No data is written back to that system.';
