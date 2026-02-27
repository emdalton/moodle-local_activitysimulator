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
 * User switcher for the activity simulator.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

namespace local_activitysimulator\simulation;

defined('MOODLE_INTERNAL') || die();

/**
 * Temporarily switches the global $USER to a simulated user.
 *
 * Moodle's forum, quiz, and assignment APIs use the global $USER to determine
 * authorship and to perform capability checks. To create records owned by the
 * correct simulated user, $USER must be switched before calling these APIs.
 *
 * USAGE
 * -----
 * Always wrap in try/finally to guarantee restore() is called even if the
 * API throws an exception:
 *
 *   $switcher = new user_switcher($userid);
 *   try {
 *       forum_add_discussion(...);
 *   } finally {
 *       $switcher->restore();
 *   }
 *
 * Do not nest user_switcher instances. Always restore() before switching
 * to a second user.
 *
 * IMPLEMENTATION
 * --------------
 * Uses \core\session\manager::set_user() rather than assigning $USER
 * directly. This is the supported way to swap $USER in a CLI or task
 * context and correctly updates all session-related state.
 */
class user_switcher {

    /** @var \stdClass The original $USER captured before switching. */
    private \stdClass $original_user;

    /** @var bool Whether restore() has already been called. */
    private bool $restored = false;

    /**
     * Switch $USER to the given simulated user.
     *
     * Fetches the full user record from the database so all fields expected
     * by Moodle APIs (firstname, lastname, email, etc.) are populated.
     *
     * @param  int $userid Moodle user ID to switch to.
     * @throws \coding_exception If the user record does not exist.
     * @throws \dml_exception    On database error.
     */
    public function __construct(int $userid) {
        global $USER;

        $this->original_user = clone $USER;

        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        \core\session\manager::set_user($user);
    }

    /**
     * Restores the original $USER.
     *
     * Safe to call multiple times — subsequent calls after the first are
     * no-ops.
     *
     * @return void
     */
    public function restore(): void {
        if ($this->restored) {
            return;
        }
        \core\session\manager::set_user($this->original_user);
        $this->restored = true;
    }
}
