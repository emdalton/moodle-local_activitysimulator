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
 * Window runner for the activity simulator.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

namespace local_activitysimulator\simulation;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/enrollib.php');

use local_activitysimulator\manager\term_manager;
use local_activitysimulator\manager\user_manager;
use local_activitysimulator\learner_profiles\base_learner_profile;
use local_activitysimulator\data\name_generator;
use local_activitysimulator\course_profiles\base_profile;

/**
 * Orchestrates the full simulation for a single activity window.
 *
 * Called by the simulate_windows scheduled task once per pending window.
 * Iterates over all courses in the term's category, then over all enrolled
 * users in each course, running student_actor and instructor_actor for each.
 *
 * EXECUTION ORDER WITHIN A WINDOW
 * --------------------------------
 * For each course, the order is:
 *
 * 1. Student pass — all enrolled students run student_actor sequentially.
 *    Students who execute later in the sequence will find more unread forum
 *    posts from peers who ran earlier (the intended behaviour).
 *
 * 2. Instructor pass — instructors read and reply to student forum posts,
 *    grade assignments, and post announcements. Running after students means
 *    instructors see all posts from this window.
 *
 * ANNOUNCEMENT TIMING
 * -------------------
 * Instructor announcements are posted at the end of a window. Students do
 * not see them until the next window — this mirrors common real-world
 * practice where instructors post a wrap-up or preview announcement after
 * the main activity period. The read_announcement roll for students in
 * window N uses the announcement_posted flag from window N-1, which is
 * looked up from the run_log at the start of each window.
 *
 * ENROLMENT QUERIES
 * -----------------
 * Students and instructors are identified by Moodle capability:
 *   Students:    'mod/assign:submit'
 *   Instructors: 'moodle/course:manageactivities'
 *
 * This approach is enrolment-method-agnostic and works correctly regardless
 * of how setup_term created the enrolments.
 *
 * INSTRUCTOR PROFILE
 * ------------------
 * Phase 1 uses 'responsive' for all instructors. The instructor profile type
 * will be stored per-user in a future phase when instructor profile classes
 * are fully implemented.
 *
 * WINDOW INDEX
 * ------------
 * The 0-based window index (position within the term schedule) is fetched
 * once per window from term_manager and passed to all actors. It is used
 * by learner profiles for temporal decay calculations.
 *
 * ERROR HANDLING
 * --------------
 * Errors in individual user simulations are caught and logged via mtrace()
 * rather than allowed to abort the entire window. A window with one failing
 * user still completes successfully for all other users.
 */
class window_runner {

    /** @var string Instructor profile type for Phase 1. */
    const INSTRUCTOR_PROFILE = 'responsive';

    /** @var term_manager */
    private term_manager $term_manager;

    /** @var user_manager */
    private user_manager $user_manager;

    /** @var name_generator Shared instance across all actors in this run. */
    private name_generator $namegen;

    /** @var bool Emit mtrace() progress lines. */
    private bool $verbose;

    /**
     * Constructor.
     *
     * @param term_manager $term_manager
     * @param user_manager $user_manager
     * @param bool         $verbose
     */
    public function __construct(
        term_manager $term_manager,
        user_manager $user_manager,
        bool $verbose = false
    ) {
        $this->term_manager = $term_manager;
        $this->user_manager = $user_manager;
        $this->namegen      = new name_generator();
        $this->verbose      = $verbose;
    }

    /**
     * Runs the simulation for a single window.
     *
     * @param  \stdClass $window  Window record from local_activitysimulator_windows.
     * @param  \stdClass $term    Term record from local_activitysimulator_terms.
     * @return array     ['courses' => int, 'students' => int, 'entries' => int]
     */
    public function run(\stdClass $window, \stdClass $term): array {
        global $DB;

        $stats = ['courses' => 0, 'students' => 0, 'entries' => 0];

        // Get course profile instance and window metadata.
        $profile       = $this->term_manager->get_profile_instance($term->course_profile);
        $window_index  = $this->term_manager->get_window_index($window);
        $window_type   = $profile->get_window_type($window->window_key);
        $section       = $profile->get_section_for_window($window->period_index, $window->window_key);
        $total_windows = $profile->get_total_window_count();

        if ($this->verbose) {
            mtrace("  Window: {$window->window_label} (index=$window_index, section=$section, type=$window_type)");
        }

        // Get all courses in this term's category.
        $courses = $DB->get_records('course', ['category' => $term->categoryid]);

        if (empty($courses)) {
            mtrace("  Warning: no courses found in category {$term->categoryid} for term {$term->id}.");
            return $stats;
        }

        foreach ($courses as $course) {
            $entries = $this->run_course(
                $course,
                $window,
                $window_index,
                $window_type,
                $section,
                $total_windows,
                $stats
            );
            $stats['entries'] += $entries;
            $stats['courses']++;
        }

        if ($this->verbose) {
            mtrace(sprintf(
                "  Window complete: %d courses, %d students, %d log entries.",
                $stats['courses'],
                $stats['students'],
                $stats['entries']
            ));
        }

        return $stats;
    }

