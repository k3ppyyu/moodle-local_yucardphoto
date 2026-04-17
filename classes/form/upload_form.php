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
 * Moodle form definition for the manual photo upload page.
 *
 * Kept in a separate file so the class is never defined more than once,
 * preventing PEAR / HTML_QuickForm "non-static method called statically"
 * errors that occur when upload.php is included in an already-bootstrapped
 * admin page context.
 *
 * @package   local_yucardphoto
 * @copyright 2026 ED&IT, York University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (!defined('MOODLE_INTERNAL') && !defined('ABORT_AFTER_CONFIG')) {
    // Loaded via require_once from a web page — verify Moodle has bootstrapped.
    if (empty($CFG)) {
        die('Direct access not permitted.');
    }
}

class yucardphoto_upload_form extends moodleform {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'upload_header', get_string('uploadphoto', 'local_yucardphoto'));

        // User search / select — addHelpButton MUST come after addElement.
        $mform->addElement('text', 'usersearch',
            get_string('searchstudent', 'local_yucardphoto'),
            ['size' => 40, 'placeholder' => 'username, student ID, first or last name']
        );
        $mform->setType('usersearch', PARAM_TEXT);
        $mform->addHelpButton('usersearch', 'searchstudent', 'local_yucardphoto');

        // Selected user display + hidden userid.
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

        // Moodle hidden field for form validation.
        $mform->addElement('hidden', 'userid', 0);
        $mform->setType('userid', PARAM_INT);

        // Photo file upload — addElement first, then addHelpButton and addRule.
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
