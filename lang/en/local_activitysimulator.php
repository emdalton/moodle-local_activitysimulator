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
 * English language strings for local_activitysimulator.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

defined('MOODLE_INTERNAL') || die();

// -------------------------------------------------------------------------
// Required by Moodle for all plugins.
// -------------------------------------------------------------------------

$string['pluginname'] = 'Activity Simulator';

// -------------------------------------------------------------------------
// Scheduled task names — displayed in Admin > Server > Scheduled tasks.
// -------------------------------------------------------------------------

$string['task_setup_term']        = 'Activity Simulator: Set up next term';
$string['task_simulate_windows']  = 'Activity Simulator: Simulate pending activity windows';

// -------------------------------------------------------------------------
// Settings page — section headings.
// -------------------------------------------------------------------------

$string['heading_control']    = 'Simulation control';
$string['heading_term']       = 'Term setup';
$string['heading_users']      = 'User population';
$string['heading_groups']     = 'Learner group distribution';
$string['heading_groups_desc'] = 'Percentage of students assigned to each behaviour group. Values must sum to 100. A warning will be shown at term creation time if they do not.';
$string['heading_diligence']      = 'Diligence scalar ranges';
$string['heading_diligence_desc'] = 'Controls the bell-curve distribution of individual engagement levels within each learner group. Each user is assigned a persistent diligence scalar drawn from a truncated normal distribution using these parameters.';
$string['heading_timing']         = 'Activity window timing';
$string['heading_timing_desc']    = 'Defines the time-of-day ranges used when writing backdated log entries. All times are in 24-hour HH:MM format.';

// -------------------------------------------------------------------------
// Settings — simulation control.
// -------------------------------------------------------------------------

$string['enabled']      = 'Enable simulation';
$string['enabled_desc'] = 'When disabled, all scheduled tasks will exit immediately without making any changes. Use this to pause the simulation without uninstalling the plugin.';

$string['testmode']      = 'Test mode';
$string['testmode_desc'] = 'When enabled, completed activity windows can be re-run using the --force flag on CLI tools, and verbose logging is written to the Moodle log. Do not leave this enabled in production.';

// -------------------------------------------------------------------------
// Settings — term setup.
// -------------------------------------------------------------------------

$string['course_profile']      = 'Course profile';
$string['course_profile_desc'] = 'Determines the number of sections, activity types per section, and the activity window schedule for each simulated course. Each profile is a PHP class in classes/course_profiles/.';

$string['profile_one_week_intensive']     = 'One-week intensive (5 sections, 2 windows/day)';
$string['profile_eight_week_accelerated'] = 'Eight-week accelerated (8 sections, 3 windows/week)';
$string['profile_sixteen_week_semester']  = 'Sixteen-week semester (16 sections, 3 windows/week)';

$string['courses_per_term']      = 'Courses per term';
$string['courses_per_term_desc'] = 'Number of courses to create in each term. Each is a copy of the master course for the selected profile.';

$string['term_start_day']      = 'Term start day';
$string['term_start_day_desc'] = 'Day of the week on which each term begins. Defaults to Monday.';

$string['setup_day']      = 'Term setup day';
$string['setup_day_desc'] = 'Day of the week on which the setup task runs to create the next term\'s category, courses, and enrolments. Defaults to Saturday.';

$string['backfill_on_create']      = 'Backfill elapsed windows on term creation';
$string['backfill_on_create_desc'] = 'When a term is created with a start date in the past, immediately simulate all activity windows that have already elapsed, using backdated timestamps. This allows a full semester of realistic data to be generated in minutes. If disabled, only future windows will be simulated on their scheduled dates.';

$string['backfill_max_weeks']      = 'Maximum backfill duration (weeks)';
$string['backfill_max_weeks_desc'] = 'The furthest back (in weeks from today) that a term start date may be set when backfilling. Prevents accidental generation of unreasonably large datasets. Default is 20 weeks.';