    // -------------------------------------------------------------------------
    // Private: per-course simulation
    // -------------------------------------------------------------------------

    /**
     * Runs the simulation for one course within a window.
     *
     * Order: students first, then instructors. Instructors see all student
     * forum posts from this window. Instructor announcements are visible to
     * students in the next window via the forum_read table.
     *
     * @param  \stdClass $course
     * @param  \stdClass $window
     * @param  int       $window_index
     * @param  string    $window_type
     * @param  int       $section
     * @param  int       $total_windows
     * @param  array     &$stats         Running stats, updated in place.
     * @return int       Log entries written for this course.
     */
    private function run_course(
        \stdClass $course,
        \stdClass $window,
        int $window_index,
        string $window_type,
        int $section,
        int $total_windows,
        array &$stats
    ): int {
        $courseid = (int)$course->id;
        $context  = \context_course::instance($courseid);
        $written  = 0;

        $scanner    = new content_scanner($courseid);
        $log_writer = new log_writer($window, $window_type, $window_index);

        $activities = $scanner->get_activities_in_section($section);
        if (empty($activities)) {
            if ($this->verbose) {
                mtrace("    Course $courseid: section $section empty, skipping.");
            }
            return 0;
        }

        $students    = $this->get_enrolled_students($context);
        $instructors = $this->get_enrolled_instructors($context);

        if (empty($students)) {
            if ($this->verbose) {
                mtrace("    Course $courseid: no enrolled students, skipping.");
            }
            return 0;
        }

        $student_actor    = new student_actor($log_writer, $scanner, $this->namegen, $total_windows, $this->verbose);
        $instructor_actor = new instructor_actor($log_writer, $scanner, $this->namegen, $total_windows, $this->verbose);

        // --- Pass 1: Students ---
        foreach ($students as $student) {
            $userid = (int)$student->id;

            try {
                $profile = base_learner_profile::for_user($userid);
                if ($profile === null) {
                    continue;
                }

                $entries = $student_actor->simulate(
                    $userid,
                    $courseid,
                    $section,
                    $window_index,
                    $profile
                );

                $written += $entries;
                $stats['students']++;

            } catch (\Throwable $e) {
                mtrace("    Error simulating student $userid in course $courseid: " . $e->getMessage());
            }
        }

        // --- Pass 2: Instructors ---
        // Runs after students — instructors see all forum posts from this window.
        // Announcements posted here become unread posts in the forum_read table,
        // visible to students when they next log in (i.e. the next window).
        foreach ($instructors as $instructor) {
            try {
                $result = $instructor_actor->simulate(
                    (int)$instructor->id,
                    $courseid,
                    $section,
                    $window_index,
                    self::INSTRUCTOR_PROFILE
                );
                $written += $result->written;

            } catch (\Throwable $e) {
                mtrace("    Error simulating instructor {$instructor->id} in course $courseid: " . $e->getMessage());
            }
        }

        if ($this->verbose) {
            mtrace("    Course $courseid: " . count($students) . " students, $written entries.");
        }

        return $written;
    }

    // -------------------------------------------------------------------------
    // Private: enrolment queries
    // -------------------------------------------------------------------------

    /**
     * Returns enrolled users with the student capability in the given context.
     *
     * @param  \context_course $context
     * @return \stdClass[]     Array of user records (id field populated).
     */
    private function get_enrolled_students(\context_course $context): array {
        return array_values(get_enrolled_users(
            $context,
            'mod/assign:submit',
            0,
            'u.id',
            null,
            0,
            0,
            true  // Include only active enrolments.
        ));
    }

    /**
     * Returns enrolled users with the instructor capability in the given context.
     *
     * @param  \context_course $context
     * @return \stdClass[]
     */
    private function get_enrolled_instructors(\context_course $context): array {
        return array_values(get_enrolled_users(
            $context,
            'moodle/course:manageactivities',
            0,
            'u.id',
            null,
            0,
            0,
            true
        ));
    }
}
