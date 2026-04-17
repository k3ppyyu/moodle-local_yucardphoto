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
 * Manual photo upload page for administrators.
 *
 * Allows site admins / managers to:
 *  1. Search for a Moodle user by name, username, or idnumber (SIS ID).
 *  2. Upload a JPEG or PNG photo for that user.
 *  3. The photo is stored in the Moodle file system and the
 *     local_yucardphoto record is created/updated.
 *
 * Access requires local/yucardphoto:uploadphoto (managers + admins only).
 *
 * @package   local_yucardphoto
 * @copyright 2026 ED&IT, York University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/form/upload_form.php');

require_login();

// Restrict to site admins only.
if (!is_siteadmin()) {
    throw new \moodle_exception('accessdenied', 'admin');
}

$syscontext = context_system::instance();

// -------------------------------------------------------------------------
// Page setup
// -------------------------------------------------------------------------
$PAGE->set_url(new moodle_url('/local/yucardphoto/upload.php'));
$PAGE->set_context($syscontext);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('uploadphototitle', 'local_yucardphoto'));
$PAGE->set_heading(get_string('uploadphototitle', 'local_yucardphoto'));

// -------------------------------------------------------------------------
// Form instantiation
// -------------------------------------------------------------------------

$form = new yucardphoto_upload_form();

// -------------------------------------------------------------------------
// Process submission
// -------------------------------------------------------------------------
$notice      = '';
$noticetype  = 'success';

