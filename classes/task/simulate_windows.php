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
 * Scheduled task: simulate pending activity windows.
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
use local_activitysimulator\simulation\window_runner;

/**
 * Finds and processes all pending activity windows whose scheduled time
 * has passed, across all active terms.
 *
 * WHEN THIS RUNS
 * --------------
 * Scheduled daily at 03:00 (low-traffic period). Can also be triggered
 * manually via cli/run_window.php (planned). During backfill, this task
 * processes all elapsed windows in a single run — the window loop continues
 * until no pending windows remain for each active term.
 *
 * MULTIPLE ACTIVE TERMS
 * ---------------------
 * Multiple active terms is a normal operating condition. A common example
 * is a 16-week semester overlapping with one or more 8-week intensives.
 * This task processes pending windows for all active terms in every run,
 * iterating over terms in chronological order (oldest start date first).
 * Each term is reported and completed independently — a short intensive
 * that finishes this run is marked complete immediately regardless of
 * whether a longer overlapping term still has windows pending.
 *
 * WHAT IT DOES
 * ------------
 * 1. Checks the plugin is enabled; exits immediately if not.
 * 2. Activates any pending terms whose start date has now passed (one pass,
 *    before the term loop).
 * 3. Gets all active terms. If none exist, logs a message and exits.
 * 4. For each active term:
 *    a. Gets all pending windows whose scheduled_time <= now (plus any with
 *       force_rerun = 1 if test mode is on).
 *    b. For each window, runs window_runner::run(), then marks it complete.
 *       Errors in individual windows are caught and logged — a failing window
 *       is left pending for retry on the next run and does not abort the
 *       remaining windows in this term or in other terms.
 *    c. Checks whether the term is now complete (all windows done) and marks
 *       it accordingly.
 *    d. Reports per-term stats: windows processed, courses touched, student
 *       passes, log entries written.
 * 5. Reports a final aggregate summary across all terms.
 *
 * BACKFILL BEHAVIOUR
 * ------------------
 * When a term was created with a past start date and backfill_on_create is
 * enabled, all elapsed windows will be pending on the first run of this task.
 * The window loop processes them all in sequence, oldest first
 * (get_pending_windows orders by scheduled_time ASC). A full term's worth
 * of data is generated in a single task execution.
 *
 * IDEMPOTENCY
 * -----------
 * Windows already marked 'complete' are not returned by get_pending_windows
 * unless force_rerun = 1 (test mode only). Running this task multiple times
 * is safe in normal mode.
 */
class simulate_windows extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_simulate_windows', 'local_activitysimulator');
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
        mtrace('Activity Simulator: simulate_windows starting.');

        $tm     = new term_manager();
        $um     = new user_manager();
        $runner = new window_runner($tm, $um, $verbose);

        // -----------------------------------------------------------------
        // 2. Activate any pending terms whose start date has passed.
        //    Run once before the term loop so all terms are ready.
        // -----------------------------------------------------------------
        $activated = $tm->activate_due_terms();
        if ($activated > 0) {
            mtrace("  Activated $activated term(s).");
        }

        // -----------------------------------------------------------------
        // 3. Get all active terms.
        // -----------------------------------------------------------------
        $terms = $tm->get_active_terms();

        if (empty($terms)) {
            mtrace(get_string('error_no_active_term', 'local_activitysimulator'));
            return;
        }

        mtrace(sprintf('  %d active term(s) found.', count($terms)));

        // -----------------------------------------------------------------
        // 4. Process each active term independently.
        // -----------------------------------------------------------------
        $grand_totals = ['windows' => 0, 'courses' => 0, 'students' => 0, 'entries' => 0];

        foreach ($terms as $term) {
            $term_label = sprintf('Week %02d, %d [%s] (id=%d)',
                $term->week_number,
                $term->year,
                $term->course_profile,
                $term->id
            );

            mtrace("  --- Term: $term_label ---");

            // 4a. Get pending windows for this term.
            $windows = $tm->get_pending_windows($term->id);

            if (empty($windows)) {
                mtrace('    No pending windows due.');
                $tm->maybe_complete_term($term->id);
                continue;
            }

            mtrace(sprintf('    %d window(s) to process.', count($windows)));

            $term_totals = ['windows' => 0, 'courses' => 0, 'students' => 0, 'entries' => 0];

            // 4b. Process each window.
            foreach ($windows as $window) {
                if ($verbose) {
                    mtrace(sprintf(
                        '    Processing: %s (id=%d, scheduled=%s)',
                        $window->window_label,
                        $window->id,
                        date('Y-m-d H:i', $window->scheduled_time)
                    ));
                }

                try {
                    $stats = $runner->run($window, $term);
                    $tm->mark_window_complete($window->id);

                    $term_totals['windows']++;
                    $term_totals['courses']  += $stats['courses'];
                    $term_totals['students'] += $stats['students'];
                    $term_totals['entries']  += $stats['entries'];

                    mtrace(get_string('status_window_simulated', 'local_activitysimulator',
                        sprintf('%s — %d courses, %d students, %d entries',
                            $window->window_label,
                            $stats['courses'],
                            $stats['students'],
                            $stats['entries']
                        )
                    ));

                } catch (\Throwable $e) {
                    mtrace(sprintf(
                        '    ERROR in window "%s" (id=%d): %s — window left pending for retry.',
                        $window->window_label,
                        $window->id,
                        $e->getMessage()
                    ));
                }
            }

            // 4c. Mark term complete if all windows are done.
            if ($tm->maybe_complete_term($term->id)) {
                mtrace("    Term complete: $term_label");
            }

            // 4d. Per-term summary.
            mtrace(sprintf(
                '    Term summary: %d window(s), %d course(s), %d student passes, %d log entries.',
                $term_totals['windows'],
                $term_totals['courses'],
                $term_totals['students'],
                $term_totals['entries']
            ));

            $grand_totals['windows']  += $term_totals['windows'];
            $grand_totals['courses']  += $term_totals['courses'];
            $grand_totals['students'] += $term_totals['students'];
            $grand_totals['entries']  += $term_totals['entries'];
        }

        // -----------------------------------------------------------------
        // 5. Final aggregate summary across all terms.
        // -----------------------------------------------------------------
        mtrace(sprintf(
            'Activity Simulator: simulate_windows complete. ' .
            '%d term(s), %d window(s), %d course(s), %d student passes, %d log entries.',
            count($terms),
            $grand_totals['windows'],
            $grand_totals['courses'],
            $grand_totals['students'],
            $grand_totals['entries']
        ));
    }
}
