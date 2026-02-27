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
 * Database upgrade steps for local_activitysimulator.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the plugin database schema.
 *
 * @param  int  $oldversion Plugin version from mdl_config_plugins.
 * @return bool
 */
function xmldb_local_activitysimulator_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    // -------------------------------------------------------------------------
    // 2026022800 — Refactor run_log for real-API approach.
    //
    // Changes to local_activitysimulator_run_log:
    //
    // 1. Add objectid (int, nullable) — the ID of the real Moodle object
    //    created for this action: forum_posts.id for post_forum/reply_forum/
    //    post_announcement, forum_discussions.id for discussion reads,
    //    assign_submission.id for submit_assignment, quiz_attempts.id for
    //    quiz actions. NULL for view-only actions. Enables JOIN from run_log
    //    to the actual activity data created by the simulation.
    //
    // 2. Widen outcome from LENGTH=16 to LENGTH=32 — accommodates values
    //    like 'score_90_pct', 'submitted_late', 'viewed_only', 'no_reply_target'.
    //
    // 3. Update simulated_time comment only (no structural change) — this
    //    field now documents the target timestamp from the window schedule.
    //    It is stored in run_log for reference but is no longer applied to
    //    any Moodle core table. All Moodle objects receive real wall-clock
    //    timestamps from the event system.
    // -------------------------------------------------------------------------
    if ($oldversion < 2026022800) {

        $table = new xmldb_table('local_activitysimulator_run_log');

        // 1. Add objectid after cmid.
        $field = new xmldb_field('objectid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'cmid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // 2. Widen outcome to LENGTH=32.
        // change_field_precision requires the full field definition.
        $field = new xmldb_field('outcome', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'objectid');
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026022800, 'local', 'activitysimulator');
    }

    return true;
}
