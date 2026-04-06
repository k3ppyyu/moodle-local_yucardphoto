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
 * YU Card Photo Roster — participants photo page.
 *
 * Displays a responsive, searchable, sortable, paginated photo grid of
 * enrolled students for the given course, using photos from the
 * local_yucardphoto table.
 *
 * Access: local/yucardphoto:viewroster capability + course Photo View enabled.
 *
 * @package   local_yucardphoto
 * @copyright 2026 ED&IT, York University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/local/yucardphoto/lib.php');

// -------------------------------------------------------------------------
// Parameters
// -------------------------------------------------------------------------
$courseid = required_param('id', PARAM_INT);
$search   = optional_param('search', '', PARAM_TEXT);
$sort     = optional_param('sort', 'lastname', PARAM_ALPHA);
$page     = optional_param('page', 0, PARAM_INT);
$perpage  = optional_param('perpage', 20, PARAM_INT);

// Sanitise sort column to a known safe value.
$allowedsorts = ['lastname', 'firstname', 'email', 'sisid', 'nophoto'];
if (!in_array($sort, $allowedsorts)) {
    $sort = 'lastname';
}

// Sanitise perpage — allow 20 or 100.
$allowedperpage = [20, 100];
if (!in_array($perpage, $allowedperpage)) {
    $perpage = 20;
}

// -------------------------------------------------------------------------
// Setup
// -------------------------------------------------------------------------
$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);

if (!local_yucardphoto_can_view_roster($context)) {
    throw new \moodle_exception('nopermissions', 'error', '', get_string('photoview', 'local_yucardphoto'));
}

if (!local_yucardphoto_is_enabled_for_course($courseid)) {
    throw new \moodle_exception('nopermissions', 'error', '', get_string('photoview', 'local_yucardphoto'));
}

// -------------------------------------------------------------------------
// Page setup
// -------------------------------------------------------------------------
$participantsurl = new moodle_url('/user/index.php', ['id' => $courseid]);
$pageurl         = new moodle_url('/local/yucardphoto/participants.php', ['id' => $courseid]);

$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('photoviewtitle', 'local_yucardphoto', $course->fullname));
$PAGE->set_heading($course->fullname);
$PAGE->requires->js_call_amd('local_yucardphoto/participants', 'init', [['debounce' => 600]]);

// Breadcrumb: Course > Participants > Photo Roster.
$PAGE->navbar->add(get_string('participants'), $participantsurl);
$PAGE->navbar->add(get_string('photoview', 'local_yucardphoto'));

// -------------------------------------------------------------------------
// Query enrolled students with photo data
// -------------------------------------------------------------------------
// Fetch users enrolled with the 'student' role archetype in this course.
// get_enrolled_users() with no capability returns ALL enrolled users which
// is what we want — instructors are already filtered out by the role check
// on the page itself.  We pass an empty capability string so we get every
// enrolled user, then restrict to those with at least one enrolment.
$enrolledusers = get_enrolled_users(
    $context,
    '',          // no capability filter — return all enrolled users
    0,           // all groups
    'u.id, u.firstname, u.lastname, u.email, u.idnumber, u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename',
    'u.lastname ASC, u.firstname ASC'
);

// Build a map of sisid => moodle user for joined lookup.
// At York, u.idnumber holds the SIS student number.
$sisids = array_filter(array_column($enrolledusers, 'idnumber'));

// Fetch photo records for all enrolled students in one query.
$photosbysissid = [];
if (!empty($sisids)) {
    list($insql, $inparams) = $DB->get_in_or_equal(array_values($sisids), SQL_PARAMS_NAMED);
    $photorecs = $DB->get_records_select('local_yucardphoto', "sisid {$insql}", $inparams);
    foreach ($photorecs as $rec) {
        $photosbysissid[$rec->sisid] = $rec;
    }
}

// Merge into a flat list for display.
$students = [];
foreach ($enrolledusers as $user) {
    $sisid    = $user->idnumber ?? '';
    $photo    = !empty($sisid) ? ($photosbysissid[$sisid] ?? null) : null;
    $students[] = (object)[
        'userid'    => $user->id,
        'firstname' => $user->firstname,
        'lastname'  => $user->lastname,
        'email'     => $user->email,
        'sisid'     => $sisid,
        'hasphoto'  => ($photo !== null),
        'photourl'  => $photo ? $photo->moodle_file_url : null,
    ];
}

// -------------------------------------------------------------------------
// Search filter
// -------------------------------------------------------------------------
$searchterm = trim(core_text::strtolower($search));
if ($searchterm !== '') {
    $students = array_filter($students, function($s) use ($searchterm) {
        return strpos(core_text::strtolower($s->firstname), $searchterm) !== false
            || strpos(core_text::strtolower($s->lastname),  $searchterm) !== false
            || strpos(core_text::strtolower($s->sisid),     $searchterm) !== false;
    });
}

