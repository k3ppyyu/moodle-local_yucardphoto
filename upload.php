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

require_once('../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/local/yucardphoto/lib.php');

require_login();
$syscontext = context_system::instance();
require_capability('local/yucardphoto:uploadphoto', $syscontext);

// -------------------------------------------------------------------------
// Page setup
// -------------------------------------------------------------------------
$PAGE->set_url(new moodle_url('/local/yucardphoto/upload.php'));
$PAGE->set_context($syscontext);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('uploadphototitle', 'local_yucardphoto'));
$PAGE->set_heading(get_string('uploadphototitle', 'local_yucardphoto'));

// -------------------------------------------------------------------------
// Form definition (inline moodleform)
// -------------------------------------------------------------------------
class yucardphoto_upload_form extends moodleform {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'upload_header', get_string('uploadphoto', 'local_yucardphoto'));

        // User search / select.
        $mform->addElement('text', 'usersearch',
            get_string('searchstudent', 'local_yucardphoto'),
            ['size' => 40, 'placeholder' => 'username, student ID, first or last name']
        );
        $mform->setType('usersearch', PARAM_TEXT);
        $mform->addHelpButton('usersearch', 'searchstudent', 'local_yucardphoto');

        // Selected user display + hidden userid — all in one html block we fully control.
        // We do NOT use addElement('hidden',...) because Moodle's renderer places hidden
        // fields at the bottom of the form with an unpredictable DOM position; instead we
        // embed the hidden input right here so JS can reliably find it by id="ycp-userid".
        $selectlabel = get_string('selectuser', 'local_yucardphoto');
        $mform->addElement('html',
            '<input type="hidden" name="userid" id="ycp-userid" value="0">' .
            '<div class="form-group row fitem mb-3">' .
            '<div class="col-md-3 col-form-label d-flex pb-0 pr-md-0">' .
            '<label class="d-inline word-break">' . s($selectlabel) . '</label>' .
            '</div>' .
            '<div class="col-md-9 form-inline felement">' .
            '<div id="ycp-selected-student" class="alert alert-secondary py-2 mb-0 w-100" ' .
            'style="min-height:2.4rem;">' .
            '<span class="text-muted fst-italic">' . get_string('noneselected', 'local_yucardphoto') . '</span>' .
            '</div>' .
            '</div>' .
            '</div>'
        );
        // Keep a moodleform hidden too so validation picks it up from $data->userid.
        $mform->addElement('hidden', 'userid', 0);
        $mform->setType('userid', PARAM_INT);

        // Photo file upload.
        $mform->addElement('filepicker', 'photofile',
            get_string('choosephoto', 'local_yucardphoto'),
            null,
            ['maxbytes' => 2 * 1024 * 1024, 'accepted_types' => ['.jpg', '.jpeg', '.png']]
        );
        $mform->addHelpButton('photofile', 'choosephoto', 'local_yucardphoto');
        $mform->addRule('photofile', null, 'required', null, 'client');

        $this->add_action_buttons(false, get_string('uploadsubmit', 'local_yucardphoto'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($data['userid'])) {
            $errors['usersearch'] = get_string('usernotfound', 'local_yucardphoto');
        }
        return $errors;
    }
}

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
