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
 * Hiding and un-hiding teacher notes on the course page.
 *
 * Hiding does not remove the note from the page it was clicked on. The server has already recorded
 * the choice, so the note is gone on the next page load; showing that immediately would make an
 * accidental click hard to recover from. Instead the body is swapped for a line saying what will
 * happen, with an undo.
 *
 * @module     mod_ednote/note
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';
import Notification from 'core/notification';
import Pending from 'core/pending';

const SELECTORS = {
    NOTE: '[data-region="ednote"]',
    LIVE: '[data-region="ednote-live"]',
    DISMISSED: '[data-region="ednote-dismissed"]',
    HIDE: '[data-action="ednote-hide"]',
    UNDO: '[data-action="ednote-undo"]',
    HIDDENMARKER: '.ednote-is-hidden',
};

/**
 * Record a hide or unhide against the current user.
 *
 * @param {number} cmid The note.
 * @param {string} scope hiddennote or hiddenguidance.
 * @param {boolean} hidden Whether it should be hidden.
 * @returns {Promise} Resolves when the server has stored the choice.
 */
const setHidden = (cmid, scope, hidden) => fetchMany([{
    methodname: 'mod_ednote_set_hidden',
    args: {cmid, scope, hidden},
}])[0];

/**
 * Swap a note between its guidance and the "this is hidden" confirmation.
 *
 * The guidance and its hide links share one region, so a single toggle covers both - there is no
 * state in which the note is dismissed but still offering to dismiss itself.
 *
 * @param {Element} note The note root.
 * @param {boolean} dismissed Whether to show the confirmation.
 */
const showDismissed = (note, dismissed) => {
    const live = note.querySelector(SELECTORS.LIVE);
    const message = note.querySelector(SELECTORS.DISMISSED);

    if (live) {
        live.toggleAttribute('hidden', dismissed);
    }
    if (message) {
        message.toggleAttribute('hidden', !dismissed);
    }
};

/**
 * Remove any note the server has already marked as hidden.
 *
 * The CSS rule on li.modtype_ednote:has(.ednote-is-hidden) does this without us, and does it before
 * first paint. This is only here for browsers without :has(), where the alternative is an empty
 * activity card with no explanation.
 */
const removeHiddenNotes = () => {
    document.querySelectorAll(SELECTORS.HIDDENMARKER).forEach((marker) => {
        const wrapper = marker.closest('li.modtype_ednote');
        if (wrapper) {
            wrapper.remove();
        }
    });
};

/**
 * Record the choice and swap the card over.
 *
 * Written with try/catch rather than a promise chain because core/ajax hands back a jQuery
 * Deferred, not a native promise: it has .then(), .catch() and .always(), but no .finally(), and
 * calling one leaves the Pending unresolved and the page permanently "not ready".
 *
 * @param {Element} note The note root.
 * @param {number} cmid The note's course module id.
 * @param {string} scope hiddennote or hiddenguidance.
 * @param {boolean} hidden Whether it should be hidden.
 */
const applyHidden = async(note, cmid, scope, hidden) => {
    const pending = new Pending('mod_ednote/note:sethidden');

    try {
        // The server decides the scope actually applied: asking to hide the guidance of a note
        // that has no preset falls back to hiding just that note, and undo has to reverse what was
        // stored rather than what was clicked.
        const result = await setHidden(cmid, scope, hidden);
        note.dataset.appliedscope = result.scope;
        showDismissed(note, hidden);
    } catch (error) {
        Notification.exception(error);
    }

    pending.resolve();
};

/**
 * Wire up the course page.
 */
export const init = () => {
    removeHiddenNotes();

    document.addEventListener('click', (event) => {
        const hide = event.target.closest(SELECTORS.HIDE);
        const undo = event.target.closest(SELECTORS.UNDO);

        if (!hide && !undo) {
            return;
        }

        const note = event.target.closest(SELECTORS.NOTE);
        if (!note) {
            return;
        }

        // Both controls work without JavaScript - the menu items are real links to hidden.php, and
        // that is where an unhandled click would go. Only take over once we know we can finish.
        event.preventDefault();

        const cmid = parseInt(note.dataset.cmid, 10);
        const scope = hide ? hide.dataset.scope : note.dataset.appliedscope;

        applyHidden(note, cmid, scope, !!hide);
    });
};