if ($form->is_cancelled()) {
    redirect(new moodle_url('/admin/search.php'));

} else if ($data = $form->get_data()) {
    $userid = (int)$data->userid;
    $user   = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', IGNORE_MISSING);

    if (!$user) {
        $notice     = get_string('usernotfound', 'local_yucardphoto');
        $noticetype = 'error';
    } else {
        // Get the uploaded file content from the draft file area.
        $fs       = get_file_storage();
        $draftid  = file_get_submitted_draft_itemid('photofile');
        $userctx  = context_user::instance($USER->id);
        $draftfiles = $fs->get_area_files($userctx->id, 'user', 'draft', $draftid, '', false);
        $draftfile  = reset($draftfiles);

        if (!$draftfile) {
            $notice     = get_string('uploaderror', 'local_yucardphoto');
            $noticetype = 'error';
        } else {
            $mime = $draftfile->get_mimetype();
            if (!in_array($mime, ['image/jpeg', 'image/png'])) {
                $notice     = get_string('invalidfiletype', 'local_yucardphoto');
                $noticetype = 'error';
            } else {
                try {
                    $imagedata = $draftfile->get_content();
                    // At York the SIS ID is stored in idnumber. Fall back to
                    // uid_{id} only for accounts that have no idnumber set.
                    $sisid = !empty($user->idnumber) ? $user->idnumber : 'uid_' . $user->id;

                    // Store photo — pass $USER->id so the record shows who
                    // manually uploaded this photo (distinguishes from task = -1).
                    $fileurl = local_yucardphoto_store_photo($sisid, $imagedata, $mime, $USER->id);
                    $ext     = ($mime === 'image/png') ? 'png' : 'jpg';
                    $now     = time();

                    // Enforce one record per sisid: always upsert.
                    // The UNIQUE index on sisid prevents duplicates at the DB
                    // level; we use get_record + update/insert to keep the same
                    // row (preserving timecreated and the original task history).
                    $existing = $DB->get_record('local_yucardphoto', ['sisid' => $sisid]);
                    if ($existing) {
                        $existing->firstname          = $user->firstname;
                        $existing->lastname           = $user->lastname;
                        $existing->moodle_file_url    = $fileurl;
                        $existing->yucard_image_path  = "/{$sisid}.{$ext}";
                        $existing->yucard_lastupdated = $now;
                        $existing->uploaded_by        = $USER->id;
                        $existing->timemodified       = $now;
                        $DB->update_record('local_yucardphoto', $existing);
                    } else {
                        $DB->insert_record('local_yucardphoto', (object)[
                            'sisid'              => $sisid,
                            'firstname'          => $user->firstname,
                            'lastname'           => $user->lastname,
                            'moodle_file_url'    => $fileurl,
                            'yucard_image_path'  => "/{$sisid}.{$ext}",
                            'yucard_lastupdated' => $now,
                            'uploaded_by'        => $USER->id,
                            'timecreated'        => $now,
                            'timemodified'       => $now,
                        ]);
                    }

                    $fullname   = fullname($user);
                    $notice     = get_string('uploadsuccess', 'local_yucardphoto', $fullname);
                    $noticetype = 'success';
                } catch (\Throwable $e) {
                    $notice     = get_string('uploaderror', 'local_yucardphoto');
                    $noticetype = 'error';
                    debugging('yucardphoto upload error: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }
    }
}

// -------------------------------------------------------------------------
// AJAX: user search endpoint
// -------------------------------------------------------------------------
$ajaxsearch = optional_param('ajaxsearch', '', PARAM_TEXT);
if ($ajaxsearch !== '') {
    // Validate sesskey manually so it works with GET requests.
    if (!confirm_sesskey()) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalidsesskey', 'users' => []]);
        die;
    }

    $searchterm = trim($ajaxsearch);
    if (core_text::strlen($searchterm) < 3) {
        header('Content-Type: application/json');
        echo json_encode(['users' => []]);
        die;
    }

    $term  = '%' . $DB->sql_like_escape($searchterm) . '%';
    $users = $DB->get_records_sql(
        "SELECT id, firstname, lastname, username, idnumber, email,
                firstnamephonetic, lastnamephonetic, middlename, alternatename
           FROM {user}
          WHERE deleted = 0
            AND (" . $DB->sql_like('firstname', ':t1', false) . "
             OR  " . $DB->sql_like('lastname',  ':t2', false) . "
             OR  " . $DB->sql_like('username',  ':t3', false) . "
             OR  " . $DB->sql_like('idnumber',  ':t4', false) . ")
          ORDER BY lastname, firstname",
        ['t1' => $term, 't2' => $term, 't3' => $term, 't4' => $term],
        0, 30
    );

    $results = [];
    foreach ($users as $u) {
        // Look up any existing stored photo for this user (matched by idnumber = sisid).
        $photourl = '';
        if (!empty($u->idnumber)) {
            $rec = $DB->get_field('local_yucardphoto', 'moodle_file_url', ['sisid' => $u->idnumber]);
            if ($rec) {
                $photourl = $rec;
            }
        }
        $identifier = !empty($u->idnumber) ? $u->idnumber : $u->username;
        $results[] = [
            'id'       => (int)$u->id,
            'label'    => fullname($u) . ' (' . $identifier . ')',
            'email'    => $u->email,
            'photourl' => $photourl,
        ];
    }
    header('Content-Type: application/json');
    echo json_encode(['users' => $results]);
    die;
}

// -------------------------------------------------------------------------
// Render — build template context then delegate to Mustache
// -------------------------------------------------------------------------

// Capture the moodleform HTML into a string so it can be passed as a
// template variable (keeps the Moodle form renderer intact).
ob_start();
$form->display();
$formhtml = ob_get_clean();

// ---- Template context ---------------------------------------------------
$templatecontext = [
    'pagetitle'     => get_string('uploadphototitle', 'local_yucardphoto'),
    'hasnotice'     => ($notice !== ''),
    'noticesuccess' => ($noticetype === 'success'),
    'noticemsg'     => s($notice),
    'formhtml'      => $formhtml,   // triple-mustache in template — unescaped
];

// ---- AMD module: user-search autocomplete + photo preview ---------------
$PAGE->requires->js_call_amd('local_yucardphoto/upload', 'init', [[
    'ajaxurl'       => (new moodle_url('/local/yucardphoto/upload.php'))->out(false),
    'sesskey'       => sesskey(),
    'noresult'      => get_string('usernotfound',   'local_yucardphoto'),
    'overridelabel' => get_string('overridephoto',  'local_yucardphoto'),
    'searchbtn'     => get_string('search',         'local_yucardphoto'),
    'minchars'      => get_string('minsearchchars', 'local_yucardphoto'),
    'selectedlabel' => get_string('selectuser',     'local_yucardphoto'),
]]);

// ---- Output -------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_yucardphoto/upload', $templatecontext);
echo $OUTPUT->footer();
