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
 * CLI tool: create the next simulated term (or a specific date term).
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_activitysimulator\manager\term_manager;
use local_activitysimulator\manager\user_manager;

// ---------------------------------------------------------------------------
// Option definitions and help text.
// ---------------------------------------------------------------------------

list($options, $unrecognised) = cli_get_params(
    [
        'help'       => false,
        'verbose'    => false,
        'dry-run'    => false,
        'date'       => null,
        'users-only' => false,
    ],
    [
        'h' => 'help',
        'v' => 'verbose',
        'n' => 'dry-run',
    ]
);

$help = <<<EOT
Create the next simulated term: course category, courses, and enrolments.

By default, calculates the next term start date from the configured
term_start_day setting (e.g. the coming Monday). Use --date to target
a specific date, including past dates for backfill.

Options:
  --date=YYYY-MM-DD   Start the term on this specific date rather than
                      calculating the next scheduled start. Past dates
                      are allowed (backfill). Must be a valid calendar date.
  --users-only        Create/verify the simulated user pool only; do not
                      create a term or courses. Useful after changing pool
                      size settings.
  --dry-run, -n       Validate settings and report what would be created
                      without writing anything to the database.
  --verbose, -v       Emit detailed progress output including per-course
                      and per-manager messages.
  --help, -h          Print this help and exit.

Examples:
  # Create the next scheduled term:
  php local/activitysimulator/cli/setup.php

  # Create a term starting on a specific Monday (backfill):
  php local/activitysimulator/cli/setup.php --date=2026-01-06

  # Dry run to see what would happen:
  php local/activitysimulator/cli/setup.php --date=2026-01-06 --dry-run

  # Verify/create users only:
  php local/activitysimulator/cli/setup.php --users-only --verbose

EOT;

if ($unrecognised) {
    $unrecognised = implode(PHP_EOL . '  ', $unrecognised);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognised));
}

if ($options['help']) {
    echo $help;
    exit(0);
}

$verbose  = (bool)$options['verbose'];
$dry_run  = (bool)$options['dry-run'];
$date_str = $options['date'];
$users_only = (bool)$options['users-only'];

// ---------------------------------------------------------------------------
// Bootstrap checks.
// ---------------------------------------------------------------------------

$config = get_config('local_activitysimulator');

if (empty($config->enabled)) {
    if ($dry_run) {
        mtrace('WARNING: Plugin is disabled. Dry run showing what configuration issues exist.');
    } else {
        cli_error('Activity Simulator is disabled. Enable it in Site Administration → Plugins → Local plugins → Activity Simulator.');
    }
}

if ($dry_run) {
    mtrace('--- DRY RUN — no changes will be written ---');
}

$tm = new term_manager();
$um = new user_manager();

// ---------------------------------------------------------------------------
// Validate settings — always run, even in dry-run.
// ---------------------------------------------------------------------------

mtrace('Validating settings...');
$warnings = $tm->validate_settings();
if (empty($warnings)) {
    mtrace('  Settings OK.');
} else {
    foreach ($warnings as $warning) {
        mtrace('  WARNING: ' . $warning);
    }
}

// ---------------------------------------------------------------------------
// --users-only mode.
// ---------------------------------------------------------------------------

if ($users_only) {
    if ($dry_run) {
        // Count existing users without creating any.
        global $DB;
        $existing = $DB->count_records_select('user',
            "username LIKE 'a%' OR username LIKE 'b%' OR username LIKE 'c%' " .
            "OR username LIKE 'f%' OR username LIKE 't%'"
        );
        $total_expected = (int)($config->group_count_overachiever ?? 50)
            + (int)($config->group_count_standard     ?? 350)
            + (int)($config->group_count_intermittent ?? 50)
            + (int)($config->group_count_failing      ?? 50)
            + (int)($config->instructors_per_course   ?? 2);
        mtrace("Dry run: $existing users exist, $total_expected expected.");
        exit(0);
    }

    mtrace('Ensuring simulated users exist...');
    $stats = $um->ensure_users_exist($verbose);
    mtrace(sprintf(
        'Done: %d created, %d already existed, %d profiles created.',
        $stats['created'],
        $stats['existing'],
        $stats['profiles_created']
    ));
    exit(0);
}

