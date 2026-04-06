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
 * AMD module: user-search autocomplete for the photo upload page.
 *
 * Listens on the usersearch text input, fires a debounced AJAX request to
 * upload.php?ajaxsearch=… and renders a Bootstrap list-group dropdown of
 * matching users below the field.  Selecting a user populates the hidden
 * #id_userid field, shows the user's name in the static "Selected student"
 * field, and fetches + displays any existing photo already stored for that
 * student so the admin can see what they are about to override.
 *
 * @module     local_yucardphoto/upload
 * @copyright  2026 ED&IT, York University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Initialise the upload page autocomplete and photo preview.
 *
 * @param {Object} config
 * @param {string} config.ajaxurl   URL of upload.php (the AJAX endpoint).
 * @param {string} config.sesskey   Moodle session key.
 * @param {string} config.noresult  Localised "no user found" message.
 * @param {string} config.overridelabel Localised "Current photo (will be replaced)" label.
 */
export const init = (config) => {
    // Insert the autocomplete result container immediately after the search input.
    const searchInput = document.getElementById('id_usersearch');
    if (!searchInput) {
        return;
    }

    // Results dropdown container.
    const resultsDiv = document.createElement('div');
    resultsDiv.id = 'ycp-results';
    resultsDiv.style.cssText = 'position:relative;z-index:1050';
    searchInput.insertAdjacentElement('afterend', resultsDiv);

    // Current-photo preview container (inserted after the static selecteduser field).
    const staticField = document.getElementById('id_selecteduser');
    let previewDiv = null;
    if (staticField) {
        previewDiv = document.createElement('div');
        previewDiv.id = 'ycp-photo-preview';
        previewDiv.className = 'mt-2 mb-2';
        staticField.closest('.form-group, .fitem')?.insertAdjacentElement('afterend', previewDiv);
    }

    let timer = null;

    // ---- Keyup handler --------------------------------------------------
    searchInput.addEventListener('input', () => {
        clearTimeout(timer);
        const term = searchInput.value.trim();
        if (term.length < 2) {
            resultsDiv.innerHTML = '';
            return;
        }
        timer = setTimeout(() => fetchUsers(term), 300);
    });

    // ---- Close on outside click -----------------------------------------
    document.addEventListener('click', (e) => {
        if (!e.target.closest('#id_usersearch, #ycp-results')) {
            resultsDiv.innerHTML = '';
        }
    });

    // ---- Fetch users via AJAX -------------------------------------------
    const fetchUsers = (term) => {
        const url = new URL(config.ajaxurl);
        url.searchParams.set('ajaxsearch', term);
        url.searchParams.set('sesskey', config.sesskey);

        fetch(url.toString(), {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(r => r.json())
            .then(data => renderResults(data.users || []))
            .catch(() => {
                resultsDiv.innerHTML = '';
            });
    };

    // ---- Render dropdown list -------------------------------------------
    const renderResults = (users) => {
        resultsDiv.innerHTML = '';
        const ul = document.createElement('ul');
        ul.className = 'list-group mt-1';
        ul.id = 'ycp-ul';

        if (!users.length) {
            const li = document.createElement('li');
            li.className = 'list-group-item text-muted';
            li.textContent = config.noresult;
            ul.appendChild(li);
        } else {
            users.forEach(u => {
                const li = document.createElement('li');
                li.className = 'list-group-item list-group-item-action';
                li.style.cursor = 'pointer';
                li.textContent = u.label;
                li.addEventListener('click', () => selectUser(u));
                ul.appendChild(li);
            });
        }

        resultsDiv.appendChild(ul);
    };

    // ---- Select a user and show current photo preview -------------------
    const selectUser = (u) => {
        // Populate hidden field and visible label.
        document.getElementById('id_userid').value = u.id;
        searchInput.value = u.label;

        const staticEl = document.getElementById('id_selecteduser');
        if (staticEl) {
            staticEl.textContent = u.label + ' \u2014 ' + u.email;
        }

        resultsDiv.innerHTML = '';

        // Show existing photo if available.
        if (previewDiv) {
            if (u.photourl) {
                previewDiv.innerHTML =
                    '<p class="small text-muted mb-1"><strong>' +
                    escHtml(config.overridelabel) +
                    '</strong></p>' +
                    '<img src="' + escHtml(u.photourl) + '" ' +
                    'alt="' + escHtml(u.label) + '" ' +
                    'width="100" height="100" ' +
                    'class="rounded-circle border" style="object-fit:cover;">';
            } else {
                previewDiv.innerHTML = '';
            }
        }
    };

    // ---- Simple HTML escaper --------------------------------------------
    const escHtml = (str) => {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    };
};
