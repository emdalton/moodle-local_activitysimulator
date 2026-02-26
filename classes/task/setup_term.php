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
 * Scheduled task: set up next term.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

namespace local_activitysimulator\task;

defined('MOODLE_INTERNAL') || die();

use local_activitysimulator\manager\term_manager;
use local_activitysimulator\manager\user_manager;

/**
 * Creates the next term's category, courses, and enrolments.
 *
 * WHEN THIS RUNS
 * --------------
 * The task is scheduled to run on the configured setup day (default: Saturday
 * at 14:00). It checks whether a term already exists for the upcoming start
 * date and exits silently if so, making repeated runs idempotent.
 *
 * WHAT IT DOES
 * ------------
 * 1. Checks that the plugin is enabled; exits immediately if not.
 * 2. Validates settings (warns if group percentages don't sum to 100).
 * 3. Ensures all simulated users exist (user_manager::ensure_users_exist).
 *    This is idempotent — already-created users are not modified.
 * 4. Calculates the start date of the next term. The next term starts on
 *    the nearest upcoming occurrence of the configured term_start_day
 *    (default: Monday). "Next" means the Monday after the current setup day.
 * 5. Checks whether a term already exists for that week. If one exists,
 *    logs a message and exits.
 * 6. Creates the term: category, term DB row, and window rows.
 * 7. Creates courses inside the term category.
 * 8. Enrols students and instructors into courses.
 * 9. If backfill_on_create is enabled and the term start date is in the
 *    past (which can happen when backfilling or during testing), the
 *    simulate_windows task will pick up the elapsed windows on its next run.
 *
 * TERM START DATE CALCULATION
 * ---------------------------
 * The task runs on setup_day (e.g. Saturday). The next term starts on
 * term_start_day (e.g. Monday). The gap between them is calculated as:
 *
 *   days_until_start = (term_start_day - setup_day + 7) % 7
 *   if days_until_start == 0: days_until_start = 7  (next occurrence, not today)
 *
 * For Saturday setup → Monday start: (1 - 6 + 7) % 7 = 2 days ahead.
 * The start timestamp is set to midnight (00:00) on that day in the
 * simulation timezone.
 *
 * IDEMPOTENCY
 * -----------
 * The task is safe to run multiple times. Duplicate prevention is achieved
 * by checking for an existing term with the same ISO year and week number.
 * Course and user creation are also idempotent (idnumber-based lookup).
 */
