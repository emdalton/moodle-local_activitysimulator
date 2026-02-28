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
 * CLI tool: run pending simulation windows.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

define('CLI_SCRIPT', true);

// Resolve Moodle root when the plugin is symlinked into Moodle (common in
// development). argv[0] contains the invocation path (preserving symlinks),
// whereas __FILE__ and __DIR__ resolve to the real filesystem path.
// Four dirname() calls: cli/ -> activitysimulator/ -> local/ -> moodle/
$moodle_root = dirname(dirname(dirname(dirname($_SERVER['argv'][0]))));
if (!file_exists($moodle_root . '/config.php')) {
    fwrite(STDERR, "Could not locate Moodle config.php. Expected at: {$moodle_root}/config.php\n");
    fwrite(STDERR, "Invoke this script with an absolute path, e.g.:\n");
    fwrite(STDERR, "  php /Applications/MAMP/htdocs/moodle/local/activitysimulator/cli/run_window.php\n");
    exit(1);
}

require($moodle_root . '/config.php');
require_once($CFG->libdir . '/clilib.php');

// Moodle API libraries required by window_runner, student_actor, and instructor_actor.
// These must be require_once'd here in the CLI entry point — after config.php has
// fully initialised $CFG — rather than in the class files themselves. Class files
// in classes/simulation/ are loaded via PSR-4 autoloader at an indeterminate time
// when $CFG->dirroot may not yet be available, causing require_once to silently
// load nothing and leaving forum_add_discussion() / forum_add_post() undefined.
//
// WARNING: This placement only works for CLI invocation. When window execution is
// moved to a Moodle scheduled task, these require_once calls must be moved inside
// the individual methods that use them (post_announcement(), reply_to_discussion(),
// post_to_forum(), reply_to_forum_discussion()) so they execute after $CFG is
// available regardless of entry point. See README.md — "Known limitations".
require_once($CFG->dirroot . '/lib/enrollib.php');
require_once($CFG->dirroot . '/mod/forum/lib.php');        // forum_add_discussion(), forum_add_new_post().
if (!function_exists('forum_add_new_post')) {
    fwrite(STDERR, "FATAL: forum_add_new_post() not defined after require_once of mod/forum/lib.php\n");
    fwrite(STDERR, "  dirroot: " . $CFG->dirroot . "\n");
    exit(1);
}
require_once($CFG->dirroot . '/mod/assign/locallib.php');  // assign class for submission API.

// Suppress all outbound email during simulation. Simulated users have
// .invalid email domains which cause debug noise on every API action
// that triggers a notification (assignment submissions, forum posts, etc.).
$CFG->noemailever = true;

use local_activitysimulator\manager\term_manager;
use local_activitysimulator\manager\user_manager;
use local_activitysimulator\simulation\window_runner;

// ---------------------------------------------------------------------------
// Option definitions and help text.
// ---------------------------------------------------------------------------

list($options, $unrecognised) = cli_get_params(
    [
        'help'    => false,
        'verbose' => false,
        'term'    => null,
        'window'  => null,
        'limit'   => null,
        'force'   => false,
        'list'    => false,
    ],
    [
        'h' => 'help',
        'v' => 'verbose',
        'l' => 'list',
    ]
);

$help = <<<EOT
Run pending simulation windows for all active terms (or a specific term/window).

Without options, processes all pending windows whose scheduled_time has passed
across every active term — identical to the simulate_windows scheduled task.

The primary debugging workflow is --window + --force + --verbose: target one
window that produced unexpected data, force-rerun it, and watch every student
and instructor action in detail.

Options:
  --term=ID         Process only the term with this database ID. All other
                    active terms are skipped. Use --list to see term IDs.
  --window=ID       Run exactly this one window by database ID, regardless of
                    its current status. Implies --force. Use --list to see
                    window IDs for a term.
  --limit=N         Stop after processing N windows across all terms. Useful
                    for stepping through large backfills and inspecting results
                    between runs.
  --force           Mark targeted window(s) as pending before running, allowing
                    re-simulation of already-completed windows. Without
                    --window, requires testmode to be enabled in plugin settings
                    to prevent accidental mass re-simulation.
  --list            List all active terms and their pending/complete window
                    counts, then exit. Combine with --term=ID to list windows
                    for that specific term.
  --verbose, -v     Emit detailed output including per-student and
                    per-instructor action lines.
  --help, -h        Print this help and exit.

Examples:
  # Run all pending windows (same as the scheduled task):
  php local/activitysimulator/cli/run_window.php

  # List active terms and window status:
  php local/activitysimulator/cli/run_window.php --list

  # List windows for a specific term:
  php local/activitysimulator/cli/run_window.php --list --term=3

  # Run pending windows for one term only:
  php local/activitysimulator/cli/run_window.php --term=3

  # Re-run a specific window with full verbose output (primary debug tool):
  php local/activitysimulator/cli/run_window.php --window=42 --force --verbose

  # Step through a backfill 5 windows at a time:
  php local/activitysimulator/cli/run_window.php --term=3 --limit=5

