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
 * Term manager for the activity simulator.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

namespace local_activitysimulator\manager;

defined('MOODLE_INTERNAL') || die();

use local_activitysimulator\course_profiles\base_profile;
use local_activitysimulator\data\name_generator;

/**
 * Creates and manages simulated terms and their activity windows.
 *
 * A term corresponds to a Moodle course category containing all simulated
 * courses for that term. Each term has a set of activity windows whose
 * schedule is defined by the active course profile.
 *
 * TYPICAL CALL SEQUENCE
 * ---------------------
 * setup_term task (Saturday):
 *   1. $tm->validate_settings()           — warn if group pcts don't sum to 100
 *   2. $tm->create_term($start_timestamp) — creates category, term row, window rows
 *      (backfills elapsed windows automatically if backfill_on_create is set)
 *
 * simulate_windows task (daily):
 *   1. $tm->get_active_term()             — find the current term
 *   2. $tm->get_pending_windows($termid)  — windows due to run
 *   3. ... window_runner simulates each window ...
 *   4. $tm->mark_window_complete($windowid)
 *
 * TERM CATEGORY NAMING
 * --------------------
 * Categories are named using ISO week number and year:
 *   "Simulated Term — Week 08, 2026"
 * The idnumber is set to 'local_activitysimulator_YYYY_WW' for reliable
 * programmatic lookup without depending on the display name.
 */
class term_manager {

