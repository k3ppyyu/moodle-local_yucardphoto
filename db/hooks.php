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
 * Hook callbacks for local_yucardphoto.
 *
 * @package   local_yucardphoto
 * @copyright 2026 ED&IT, York University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    // Inject the "Photo View" button on user/index.php via the before_http_headers hook.
    [
        'hook'     => \core\hook\output\before_http_headers::class,
        'callback' => \local_yucardphoto\hook\output\before_http_headers::class . '::callback',
        'priority' => 500,
    ],
    // Add "Participant Photograph" section to the course edit form.
    [
        'hook'     => \core_course\hook\after_form_definition::class,
        'callback' => \local_yucardphoto\hook\course\after_form_definition::class . '::callback',
        'priority' => 500,
    ],
    // Save the course setting after the form is submitted.
    [
        'hook'     => \core_course\hook\after_form_submission::class,
        'callback' => \local_yucardphoto\hook\course\after_form_submission::class . '::callback',
        'priority' => 500,
    ],
];