EOT;

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL . '  ', $unrecognised);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognised));
}

if ($options['help']) {
    echo $help;
    exit(0);
}

$verbose    = (bool)$options['verbose'];
$term_id    = $options['term']   !== null ? (int)$options['term']   : null;
$window_id  = $options['window'] !== null ? (int)$options['window'] : null;
$limit      = $options['limit']  !== null ? (int)$options['limit']  : null;
$force      = (bool)$options['force'];
$list_mode  = (bool)$options['list'];

// --window implies --force (you named the window explicitly; run it).
if ($window_id !== null) {
    $force = true;
}

// ---------------------------------------------------------------------------
// Bootstrap checks.
// ---------------------------------------------------------------------------

$config = get_config('local_activitysimulator');

if (empty($config->enabled)) {
    cli_error('Activity Simulator is disabled. Enable it in Site Administration → Plugins → Local plugins → Activity Simulator.');
}

// Guard --force without --window against accidental mass re-simulation.
if ($force && $window_id === null && empty($config->testmode)) {
    cli_error(
        '--force without --window=ID re-simulates all completed windows. ' .
        'This is only permitted when testmode is enabled in plugin settings. ' .
        'Use --window=ID to target a specific window, or enable testmode.'
    );
}

global $DB;
$tm     = new term_manager();
$um     = new user_manager();

// ---------------------------------------------------------------------------
// --list mode: show terms and window status, then exit.
// ---------------------------------------------------------------------------

if ($list_mode) {
    $tm->activate_due_terms();

    if ($term_id !== null) {
        // List windows for the specific term — no need to check active terms.
        $term = $DB->get_record('local_activitysimulator_terms', ['id' => $term_id]);
        if (!$term) {
            cli_error("No term found with id=$term_id.");
        }

        mtrace(sprintf('Windows for term id=%d (Week %02d, %d — %s) [status: %s]:',
            $term->id, $term->week_number, $term->year, $term->course_profile, $term->status));
        mtrace(str_pad('ID', 6) . str_pad('Label', 30) . str_pad('Scheduled', 20) . str_pad('Status', 12) . 'Force-rerun');
        mtrace(str_repeat('-', 82));

        $windows = $DB->get_records('local_activitysimulator_windows',
            ['termid' => $term_id], 'scheduled_time ASC');

        foreach ($windows as $w) {
            mtrace(sprintf('%s%s%s%s%s',
                str_pad($w->id, 6),
                str_pad($w->window_label, 30),
                str_pad(date('Y-m-d H:i', $w->scheduled_time), 20),
                str_pad($w->status, 12),
                $w->force_rerun ? 'yes' : 'no'
            ));
        }
        exit(0);
    }

    $terms = $tm->get_active_terms();

    if (empty($terms)) {
        mtrace('No active terms found.');
        exit(0);
    }

    // List all active terms.
    mtrace(sprintf('%-6s %-10s %-6s %-24s %-12s %-10s %s',
        'ID', 'Week/Year', 'Prof', 'Category', 'Status', 'Pending', 'Complete'));
    mtrace(str_repeat('-', 88));

    foreach ($terms as $term) {
        $pending  = $DB->count_records('local_activitysimulator_windows',
            ['termid' => $term->id, 'status' => 'pending']);
        $complete = $DB->count_records('local_activitysimulator_windows',
            ['termid' => $term->id, 'status' => 'complete']);
        $category = $DB->get_field('course_categories', 'name', ['id' => $term->categoryid]) ?? '?';

        mtrace(sprintf('%-6d %-10s %-6s %-24s %-12s %-10d %d',
            $term->id,
            sprintf('W%02d/%d', $term->week_number, $term->year),
            substr($term->course_profile, 0, 5),
            substr($category, 0, 23),
            $term->status,
            $pending,
            $complete
        ));
    }

    exit(0);
}

// ---------------------------------------------------------------------------
// --window mode: run exactly one window by ID.
// ---------------------------------------------------------------------------

