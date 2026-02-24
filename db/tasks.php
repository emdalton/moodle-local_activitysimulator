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
 * Scheduled task definitions for local_activitysimulator.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [

    // -------------------------------------------------------------------------
    // Term setup task.
    // Runs on the configured setup day (default: Saturday afternoon) to create
    // the next term's course category, copy courses from the master, and enrol
    // users. If backfill_on_create is enabled and the term start date is in the
    // past, all elapsed windows are simulated immediately with backdated
    // timestamps.
    //
    // Default schedule: 14:00 every Saturday.
    // Moodle cron must be running for this to fire automatically.
    // Can also be triggered manually via cli/setup.php.
    // -------------------------------------------------------------------------
    [
        'classname'   => '\local_activitysimulator\task\setup_term',
        'blocking'    => 0,
        'minute'      => '0',
        'hour'        => '14',
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '6',   // 0=Sunday, 6=Saturday.
        'disabled'    => 1,     // Disabled until plugin is configured and enabled.
    ],

    // -------------------------------------------------------------------------
    // Simulate windows task.
    // Runs daily to find and process any pending activity windows whose
    // scheduled_time has passed. Writes simulated actions to Moodle's APIs
    // (grades, forum posts, quiz attempts, log entries) with correct backdated
    // timestamps. Skips windows already marked complete unless force_rerun is
    // set (test mode only).
    //
    // Default schedule: 03:00 daily (low-traffic period).
    // Can also be triggered manually via cli/run_window.php.
    // -------------------------------------------------------------------------
    [
        'classname'   => '\local_activitysimulator\task\simulate_windows',
        'blocking'    => 0,
        'minute'      => '0',
        'hour'        => '3',
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
        'disabled'    => 1,     // Disabled until plugin is configured and enabled.
    ],

];
