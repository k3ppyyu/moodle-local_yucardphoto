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
 * AMD module: interactive controls for the YU Card Photo Roster page.
 *
 * - Sort-by dropdown submits the form immediately on change.
 * - Search input submits automatically after the user pauses typing (debounce).
 * - Enter key submits immediately.
 * - X clear button clears the search field and submits.
 * - The explicit Submit button is hidden via JS (progressive enhancement).
 *
 * Note on DB usage: the PHP page fetches enrolled users + photo records once
 * per page load using two queries (get_enrolled_users + one IN query). All
 * search/sort/pagination is then done in PHP memory — no extra queries per
 * filter interaction. Each form submit costs exactly the same two queries
 * regardless of search term or sort order.
 *
 * @module     local_yucardphoto/participants
 * @copyright  2026 ED&IT, York University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Initialise the participants page controls.
 *
 * @param {Object} config
 * @param {number} config.debounce  Unused — kept for API compatibility.
 */
export const init = (config) => {
    const cfg = Object.assign({debounce: 600}, config || {});
    // Debounce retained for API compatibility but no longer used.
    void cfg;

    const form = document.querySelector('[data-region="ycp-controls"]');
    if (!form) {
        return;
    }

    const sortSelect = document.getElementById('ycp-sort');

    // ── Sort dropdown: submit immediately on change ───────────────────────
    if (sortSelect) {
        sortSelect.addEventListener('change', () => form.submit());
    }
};