if ($window_id !== null) {
    $window = $DB->get_record('local_activitysimulator_windows', ['id' => $window_id]);
    if (!$window) {
        cli_error("No window found with id=$window_id.");
    }

    $term = $DB->get_record('local_activitysimulator_terms', ['id' => $window->termid]);
    if (!$term) {
        cli_error("Window id=$window_id references missing term id={$window->termid}.");
    }

    if ($window->status === 'complete') {
        // --force is implied by --window, so proceed; just inform the user.
        mtrace(sprintf(
            'Window id=%d (%s) is already complete — re-running (--force implied by --window).',
            $window->id,
            $window->window_label
        ));
        // Reset to pending so mark_window_complete() has something to update.
        $DB->set_field('local_activitysimulator_windows', 'status', 'pending', ['id' => $window->id]);
    }

    mtrace(sprintf(
        'Running window id=%d: %s (term id=%d, Week %02d %d, scheduled %s)',
        $window->id,
        $window->window_label,
        $term->id,
        $term->week_number,
        $term->year,
        date('Y-m-d H:i', $window->scheduled_time)
    ));

    $runner = new window_runner($tm, $um, $verbose);

    try {
        $stats = $runner->run($window, $term);
        $tm->mark_window_complete($window->id);

        mtrace(sprintf(
            'Done: %d courses, %d student passes, %d log entries.',
            $stats['courses'],
            $stats['students'],
            $stats['entries']
        ));
    } catch (\Throwable $e) {
        cli_error('Window run failed: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    }

    exit(0);
}

// ---------------------------------------------------------------------------
// Normal mode: process pending windows across all (or one) active term(s).
// ---------------------------------------------------------------------------

// Activate pending terms whose start date has passed.
$activated = $tm->activate_due_terms();
if ($activated > 0) {
    mtrace("Activated $activated term(s).");
}

// Get terms to process.
if ($term_id !== null) {
    $term_record = $DB->get_record('local_activitysimulator_terms', ['id' => $term_id]);
    if (!$term_record) {
        cli_error("No term found with id=$term_id.");
    }
    if ($term_record->status !== 'active') {
        mtrace(sprintf('WARNING: Term id=%d has status "%s", not "active". Proceeding anyway.',
            $term_id, $term_record->status));
    }
    $terms = [$term_record->id => $term_record];
} else {
    $terms = $tm->get_active_terms();
}

if (empty($terms)) {
    mtrace('No active terms found. Nothing to do.');
    exit(0);
}

mtrace(sprintf('%d active term(s).', count($terms)));

$runner       = new window_runner($tm, $um, $verbose);
$grand_totals = ['windows' => 0, 'courses' => 0, 'students' => 0, 'entries' => 0];
$limit_remaining = $limit;

foreach ($terms as $term) {
    $term_label = sprintf('Week %02d, %d [%s] (id=%d)',
        $term->week_number, $term->year, $term->course_profile, $term->id);

    mtrace("--- Term: $term_label ---");

    // Get pending windows; if --force, also include completed ones.
    if ($force) {
        // Reset all complete windows to pending so get_pending_windows picks them up.
        $DB->set_field('local_activitysimulator_windows', 'status', 'pending',
            ['termid' => $term->id, 'status' => 'complete']);
        mtrace('  --force: reset completed windows to pending.');
    }

    $windows = $tm->get_pending_windows($term->id);

    if (empty($windows)) {
        mtrace('  No pending windows due.');
        $tm->maybe_complete_term($term->id);
        continue;
    }

    // Apply --limit across terms cumulatively.
    if ($limit_remaining !== null) {
        $windows = array_slice($windows, 0, $limit_remaining, true);
    }

    mtrace(sprintf('  %d window(s) to process%s.',
        count($windows),
        $limit_remaining !== null ? " (limit=$limit_remaining)" : ''
    ));

    $term_totals = ['windows' => 0, 'courses' => 0, 'students' => 0, 'entries' => 0];

    foreach ($windows as $window) {
        if ($verbose) {
            mtrace(sprintf('  Processing: %s (id=%d, scheduled=%s)',
                $window->window_label, $window->id,
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

            mtrace(sprintf('  ✓ %s — %d courses, %d students, %d entries',
                $window->window_label,
                $stats['courses'],
                $stats['students'],
                $stats['entries']
            ));

        } catch (\Throwable $e) {
            mtrace(sprintf('  ✗ ERROR in window "%s" (id=%d): %s',
                $window->window_label, $window->id, $e->getMessage()));
            if ($verbose) {
                mtrace($e->getTraceAsString());
            }
            // Window remains pending for retry.
        }
    }

    // Decrement limit counter.
    if ($limit_remaining !== null) {
        $limit_remaining -= $term_totals['windows'];
    }

    // Mark term complete if all windows are done (only if not limiting).
    if ($limit_remaining === null || $limit_remaining <= 0) {
        if ($tm->maybe_complete_term($term->id)) {
            mtrace("  Term complete: $term_label");
        }
    }

    mtrace(sprintf('  Term summary: %d window(s), %d course(s), %d student passes, %d entries.',
        $term_totals['windows'],
        $term_totals['courses'],
        $term_totals['students'],
        $term_totals['entries']
    ));

    $grand_totals['windows']  += $term_totals['windows'];
    $grand_totals['courses']  += $term_totals['courses'];
    $grand_totals['students'] += $term_totals['students'];
    $grand_totals['entries']  += $term_totals['entries'];

    if ($limit_remaining !== null && $limit_remaining <= 0) {
        mtrace(sprintf('Limit of %d window(s) reached. Stopping.', $limit));
        break;
    }
}

// ---------------------------------------------------------------------------
// Final summary.
// ---------------------------------------------------------------------------

mtrace(sprintf(
    'Done: %d term(s), %d window(s), %d course(s), %d student passes, %d log entries.',
    count($terms),
    $grand_totals['windows'],
    $grand_totals['courses'],
    $grand_totals['students'],
    $grand_totals['entries']
));