// -------------------------------------------------------------------------
// Settings — user population.
// -------------------------------------------------------------------------

$string['students_per_course']      = 'Students per course';
$string['students_per_course_desc'] = 'Number of students enrolled in each simulated course. The total student pool must be large enough to satisfy this across all courses in the term.';

$string['instructors_per_course']      = 'Instructors per course';
$string['instructors_per_course_desc'] = 'Number of instructors assigned to each course. Instructors are distributed across courses so each teaches approximately the same number.';

// -------------------------------------------------------------------------
// Settings — learner group distribution.
// -------------------------------------------------------------------------

$string['group_pct_overachiever']  = 'Overachievers (%)';
$string['group_pct_standard']      = 'Standard (%)';
$string['group_pct_intermittent']  = 'Intermittent (%)';
$string['group_pct_failing']       = 'Failing (%)';
$string['group_pct_desc']          = 'Percentage of the student pool assigned to this group.';

// -------------------------------------------------------------------------
// Settings — diligence scalars.
// -------------------------------------------------------------------------

$string['diligence_mean_overachiever']  = 'Overachiever: mean diligence';
$string['diligence_mean_standard']      = 'Standard: mean diligence';
$string['diligence_mean_intermittent']  = 'Intermittent: mean diligence';
$string['diligence_mean_failing']       = 'Failing: mean diligence';

$string['diligence_stddev_overachiever']  = 'Overachiever: diligence std dev';
$string['diligence_stddev_standard']      = 'Standard: diligence std dev';
$string['diligence_stddev_intermittent']  = 'Intermittent: diligence std dev';
$string['diligence_stddev_failing']       = 'Failing: diligence std dev';

// -------------------------------------------------------------------------
// Settings — activity window timing.
// -------------------------------------------------------------------------

$string['am_window_start']      = 'AM window start';
$string['am_window_start_desc'] = 'Start of the morning activity window in 24-hour HH:MM format. Log entries for AM activities will have timestamps randomly distributed between this time and the AM window end.';

$string['am_window_end']      = 'AM window end';
$string['am_window_end_desc'] = 'End of the morning activity window in 24-hour HH:MM format.';

$string['pm_window_start']      = 'PM window start';
$string['pm_window_start_desc'] = 'Start of the afternoon activity window in 24-hour HH:MM format.';

$string['pm_window_end']      = 'PM window end';
$string['pm_window_end_desc'] = 'End of the afternoon activity window in 24-hour HH:MM format.';

$string['timezone']      = 'Simulation timezone';
$string['timezone_desc'] = 'Timezone used when calculating activity window timestamps. Select "Server timezone" to use the Moodle site timezone, which is recommended for most installations.';

// -------------------------------------------------------------------------
// Error and status messages — used by tasks and CLI tools.
// -------------------------------------------------------------------------

$string['error_plugin_disabled']       = 'Activity Simulator is disabled. Enable it in Site administration > Plugins > Local plugins > Activity Simulator.';
$string['error_group_pct_not_100']     = 'Learner group percentages sum to {$a}%, not 100%. Check the group distribution settings before creating a term.';
$string['error_backfill_too_far']      = 'Term start date is {$a} weeks in the past, which exceeds the maximum backfill duration. Increase the backfill limit or choose a more recent start date.';
$string['error_no_active_term']        = 'No active term found. Run the term setup task or CLI setup tool first.';
$string['error_profile_not_found']     = 'Course profile class not found: {$a}. Check the course_profile setting and ensure the profile class exists in classes/course_profiles/.';

$string['status_term_created']         = 'Term created: {$a}';
$string['status_window_simulated']     = 'Window simulated: {$a}';
$string['status_window_skipped']       = 'Window already complete, skipped: {$a}';
$string['status_window_forced']        = 'Window re-run forced (test mode): {$a}';
$string['status_backfill_started']     = 'Backfill started: {$a} elapsed windows to process.';
$string['status_backfill_complete']    = 'Backfill complete: {$a} windows simulated.';
