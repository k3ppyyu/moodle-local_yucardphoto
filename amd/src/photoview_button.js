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
 * AMD module: inject the "Photo View" button into the participants page
 * tertiary navigation bar.
 *
 * The button is appended inside [data-region="wrapper"] which is the
 * right-hand side of the tertiary nav rendered by
 * core_renderer::render_participants_tertiary_nav().
 *
 * @module     local_yucardphoto/photoview_button
 * @copyright  2026 ED&IT, York University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Initialise the Photo View button.
 *
 * @param {Object} config
 * @param {string} config.url   URL of the photo roster page.
 * @param {string} config.label Button label text.
 */
export const init = (config) => {
    // Wait for DOM ready.
    document.addEventListener('DOMContentLoaded', () => {
        insertButton(config);
    });

    // Fallback — if DOMContentLoaded already fired (AMD loaded late).
    if (document.readyState === 'interactive' || document.readyState === 'complete') {
        insertButton(config);
    }
};

/**
 * Build and insert the button into the tertiary nav wrapper.
 *
 * @param {Object} config
 */
const insertButton = (config) => {
    // Guard against double-insertion.
    if (document.getElementById('yucardphoto-photoview-btn')) {
        return;
    }

    // The tertiary nav wrapper that already holds the enrol buttons.
    const wrapper = document.querySelector('[data-region="wrapper"]');
    if (!wrapper) {
        return;
    }

    const btn = document.createElement('a');
    btn.id        = 'yucardphoto-photoview-btn';
    btn.href      = config.url;
    btn.className = 'btn btn-secondary ms-2';
    btn.setAttribute('role', 'button');
    btn.textContent = config.label;

    wrapper.appendChild(btn);
};