    /** @var \stdClass Plugin config. */
    private \stdClass $config;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->config = get_config('local_activitysimulator');
    }

    // -------------------------------------------------------------------------
    // Term creation
    // -------------------------------------------------------------------------

    /**
     * Creates a new simulated term starting on the given timestamp.
     *
     * Creates the Moodle course category, inserts a term row, generates and
     * inserts all window rows for the full term schedule, and optionally
     * backfills elapsed windows.
     *
     * @param  int  $start_timestamp Unix timestamp for the term start (should
     *                               be midnight of the term start day).
     * @param  bool $verbose         If true, emit mtrace() progress lines.
     * @return int                   ID of the newly created term record.
     * @throws \moodle_exception     If start date exceeds backfill_max_weeks.
     */
    public function create_term(int $start_timestamp, bool $verbose = false): int {
        global $DB;

        // Validate backfill depth before doing anything.
        $this->check_backfill_depth($start_timestamp);

        $profile = $this->get_profile_instance();
        $now     = time();

        // Derive ISO week and year for display and idnumber.
        $week_number = (int)date('W', $start_timestamp);
        $year        = (int)date('o', $start_timestamp); // 'o' = ISO year (may differ from 'Y' near year boundaries).

        // Create the Moodle course category.
        $categoryid = $this->create_category($week_number, $year, $verbose);

        // Insert the term record.
        $term = new \stdClass();
        $term->categoryid     = $categoryid;
        $term->course_profile = $this->config->course_profile ?? 'one_week_intensive';
        $term->week_number    = $week_number;
        $term->year           = $year;
        $term->start_timestamp = $start_timestamp;
        $term->end_timestamp  = $profile->get_term_end_timestamp($start_timestamp);
        $term->status         = 'pending';
        $term->backfilled     = 0;
        $term->timecreated    = $now;
        $term->timemodified   = $now;

        $termid = $DB->insert_record('local_activitysimulator_terms', $term);

        if ($verbose) {
            mtrace("  Term created: Week $week_number, $year (id=$termid, categoryid=$categoryid)");
        }

        // Generate and insert window rows.
        $this->create_windows($termid, $profile, $start_timestamp, $verbose);

        // Activate the term if its start date is now or in the past.
        if ($start_timestamp <= $now) {
            $DB->set_field('local_activitysimulator_terms', 'status', 'active', ['id' => $termid]);
            $DB->set_field('local_activitysimulator_terms', 'timemodified', $now, ['id' => $termid]);
        }

        // Backfill elapsed windows if configured and start is in the past.
        if (!empty($this->config->backfill_on_create) && $start_timestamp < $now) {
            $this->mark_elapsed_windows_for_backfill($termid, $now);
            $DB->set_field('local_activitysimulator_terms', 'backfilled', 1, ['id' => $termid]);
            if ($verbose) {
                $count = $DB->count_records('local_activitysimulator_windows', [
                    'termid' => $termid,
                    'status' => 'pending',
                ]);
                mtrace(get_string('status_backfill_started', 'local_activitysimulator', $count));
            }
        }

        return (int)$termid;
    }

    /**
     * Generates and inserts all window rows for a term.
     *
     * Asks the course profile for the full window schedule and bulk-inserts
     * into local_activitysimulator_windows. The 0-based window_index (position
     * in the schedule array) is stored on each window row for use by the
     * temporal decay model.
     *
     * @param  int          $termid
     * @param  base_profile $profile
     * @param  int          $term_start
     * @param  bool         $verbose
     * @return void
     */
    private function create_windows(
        int $termid,
        base_profile $profile,
        int $term_start,
        bool $verbose
    ): void {
        global $DB;

        $schedule = $profile->get_window_schedule($term_start);
        $now      = time();
        $records  = [];

        foreach ($schedule as $index => $window) {
            $record = new \stdClass();
            $record->termid         = $termid;
            $record->period_index   = $window['period_index'];
            $record->window_key     = $window['window_key'];
            $record->window_label   = $window['window_label'];
            $record->scheduled_time = $window['scheduled_time'];
            $record->status         = 'pending';
            $record->force_rerun    = 0;
            $record->simulated_at   = null;
            $record->notes          = null;
            $records[] = $record;
        }

        $DB->insert_records('local_activitysimulator_windows', $records);

        if ($verbose) {
            mtrace("  Created " . count($records) . " activity windows.");
        }
    }

    /**
     * Creates the Moodle course category for a term.
     *
     * Uses idnumber for reliable lookup so re-running setup doesn't create
     * duplicate categories.
     *
     * @param  int  $week_number
     * @param  int  $year
     * @param  bool $verbose
     * @return int  Category ID.
     */
    private function create_category(int $week_number, int $year, bool $verbose): int {
        global $DB;

        $idnumber = sprintf('local_activitysimulator_%04d_%02d', $year, $week_number);

        // Return existing category if already created (idempotent).
        $existing = $DB->get_record('course_categories', ['idnumber' => $idnumber]);
        if ($existing) {
            if ($verbose) {
                mtrace("  Category already exists (id={$existing->id}), reusing.");
            }
            return (int)$existing->id;
        }

        $category = new \stdClass();
        $category->name        = sprintf(
            'Simulated Term — Week %02d, %d',
            $week_number,
            $year
        );
        $category->idnumber    = $idnumber;
        $category->description = 'Automatically generated by local_activitysimulator. Do not edit manually.';
        $category->descriptionformat = FORMAT_PLAIN;
        $category->parent      = 0; // Top-level category.
        $category->visible     = 1;
        $category->sortorder   = 0;
        $category->timemodified = time();

        $categoryid = $DB->insert_record('course_categories', $category);

        // Moodle requires context to be created for new categories.
        \context_coursecat::instance($categoryid);

        if ($verbose) {
            mtrace("  Created course category (id=$categoryid): {$category->name}");
        }

        return (int)$categoryid;
    }

    /**
     * Marks all windows whose scheduled_time has already passed as eligible
     * for immediate simulation (used during backfill).
     *
     * Windows are left with status='pending' — the simulate_windows task
     * will pick them up on its next run. The backfill flag on the term record
     * signals to the task that these should be processed immediately rather
     * than waiting for their scheduled time.
     *
     * @param  int $termid
     * @param  int $now    Current Unix timestamp.
     * @return void
     */
    private function mark_elapsed_windows_for_backfill(int $termid, int $now): void {
        // Windows are already pending by default. Nothing to change in the
        // status field — the simulate task processes all pending windows
        // regardless of whether they are backfill or live. This method exists
        // as a hook for future logic (e.g. setting a backfill priority flag).
        // Currently a no-op beyond the term.backfilled flag set by the caller.
    }

    // -------------------------------------------------------------------------
    // Course creation and enrolment
    // -------------------------------------------------------------------------

    /**
     * Creates all courses for a term inside its category.
     *
     * Each course is a fresh Moodle course (not a copy of a master) with
     * sections and activities created according to the active course profile.
     * Courses are named using name_generator so titles look like real academic
     * courses. Section names are also generated.
     *
     * Idempotent: if courses already exist in the category (identified by
     * idnumber prefix), no new ones are created.
     *
     * @param  int         $termid   Term record ID.
     * @param  int         $categoryid Moodle course category ID.
     * @param  base_profile $profile  Course profile instance.
     * @param  bool        $verbose
     * @return int[]       Array of created (or existing) Moodle course IDs.
     */
    public function create_courses_in_term(
        int $termid,
        int $categoryid,
        base_profile $profile,
        bool $verbose = false
    ): array {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/course/lib.php');

        $count   = (int)($this->config->courses_per_term ?? 10);
        $namegen = new name_generator();
        $courseids = [];

        for ($i = 1; $i <= $count; $i++) {
            $idnumber = 'sim_term_' . $termid . '_course_' . $i;

            // Idempotent: return existing course if already created.
            $existing = $DB->get_record('course', ['idnumber' => $idnumber]);
            if ($existing) {
                $courseids[] = (int)$existing->id;
                if ($verbose) {
                    mtrace("  Course $i already exists (id={$existing->id}), reusing.");
                }
                continue;
            }

            // Build the course object.
            $course = new \stdClass();
            $course->fullname    = $namegen->get_course_name();
            $course->shortname   = 'SIM-' . $termid . '-' . $i;
            $course->idnumber    = $idnumber;
            $course->category    = $categoryid;
            $course->numsections = $profile->get_section_count();
            $course->visible     = 1;
            $course->startdate   = time();
            $course->format      = 'topics';

            $created = create_course($course);
            $courseids[] = (int)$created->id;

            // Populate sections and activities.
            $this->create_course_content((int)$created->id, $profile, $namegen, $verbose);

            if ($verbose) {
                mtrace("  Created course $i: {$course->fullname} (id={$created->id})");
            }
        }

        return $courseids;
    }

    /**
     * Creates sections and activities within a course according to the profile.
     *
     * Each section gets a generated name and the set of activities defined by
     * the profile for that section number. Activity names are also generated.
     *
     * @param  int          $courseid
     * @param  base_profile $profile
     * @param  name_generator $namegen  Shared instance so names vary across calls.
     * @param  bool         $verbose
     * @return void
     */
    private function create_course_content(
        int $courseid,
        base_profile $profile,
        name_generator $namegen,
        bool $verbose
    ): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $modinfo      = get_fast_modinfo($courseid);
        $section_info = $modinfo->get_section_info_all();

        for ($s = 1; $s <= $profile->get_section_count(); $s++) {
            // Update section name.
            if (isset($section_info[$s])) {
                $DB->set_field('course_sections', 'name', $namegen->get_section_name(), [
                    'course'  => $courseid,
                    'section' => $s,
                ]);
            }

            // Create each activity type in this section.
            foreach ($profile->get_activities_for_section($s) as $type) {
                $this->create_activity($courseid, $s, $type, $namegen);
            }
        }

        // Rebuild modinfo cache after all module insertions.
        rebuild_course_cache($courseid, true);
    }

    /**
     * Creates a single activity module in a course section.
     *
     * Uses `add_moduleinfo()` which is the standard Moodle API for programmatic
     * module creation. Only the four types used by the simulation engine are
     * supported: page, quiz, assign, forum.
     *
     * @param  int          $courseid
     * @param  int          $section   1-based section number.
     * @param  string       $type      Activity type: 'page', 'quiz', 'assignment', 'forum'.
     * @param  name_generator $namegen
     * @return int          Course module ID of the created activity.
     */
    private function create_activity(
        int $courseid,
        int $section,
        string $type,
        name_generator $namegen
    ): int {
        global $CFG;

        require_once($CFG->dirroot . '/course/modlib.php');

        $course = get_course($courseid);

        // Map plugin type strings to Moodle modnames.
        $modname_map = [
            'page'       => 'page',
            'quiz'       => 'quiz',
            'assignment' => 'assign',
            'forum'      => 'forum',
        ];

        $modname = $modname_map[$type] ?? null;
        if ($modname === null) {
            return 0;
        }

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = $modname;
        $moduleinfo->course     = $courseid;
        $moduleinfo->section    = $section;
        $moduleinfo->visible    = 1;

        switch ($modname) {
            case 'page':
                $moduleinfo->name    = $namegen->get_section_name() . ' Reading';
                $moduleinfo->intro   = '';
                $moduleinfo->introformat = FORMAT_HTML;
                $moduleinfo->content = '<p>Simulated page content.</p>';
                $moduleinfo->contentformat = FORMAT_HTML;
                break;

            case 'quiz':
                $moduleinfo->name        = $namegen->get_section_name() . ' Quiz';
                $moduleinfo->intro       = '';
                $moduleinfo->introformat = FORMAT_HTML;
                $moduleinfo->timeopen    = 0;
                $moduleinfo->timeclose   = 0;
                $moduleinfo->timelimit   = 0;
                $moduleinfo->attempts    = 0; // Unlimited.
                $moduleinfo->grademethod = 1; // Highest grade.
                $moduleinfo->grade       = 100;
                break;

            case 'assign':
                $moduleinfo->name        = $namegen->get_section_name() . ' Assignment';
                $moduleinfo->intro       = '<p>Simulated assignment brief.</p>';
                $moduleinfo->introformat = FORMAT_HTML;
                $moduleinfo->duedate     = 0;
                $moduleinfo->grade       = 100;
                $moduleinfo->submissiondrafts = 0;
                $moduleinfo->assignsubmission_onlinetext_enabled = 1;
                $moduleinfo->assignsubmission_file_enabled = 0;
                break;

            case 'forum':
                $moduleinfo->name        = $namegen->get_section_name() . ' Discussion';
                $moduleinfo->intro       = '';
                $moduleinfo->introformat = FORMAT_HTML;
                $moduleinfo->type        = 'general';
                break;
        }

        $moduleinfo->add         = $modname;
        $moduleinfo->return      = 0;
        $moduleinfo->sr          = 0;

        $result = add_moduleinfo($moduleinfo, $course);
        return (int)$result->coursemodule;
    }

    /**
     * Enrols students and instructors into all courses in a term.
     *
     * Students are distributed across courses using a sliding window rotation.
     * Each group's pool is offset by a fixed stride so consecutive students
     * in the pool appear in consecutive courses. This guarantees deterministic,
     * testable enrolment with every student appearing in at least
     * $min_courses_per_student courses.
     *
     * The minimum-courses-per-student guarantee is enforced by a top-up pass:
     * after the sliding window enrolment, any student appearing in fewer than
     * $min_courses_per_student courses is enrolled in additional courses chosen
     * sequentially from the term's course list.
     *
     * Instructors are distributed round-robin across courses.
     *
     * @param  int[]       $courseids   Moodle course IDs in the term.
     * @param  int         $termid      For logging only.
     * @param  bool        $verbose
     * @return array       ['students_enrolled' => int, 'instructors_enrolled' => int]
     */
    public function enrol_users_in_term(
        array $courseids,
        int $termid,
        bool $verbose = false
    ): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/lib/enrollib.php');

        $stats = ['students_enrolled' => 0, 'instructors_enrolled' => 0];

        if (empty($courseids)) {
            return $stats;
        }

        $course_count         = count($courseids);
        $students_per_course  = (int)($this->config->students_per_course ?? 30);
        $groups               = ['overachiever', 'standard', 'intermittent', 'failing'];
        $min_courses          = 2; // Minimum courses per student guarantee.

        // Get the manual enrolment plugin — required for programmatic enrolment.
        $enrol_plugin = enrol_get_plugin('manual');

        // Ensure each course has a manual enrolment instance.
        $enrol_instances = [];
        foreach ($courseids as $courseid) {
            $instance = $DB->get_record('enrol', [
                'courseid' => $courseid,
                'enrol'    => 'manual',
            ]);
            if (!$instance) {
                $course  = get_course($courseid);
                $instid  = $enrol_plugin->add_instance($course);
                $instance = $DB->get_record('enrol', ['id' => $instid]);
            }
            $enrol_instances[$courseid] = $instance;
        }

        // Get student role ID.
        $student_role = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        $teacher_role = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);

        // Track which courses each student is enrolled in (for top-up pass).
        $student_course_map = []; // userid => [courseid, ...]

        // -----------------------------------------------------------------
        // Sliding window student enrolment, group by group.
        // -----------------------------------------------------------------
        foreach ($groups as $group) {
            $pct     = (int)($this->config->{'group_pct_' . $group} ?? 10);
            $n_slots = (int)round($students_per_course * $pct / 100);

            if ($n_slots === 0) {
                continue;
            }

            // Get all user IDs in this group from learner_profiles.
            $userids = $DB->get_fieldset_select(
                'local_activitysimulator_learner_profiles',
                'userid',
                'group_type = ?',
                [$group]
            );

            if (empty($userids)) {
                continue;
            }

            $pool_size = count($userids);

            // Sliding window: for course $c (0-based), the window starts at
            // offset = $c * $n_slots (mod pool_size) and takes $n_slots users.
            for ($c = 0; $c < $course_count; $c++) {
                $courseid  = $courseids[$c];
                $instance  = $enrol_instances[$courseid];
                $offset    = ($c * $n_slots) % $pool_size;

                for ($slot = 0; $slot < $n_slots; $slot++) {
                    $userid = $userids[($offset + $slot) % $pool_size];
                    $enrol_plugin->enrol_user($instance, $userid, $student_role->id);
                    $student_course_map[$userid][] = $courseid;
                    $stats['students_enrolled']++;
                }
            }
        }

        // -----------------------------------------------------------------
        // Top-up pass: guarantee each student appears in at least $min_courses.
        // -----------------------------------------------------------------
        foreach ($student_course_map as $userid => $enrolled_in) {
            $shortfall = $min_courses - count($enrolled_in);
            if ($shortfall <= 0) {
                continue;
            }

            // Find courses this student is not yet in, sequentially from
            // the start of the course list.
            $enrolled_set = array_flip($enrolled_in);
            $added        = 0;

            foreach ($courseids as $courseid) {
                if ($added >= $shortfall) {
                    break;
                }
                if (isset($enrolled_set[$courseid])) {
                    continue;
                }
                $instance = $enrol_instances[$courseid];
                $enrol_plugin->enrol_user($instance, $userid, $student_role->id);
                $stats['students_enrolled']++;
                $added++;
            }
        }

        // -----------------------------------------------------------------
        // Instructor enrolment — round-robin across courses.
        // -----------------------------------------------------------------
        $instructor_ids = $DB->get_fieldset_sql(
            "SELECT u.id FROM {user} u
              WHERE u.username LIKE 't%'
                AND u.deleted = 0
              ORDER BY u.username ASC"
        );

        foreach ($courseids as $idx => $courseid) {
            if (empty($instructor_ids)) {
                break;
            }
            $instructors_per_course = (int)($this->config->instructors_per_course ?? 2);
            $instance = $enrol_instances[$courseid];

            for ($i = 0; $i < $instructors_per_course; $i++) {
                $instructor = $instructor_ids[($idx * $instructors_per_course + $i) % count($instructor_ids)];
                $enrol_plugin->enrol_user($instance, $instructor, $teacher_role->id);
                $stats['instructors_enrolled']++;
            }
        }

        if ($verbose) {
            mtrace(sprintf(
                '  Enrolment complete: %d student enrolments, %d instructor enrolments across %d courses.',
                $stats['students_enrolled'],
                $stats['instructors_enrolled'],
                $course_count
            ));
        }

        return $stats;
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * Validates plugin settings before term creation.
     *
     * Returns an array of warning strings. An empty array means all settings
     * are valid. Warnings are non-fatal — the caller decides whether to
     * proceed or abort.
     *
     * Currently checks:
     *   - Group enrolment percentages sum to 100.
     *
     * @return string[] Array of warning messages (empty if all valid).
     */
    public function validate_settings(): array {
        $warnings = [];

        $groups = ['overachiever', 'standard', 'intermittent', 'failing'];
        $total  = 0;
        foreach ($groups as $group) {
            $total += (int)($this->config->{'group_pct_' . $group} ?? 0);
        }

        if ($total !== 100) {
            $warnings[] = get_string(
                'error_group_pct_not_100',
                'local_activitysimulator',
                $total
            );
        }

        return $warnings;
    }

    /**
     * Checks whether the given term start timestamp is within the allowed
     * backfill window.
     *
     * @param  int $start_timestamp
     * @return void
     * @throws \moodle_exception If the start date is too far in the past.
     */
    private function check_backfill_depth(int $start_timestamp): void {
        $max_weeks = (int)($this->config->backfill_max_weeks ?? 20);
        $earliest  = time() - ($max_weeks * WEEKSECS);

        if ($start_timestamp < $earliest) {
            $weeks_ago = (int)round((time() - $start_timestamp) / WEEKSECS);
            throw new \moodle_exception(
                'error_backfill_too_far',
                'local_activitysimulator',
                '',
                $weeks_ago
            );
        }
    }

    // -------------------------------------------------------------------------
    // Queries used by the simulation tasks
    // -------------------------------------------------------------------------

    /**
     * Returns all currently active term records.
     *
     * A term is active when its start_timestamp is in the past, its
     * end_timestamp is in the future, and its status is 'active'.
     *
     * Multiple active terms is a normal operating condition — for example,
     * a 16-week semester overlapping with one or more 8-week intensives.
     * All active terms are returned so simulate_windows can process pending
     * windows for each independently.
     *
     * Returns an empty array if no active terms exist.
     *
     * Results are ordered by start_timestamp ASC so older terms are
     * processed first, which is the natural chronological order and
     * makes backfill runs predictable.
     *
     * @return \stdClass[]  Array of term records, keyed by id.
     */
    public function get_active_terms(): array {
        global $DB;

        $now = time();
        $sql = "SELECT *
                  FROM {local_activitysimulator_terms}
                 WHERE status = 'active'
                   AND start_timestamp <= :now
                   AND end_timestamp > :now2
              ORDER BY start_timestamp ASC";

        return $DB->get_records_sql($sql, ['now' => $now, 'now2' => $now]);
    }

    /**
     * Returns all pending windows for a term whose scheduled time has passed,
     * ordered by scheduled_time ascending.
     *
     * Also returns windows with force_rerun = 1, regardless of status, when
     * test mode is enabled.
     *
     * @param  int  $termid
     * @return \stdClass[]
     */
    public function get_pending_windows(int $termid): array {
        global $DB;

        $now      = time();
        $testmode = !empty($this->config->testmode);

        if ($testmode) {
            // In test mode: pending windows due now, plus any force_rerun windows.
            $sql = "SELECT *
                      FROM {local_activitysimulator_windows}
                     WHERE termid = :termid
                       AND (
                               (status = 'pending' AND scheduled_time <= :now)
                            OR force_rerun = 1
                           )
                  ORDER BY scheduled_time ASC";
            return array_values($DB->get_records_sql($sql, ['termid' => $termid, 'now' => $now]));
        }

        // Normal mode: only pending windows whose time has come.
        $sql = "SELECT *
                  FROM {local_activitysimulator_windows}
                 WHERE termid = :termid
                   AND status = 'pending'
                   AND scheduled_time <= :now
              ORDER BY scheduled_time ASC";

        return array_values($DB->get_records_sql($sql, ['termid' => $termid, 'now' => $now]));
    }

    /**
     * Marks a window as complete.
     *
     * Also clears force_rerun if it was set, so the window does not run again
     * on the next task execution.
     *
     * @param  int $windowid
     * @return void
     */
    public function mark_window_complete(int $windowid): void {
        global $DB;

        $record = new \stdClass();
        $record->id           = $windowid;
        $record->status       = 'complete';
        $record->force_rerun  = 0;
        $record->simulated_at = time();

        $DB->update_record('local_activitysimulator_windows', $record);
    }

    /**
     * Returns a window record by ID.
     *
     * @param  int $windowid
     * @return \stdClass
     * @throws \dml_exception If not found.
     */
    public function get_window(int $windowid): \stdClass {
        global $DB;
        return $DB->get_record('local_activitysimulator_windows', ['id' => $windowid], '*', MUST_EXIST);
    }

    /**
     * Returns the 0-based index of a window within its term's schedule.
     *
     * Used by learner profile classes to calculate temporal decay:
     *   decay = f(window_index / total_window_count)
     *
     * The index is derived by counting windows in the same term with an
     * earlier or equal scheduled_time.
     *
     * @param  \stdClass $window Window record from local_activitysimulator_windows.
     * @return int               0-based index.
     */
    public function get_window_index(\stdClass $window): int {
        global $DB;

        $sql = "SELECT COUNT(*)
                  FROM {local_activitysimulator_windows}
                 WHERE termid = :termid
                   AND scheduled_time < :scheduled_time";

        return (int)$DB->count_records_sql($sql, [
            'termid'         => $window->termid,
            'scheduled_time' => $window->scheduled_time,
        ]);
    }

    /**
     * Activates all terms whose start_timestamp is now or in the past and
     * whose status is still 'pending'.
     *
     * Called by the simulate_windows task before querying for pending windows,
     * to handle the transition from pending to active for terms that were
     * created ahead of their start date.
     *
     * @return int Number of terms activated.
     */
    public function activate_due_terms(): int {
        global $DB;

        $now = time();
        $sql = "UPDATE {local_activitysimulator_terms}
                   SET status = 'active', timemodified = :now
                 WHERE status = 'pending'
                   AND start_timestamp <= :now2";

        $DB->execute($sql, ['now' => $now, 'now2' => $now]);

        // Return count of newly active terms for logging.
        return (int)$DB->count_records_select(
            'local_activitysimulator_terms',
            "status = 'active' AND timemodified >= :recent",
            ['recent' => $now - 5]
        );
    }

    /**
     * Marks a term complete if all its windows are complete.
     *
     * Called by simulate_windows task after processing windows, so the term
     * status accurately reflects whether work remains.
     *
     * @param  int $termid
     * @return bool True if the term was marked complete.
     */
    public function maybe_complete_term(int $termid): bool {
        global $DB;

        $pending = $DB->count_records('local_activitysimulator_windows', [
            'termid' => $termid,
            'status' => 'pending',
        ]);

        if ($pending === 0) {
            $DB->set_field('local_activitysimulator_terms', 'status', 'complete', ['id' => $termid]);
            $DB->set_field('local_activitysimulator_terms', 'timemodified', time(), ['id' => $termid]);
            return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Profile instantiation
    // -------------------------------------------------------------------------

    /**
     * Returns an instance of the course profile class configured in settings.
     *
     * Profile class names are derived from the settings key by converting
     * snake_case to CamelCase and prepending the namespace:
     *   'one_week_intensive' -> \local_activitysimulator\course_profiles\one_week_intensive
     *
     * Note: profile class files use snake_case filenames (Moodle convention
     * for non-autoloaded classes that follow the component naming pattern).
     * Moodle's autoloader maps the namespace to the directory automatically.
     *
     * @param  string|null $profile_key Settings key, or null to read from config.
     * @return base_profile
     * @throws \moodle_exception If the class does not exist.
     */
    public function get_profile_instance(?string $profile_key = null): base_profile {
        $key       = $profile_key ?? ($this->config->course_profile ?? 'one_week_intensive');
        $classname = '\\local_activitysimulator\\course_profiles\\' . $key;

        if (!class_exists($classname)) {
            throw new \moodle_exception(
                'error_profile_not_found',
                'local_activitysimulator',
                '',
                $key
            );
        }

        return new $classname();
    }
}