// ---------------------------------------------------------------------------
// Resolve term start timestamp.
// ---------------------------------------------------------------------------

if ($date_str !== null) {
    // Parse --date argument.
    $parsed = \DateTime::createFromFormat('Y-m-d', $date_str);
    if ($parsed === false || $parsed->format('Y-m-d') !== $date_str) {
        cli_error("Invalid date format '$date_str'. Use YYYY-MM-DD (e.g. 2026-01-06).");
    }

    // Resolve timezone.
    $timezone_str = $config->timezone ?? '99';
    if ($timezone_str === '99') {
        $timezone_str = \core_date::get_server_timezone();
    }
    try {
        $tz = new \DateTimeZone($timezone_str);
    } catch (\Exception $e) {
        $tz = new \DateTimeZone(\core_date::get_server_timezone());
    }

    $parsed->setTimezone($tz);
    $parsed->setTime(0, 0, 0);
    $start_timestamp = $parsed->getTimestamp();

    if ($start_timestamp < time()) {
        mtrace(sprintf(
            'WARNING: --date %s is in the past. Term will be created with backdated start.',
            $date_str
        ));
        if (!empty($config->backfill_on_create)) {
            mtrace('  backfill_on_create is ON — elapsed windows will be marked pending for simulation.');
        } else {
            mtrace('  backfill_on_create is OFF — elapsed windows will be skipped.');
        }
    }
} else {
    // Calculate next scheduled term start from settings.
    $start_timestamp = calculate_next_term_start($config);
}

$week_number = (int)date('W', $start_timestamp);
$year        = (int)date('o', $start_timestamp);

mtrace(sprintf(
    'Term start: %s (Week %02d, %d)',
    date('Y-m-d', $start_timestamp),
    $week_number,
    $year
));

// ---------------------------------------------------------------------------
// Check for existing term.
// ---------------------------------------------------------------------------

global $DB;
$existing_term = $DB->get_record('local_activitysimulator_terms', [
    'year'        => $year,
    'week_number' => $week_number,
]);

if ($existing_term) {
    mtrace(sprintf(
        'A term for Week %02d, %d already exists (id=%d, status=%s).',
        $week_number,
        $year,
        $existing_term->id,
        $existing_term->status
    ));
    mtrace('Nothing to do. To re-run simulation on this term, use run_window.php --term=' . $existing_term->id . ' --force.');
    exit(0);
}

// ---------------------------------------------------------------------------
// Dry run: report what would be created and exit.
// ---------------------------------------------------------------------------

if ($dry_run) {
    $courses_per_term = (int)($config->courses_per_term ?? 10);
    $students_per_course = (int)($config->students_per_course ?? 30);
    $instructors_per_course = (int)($config->instructors_per_course ?? 2);
    $profile = $tm->get_profile_instance();

    $total_students = (int)($config->group_count_overachiever ?? 50)
        + (int)($config->group_count_standard     ?? 350)
        + (int)($config->group_count_intermittent ?? 50)
        + (int)($config->group_count_failing      ?? 50);

    $existing_users = $DB->count_records_select('user',
        "username REGEXP '^[abcft][0-9]{3}$' AND deleted = 0"
    );

    mtrace('Dry run summary:');
    mtrace(sprintf('  Term start:         %s (Week %02d, %d)', date('Y-m-d', $start_timestamp), $week_number, $year));
    mtrace(sprintf('  Course profile:     %s', $config->course_profile ?? 'one_week_intensive'));
    mtrace(sprintf('  Courses to create:  %d', $courses_per_term));
    mtrace(sprintf('  Students/course:    %d (%d total enrolments)',
        $students_per_course, $students_per_course * $courses_per_term));
    mtrace(sprintf('  Instructors/course: %d', $instructors_per_course));
    mtrace(sprintf('  User pool size:     %d configured (%d exist in DB)', $total_students, $existing_users));
    mtrace(sprintf('  Activity windows:   %d', $profile->get_total_window_count()));
    mtrace(sprintf('  Backfill on create: %s', !empty($config->backfill_on_create) ? 'yes' : 'no'));
    if (!empty($warnings)) {
        mtrace('  Setting warnings:   ' . count($warnings));
    }
    mtrace('--- DRY RUN complete — nothing was written ---');
    exit(0);
}

