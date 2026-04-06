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
 * - Sort-by and Per-page dropdowns submit the form immediately on change.
 * - Search input submits automatically after the user pauses typing (keyup debounce).
 * - The explicit Submit button is hidden via JS (progressive enhancement).
 *
 * @module     local_yucardphoto/participants
 * @copyright  2026 ED&IT, York University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Initialise the participants page controls.
 *
 * @param {Object} config
 * @param {number} config.debounce  Milliseconds to wait after last keyup before submitting (default 500).
 */
export const init = (config) => {
    const cfg = Object.assign({debounce: 500}, config || {});

    const form = document.querySelector('[data-region="ycp-controls"]');
    if (!form) {
        return;
    }

    const searchInput = document.getElementById('ycp-search');
    const sortSelect = document.getElementById('ycp-sort');
    const submitBtn = form.querySelector('button[type="submit"]');

    // ── Hide the explicit submit button — sort change and keyup handle submission ──
    if (submitBtn) {
        submitBtn.style.display = 'none';
    }

    // ── Sort dropdown: submit immediately on change ───────────────────────
    if (sortSelect) {
        sortSelect.addEventListener('change', () => form.submit());
    }


    // ── Search input: debounced keyup submit ──────────────────────────────
    if (searchInput) {
        let timer = null;
        searchInput.addEventListener('keyup', (e) => {
            // Submit immediately on Enter, otherwise debounce.
            if (e.key === 'Enter') {
                clearTimeout(timer);
                form.submit();
                return;
            }
            clearTimeout(timer);
            timer = setTimeout(() => form.submit(), cfg.debounce);
        });
    }
};