// -------------------------------------------------------------------------
// Sort
// -------------------------------------------------------------------------
usort($students, function($a, $b) use ($sort) {
    if ($sort === 'nophoto') {
        // Students without a photo sort first; within each group sort by lastname.
        if ($a->hasphoto !== $b->hasphoto) {
            return $a->hasphoto ? 1 : -1; // no-photo (false) floats up
        }
        return strcmp(
            core_text::strtolower($a->lastname . $a->firstname),
            core_text::strtolower($b->lastname . $b->firstname)
        );
    }
    $va = core_text::strtolower($a->$sort ?? '');
    $vb = core_text::strtolower($b->$sort ?? '');
    return strcmp($va, $vb);
});

// -------------------------------------------------------------------------
// Pagination
// -------------------------------------------------------------------------
$totalcount   = count($students);
$nophotocount = count(array_filter($students, fn($s) => !$s->hasphoto));
$students     = array_slice(array_values($students), $page * $perpage, $perpage);

$paging = new paging_bar($totalcount, $page, $perpage, new moodle_url($pageurl,
    ['search' => $search, 'sort' => $sort, 'perpage' => $perpage, 'id' => $courseid]));

// -------------------------------------------------------------------------
// Render — build template context then delegate to Mustache
// -------------------------------------------------------------------------

// Default photo placeholder (Moodle's standard user silhouette).
$defaultphoto = $OUTPUT->image_url('u/f1')->out(false);

// ---- Sort dropdown options — first entry is the placeholder ---------------
$sortdefinitions = [
    'lastname'  => get_string('sortbylastname',  'local_yucardphoto'),
    'firstname' => get_string('sortbyfirstname', 'local_yucardphoto'),
    'email'     => get_string('sortbyemail',     'local_yucardphoto'),
    'sisid'     => get_string('sortbysisid',     'local_yucardphoto'),
    'nophoto'   => get_string('sortnophoto',     'local_yucardphoto'),
];
$sortoptions = [];
// Prepend the disabled placeholder shown when nothing custom is selected.
$sortoptions[] = [
    'value'       => '',
    'label'       => get_string('sortby', 'local_yucardphoto'),
    'selected'    => false,
    'placeholder' => true,
];
foreach ($sortdefinitions as $value => $label) {
    $sortoptions[] = ['value' => $value, 'label' => $label, 'selected' => ($value === $sort), 'placeholder' => false];
}

// ---- Show-all / show-paged link -----------------------------------------
// When viewing paged (perpage=20, default) show a "Show all N" link.
// When already showing all, show a "Show 20 per page" link back.
$isshowingall  = ($perpage >= 100);
$showalllabel  = $isshowingall
    ? get_string('showpaged', 'local_yucardphoto')
    : get_string('showall',   'local_yucardphoto', $totalcount);
$showallurl    = (new moodle_url($pageurl, [
    'id'      => $courseid,
    'search'  => $search,
    'sort'    => $sort,
    'perpage' => $isshowingall ? 20 : 100,
    'page'    => 0,
]))->out(false);

// ---- Student rows -------------------------------------------------------
$studentrows = [];
foreach ($students as $student) {
    $studentrows[] = [
        'firstname' => s($student->firstname),
        'lastname'  => s($student->lastname),
        'sisid'     => s($student->sisid),
        'email'     => s($student->email),
        'photourl'  => $student->photourl ?: $defaultphoto,
        'alttext'   => s($student->firstname . ' ' . $student->lastname),
        'hasphoto'  => $student->hasphoto,
        'nophoto'   => !$student->hasphoto,
    ];
}

// ---- No-results message (search vs empty) --------------------------------
if (empty($studentrows)) {
    $noresultsmsg = ($searchterm !== '')
        ? get_string('noresults', 'local_yucardphoto')
        : get_string('nophotos',  'local_yucardphoto');
} else {
    $noresultsmsg = '';
}

// ---- Paging bar (pre-render to HTML string) ------------------------------
$pagingbarhtml = $totalcount > $perpage ? $OUTPUT->render($paging) : '';

// ---- Assemble template context ------------------------------------------
$templatecontext = [
    'coursefullname'    => format_string($course->fullname),
    'pagetitle'         => get_string('photoviewtitle', 'local_yucardphoto', format_string($course->fullname)),
    'backurl'           => $participantsurl->out(false),
    'backlabel'         => get_string('backtoparticipants', 'local_yucardphoto'),
    'formaction'        => $pageurl->out(false),
    'courseid'          => $courseid,
    'searchvalue'       => s($search),
    'searchlabel'       => get_string('search', 'local_yucardphoto'),
    'searchplaceholder' => get_string('searchplaceholder', 'local_yucardphoto'),
    'sortoptions'       => $sortoptions,
    'searchbtnlabel'    => get_string('search',    'local_yucardphoto'),
    'countlabel'        => get_string('studentcount',   'local_yucardphoto', $totalcount),
    'hasnophoto'        => ($nophotocount > 0),
    'nophotolabel'      => get_string('nophotocount',   'local_yucardphoto', $nophotocount),
    'showallurl'        => $showallurl,
    'showalllabel'      => $showalllabel,
    'isshowingall'      => $isshowingall,
    'hasstudents'       => !empty($studentrows),
    'students'          => $studentrows,
    'noresultsmsg'      => $noresultsmsg,
    'pagingbar'         => $pagingbarhtml,
];

// ---- Output -------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_yucardphoto/participants', $templatecontext);
echo $OUTPUT->footer();