class setup_term extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_setup_term', 'local_activitysimulator');
    }

    public function execute() {
        $config = get_config('local_activitysimulator');

        // -----------------------------------------------------------------
        // 1. Check plugin is enabled.
        // -----------------------------------------------------------------
        if (empty($config->enabled)) {
            mtrace(get_string('error_plugin_disabled', 'local_activitysimulator'));
            return;
        }

        $verbose = !empty($config->testmode);
        mtrace('Activity Simulator: setup_term starting.');

        $tm = new term_manager();
        $um = new user_manager();

        // -----------------------------------------------------------------
        // 2. Validate settings.
        // -----------------------------------------------------------------
        $warnings = $tm->validate_settings();
        foreach ($warnings as $warning) {
            mtrace('  WARNING: ' . $warning);
        }

        // -----------------------------------------------------------------
        // 3. Ensure users exist.
        // -----------------------------------------------------------------
        mtrace('  Ensuring simulated users exist...');
        $user_stats = $um->ensure_users_exist($verbose);
        mtrace(sprintf(
            '  Users: %d created, %d already existed, %d profiles created.',
            $user_stats['created'],
            $user_stats['existing'],
            $user_stats['profiles_created']
        ));

        // -----------------------------------------------------------------
        // 4. Calculate next term start date.
        // -----------------------------------------------------------------
        $start_timestamp = $this->calculate_next_term_start($config);
        $week_number     = (int)date('W', $start_timestamp);
        $year            = (int)date('o', $start_timestamp);

        mtrace(sprintf(
            '  Next term start: %s (Week %02d, %d)',
            date('Y-m-d', $start_timestamp),
            $week_number,
            $year
        ));

        // -----------------------------------------------------------------
        // 5. Check for existing term this week.
        // -----------------------------------------------------------------
        global $DB;
        $existing = $DB->get_record('local_activitysimulator_terms', [
            'year'        => $year,
            'week_number' => $week_number,
        ]);

        if ($existing) {
            mtrace(sprintf(
                '  Term for Week %02d, %d already exists (id=%d, status=%s). Nothing to do.',
                $week_number,
                $year,
                $existing->id,
                $existing->status
            ));
            return;
        }

        // -----------------------------------------------------------------
        // 6. Create term (category + DB row + window rows).
        // -----------------------------------------------------------------
        mtrace('  Creating term...');
        try {
            $termid = $tm->create_term($start_timestamp, $verbose);
        } catch (\moodle_exception $e) {
            mtrace('  ERROR creating term: ' . $e->getMessage());
            return;
        }

        mtrace(get_string('status_term_created', 'local_activitysimulator',
            sprintf('Week %02d, %d (id=%d)', $week_number, $year, $termid)
        ));

        // -----------------------------------------------------------------
        // 7. Create courses inside the term category.
        // -----------------------------------------------------------------
        $term    = $DB->get_record('local_activitysimulator_terms', ['id' => $termid], '*', MUST_EXIST);
        $profile = $tm->get_profile_instance($term->course_profile);

        mtrace('  Creating courses...');
        $courseids = $tm->create_courses_in_term($termid, $term->categoryid, $profile, $verbose);
        mtrace(sprintf('  %d courses ready.', count($courseids)));

        // -----------------------------------------------------------------
        // 8. Enrol students and instructors.
        // -----------------------------------------------------------------
        mtrace('  Enrolling users...');
        $enrol_stats = $tm->enrol_users_in_term($courseids, $termid, $verbose);
        mtrace(sprintf(
            '  Enrolment complete: %d student enrolments, %d instructor enrolments.',
            $enrol_stats['students_enrolled'],
            $enrol_stats['instructors_enrolled']
        ));

        // -----------------------------------------------------------------
        // 9. Report backfill status.
        // -----------------------------------------------------------------
        if (!empty($config->backfill_on_create) && $start_timestamp < time()) {
            $pending = $DB->count_records('local_activitysimulator_windows', [
                'termid' => $termid,
                'status' => 'pending',
            ]);
            mtrace(get_string('status_backfill_started', 'local_activitysimulator', $pending));
            mtrace('  Run the simulate_windows task to process backfill windows.');
        }

        mtrace('Activity Simulator: setup_term complete.');
    }

    // -------------------------------------------------------------------------
    // Private: term start date calculation
    // -------------------------------------------------------------------------

    /**
     * Calculates the Unix timestamp for the start of the next term.
     *
     * The start date is the next upcoming occurrence of the configured
     * term_start_day after today. "Next" is always at least 1 day ahead —
     * if today is the term_start_day, the following week is used. This
     * ensures the task running on setup_day (e.g. Saturday) always targets
     * the term that begins on the next term_start_day (e.g. Monday).
     *
     * The timestamp is midnight (00:00:00) in the configured simulation
     * timezone on the calculated date.
     *
     * @param  \stdClass $config Plugin config.
     * @return int       Unix timestamp.
     */
    private function calculate_next_term_start(\stdClass $config): int {
        $term_start_day = (int)($config->term_start_day ?? 1); // Default: Monday.
        $timezone_str   = $config->timezone ?? '99';

        // Resolve timezone: '99' means use the Moodle site timezone.
        if ($timezone_str === '99') {
            $timezone_str = \core_date::get_server_timezone();
        }

        try {
            $tz = new \DateTimeZone($timezone_str);
        } catch (\Exception $e) {
            $tz = new \DateTimeZone(\core_date::get_server_timezone());
        }

        $now   = new \DateTime('now', $tz);
        $today = (int)$now->format('w'); // PHP w: 0=Sun … 6=Sat. Matches Moodle calendar.

        // Calculate days until next occurrence of term_start_day.
        // term_start_day in settings: 0=Sun, 1=Mon … 6=Sat (matches Moodle calendar).
        $days_ahead = ($term_start_day - $today + 7) % 7;
        if ($days_ahead === 0) {
            $days_ahead = 7; // Never start on the same day the task runs.
        }

        $start = clone $now;
        $start->modify("+{$days_ahead} days");
        $start->setTime(0, 0, 0); // Midnight.

        return $start->getTimestamp();
    }
}