// ---------------------------------------------------------------------------
// Ensure users exist.
// ---------------------------------------------------------------------------

mtrace('Ensuring simulated users exist...');
$user_stats = $um->ensure_users_exist($verbose);
mtrace(sprintf(
    '  %d created, %d already existed, %d profiles created.',
    $user_stats['created'],
    $user_stats['existing'],
    $user_stats['profiles_created']
));

// ---------------------------------------------------------------------------
// Create term.
// ---------------------------------------------------------------------------

mtrace('Creating term...');
try {
    $termid = $tm->create_term($start_timestamp, $verbose);
} catch (\moodle_exception $e) {
    cli_error('Failed to create term: ' . $e->getMessage());
}

mtrace(sprintf('  Term created (id=%d).', $termid));

// ---------------------------------------------------------------------------
// Create courses.
// ---------------------------------------------------------------------------

$term    = $DB->get_record('local_activitysimulator_terms', ['id' => $termid], '*', MUST_EXIST);
$profile = $tm->get_profile_instance($term->course_profile);

mtrace('Creating courses...');
$courseids = $tm->create_courses_in_term($termid, $term->categoryid, $profile, $verbose);
mtrace(sprintf('  %d courses ready.', count($courseids)));

// ---------------------------------------------------------------------------
// Enrol users.
// ---------------------------------------------------------------------------

mtrace('Enrolling users...');
$enrol_stats = $tm->enrol_users_in_term($courseids, $termid, $verbose);
mtrace(sprintf(
    '  %d student enrolments, %d instructor enrolments.',
    $enrol_stats['students_enrolled'],
    $enrol_stats['instructors_enrolled']
));

// ---------------------------------------------------------------------------
// Backfill status.
// ---------------------------------------------------------------------------

if (!empty($config->backfill_on_create) && $start_timestamp < time()) {
    $pending = $DB->count_records('local_activitysimulator_windows', [
        'termid' => $termid,
        'status' => 'pending',
    ]);
    mtrace(sprintf('  %d windows marked pending for backfill.', $pending));
    mtrace('  Run: php local/activitysimulator/cli/run_window.php --term=' . $termid . ' --verbose');
}

mtrace(sprintf('Setup complete. Term id=%d, %d courses.', $termid, count($courseids)));

// ---------------------------------------------------------------------------
// Helper: calculate next term start (mirrors setup_term::calculate_next_term_start).
// ---------------------------------------------------------------------------

/**
 * Calculates the Unix timestamp for midnight on the next scheduled term start day.
 *
 * @param  \stdClass $config
 * @return int
 */
function calculate_next_term_start(\stdClass $config): int {
    $term_start_day = (int)($config->term_start_day ?? 1);
    $timezone_str   = $config->timezone ?? '99';

    if ($timezone_str === '99') {
        $timezone_str = \core_date::get_server_timezone();
    }
    try {
        $tz = new \DateTimeZone($timezone_str);
    } catch (\Exception $e) {
        $tz = new \DateTimeZone(\core_date::get_server_timezone());
    }

    $now        = new \DateTime('now', $tz);
    $today      = (int)$now->format('w'); // 0=Sun … 6=Sat.
    $days_ahead = ($term_start_day - $today + 7) % 7;
    if ($days_ahead === 0) {
        $days_ahead = 7;
    }

    $start = clone $now;
    $start->modify("+{$days_ahead} days");
    $start->setTime(0, 0, 0);

    return $start->getTimestamp();
}
