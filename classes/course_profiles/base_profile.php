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
 * Abstract base class for course profiles.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

namespace local_activitysimulator\course_profiles;

defined('MOODLE_INTERNAL') || die();

/**
 * Defines the interface that all course profile classes must implement.
 *
 * A course profile describes the structure of a simulated course and the
 * schedule of activity windows within a term. Each profile is a PHP class
 * in classes/course_profiles/ that extends this base class.
 *
 * ADDING A NEW PROFILE
 * --------------------
 * 1. Create a new class in classes/course_profiles/ extending this base.
 * 2. Implement all abstract methods.
 * 3. Add the profile key and display name to settings.php and the lang file.
 * 4. term_manager::get_profile_instance() will instantiate it automatically
 *    by converting the settings key to a class name.
 *
 * ACTIVITY TYPES
 * --------------
 * The following activity type strings are recognised by student_actor and
 * instructor_actor. Profiles should only return types from this list:
 *
 *   'page'        — Moodle Page resource (passive: view only)
 *   'quiz'        — Moodle Quiz (active: attempt)
 *   'assignment'  — Moodle Assignment (active: submit)
 *   'forum'       — Moodle Forum (active: post/reply; passive: read)
 *
 * WINDOW KEYS
 * -----------
 * Window keys are short strings identifying a slot within a period, e.g.
 * 'am', 'pm', 'early', 'late'. They must be unique within a period. The
 * course profile defines their meaning; term_manager and window_runner
 * treat them as opaque identifiers except when calling get_window_type().
 *
 * PERIOD INDEX
 * ------------
 * Period index is an integer sequence number (1-based) representing an
 * abstract period within the term (e.g. day 1, day 2, week 1, week 2).
 * The profile defines what a period represents. The windows table stores
 * period_index to allow temporal analysis across the term.
 */
abstract class base_profile {

    // -------------------------------------------------------------------------
    // Term structure
    // -------------------------------------------------------------------------

    /**
     * Returns the number of sections in each simulated course.
     *
     * Each section corresponds to one period (day or week depending on
     * profile). Activities are created once per section at course setup time.
     *
     * @return int
     */
    abstract public function get_section_count(): int;

    /**
     * Returns the activity types to create in a given section.
     *
     * Returns an array of activity type strings (see class docblock).
     * In Phase 1 all sections have the same activities, but the section
     * number is passed to allow future profiles to vary by section.
     *
     * @param  int   $section 1-based section number.
     * @return string[]       Array of activity type strings.
     */
    abstract public function get_activities_for_section(int $section): array;

    /**
     * Returns the Unix timestamp at which the term ends.
     *
     * Used by term_manager to populate terms.end_timestamp. Should be
     * calculated from $term_start so that profile logic is self-contained.
     *
     * @param  int $term_start Unix timestamp of the term start (Monday 00:00).
     * @return int
     */
    abstract public function get_term_end_timestamp(int $term_start): int;

    // -------------------------------------------------------------------------
    // Window schedule
    // -------------------------------------------------------------------------

    /**
     * Returns the full window schedule for a term.
     *
     * Called once by term_manager at term creation time. The returned array
     * is bulk-inserted into local_activitysimulator_windows.
     *
     * Each entry must be an associative array with keys:
     *   'period_index'   int     1-based period number within the term.
     *   'window_key'     string  Short identifier, unique within the period
     *                            (e.g. 'am', 'pm').
     *   'window_label'   string  Human-readable label for logs and admin UI
     *                            (e.g. 'Monday AM', 'Week 3 Early').
     *   'scheduled_time' int     Unix timestamp — the target time for this
     *                            window's activities. Used to determine which
     *                            windows are due when the daily task runs, and
     *                            as the base time for backdated log entries.
     *
     * Windows must be returned in chronological order (ascending
     * scheduled_time). term_manager assigns window_index (0-based position
     * in this array) to each row, which the decay model uses later.
     *
     * @param  int   $term_start Unix timestamp of the term start.
     * @return array[]           Array of window definition arrays.
     */
    abstract public function get_window_schedule(int $term_start): array;

    /**
     * Returns the section number that is active for a given window.
     *
     * Called by window_runner to determine which section's activities to
     * scan and simulate. Section numbers are 1-based and must be within
     * the range [1, get_section_count()].
     *
     * @param  int    $period_index Period index from the windows table.
     * @param  string $window_key   Window key from the windows table.
     * @return int                  1-based section number.
     */
    abstract public function get_section_for_window(int $period_index, string $window_key): int;

    /**
     * Returns the total number of windows in a full term.
     *
     * Used by the decay model in learner profile classes to calculate
     * how far through the course a given window falls:
     *   decay = f(window_index / get_total_window_count())
     *
     * Must equal count(get_window_schedule($any_valid_start)).
     *
     * @return int
     */
    abstract public function get_total_window_count(): int;

    // -------------------------------------------------------------------------
    // Window metadata
    // -------------------------------------------------------------------------

    /**
     * Returns the time-of-day type for a given window key.
     *
     * Used by window_runner to look up the correct AM/PM time range from
     * plugin settings when generating backdated timestamps for log entries.
     *
     * Must return one of: 'am', 'pm'.
     * Future profiles may introduce additional window types (e.g. 'evening'),
     * in which case corresponding settings and lang strings should be added.
     *
     * @param  string $window_key Window key (e.g. 'am', 'pm').
     * @return string             Time-of-day type: 'am' or 'pm'.
     */
    abstract public function get_window_type(string $window_key): string;

    // -------------------------------------------------------------------------
    // Shared helpers available to all profile subclasses
    // -------------------------------------------------------------------------

    /**
     * Returns the profile key string used in settings.
     *
     * Derived from the class name by convention: the class name without the
     * namespace prefix, e.g. 'one_week_intensive'. This is used by
     * term_manager::get_profile_instance() to instantiate the correct class.
     *
     * Subclasses do not need to override this — it is derived automatically
     * from the class name.
     *
     * @return string
     */
    public function get_profile_key(): string {
        // Get unqualified class name and convert CamelCase to snake_case.
        $classname = (new \ReflectionClass($this))->getShortName();
        return strtolower(preg_replace('/([A-Z])/', '_$1', lcfirst($classname)));
    }

    /**
     * Builds a Unix timestamp for a specific day offset and time-of-day
     * within a term.
     *
     * Convenience method for use in get_window_schedule() implementations.
     * $day_offset is 0-based from term start (0 = first day of term).
     * $hour and $minute define the target time within that day.
     *
     * @param  int $term_start  Unix timestamp of term start (assumed 00:00).
     * @param  int $day_offset  Days after term start (0-based).
     * @param  int $hour        Hour of day (0–23).
     * @param  int $minute      Minute of hour (0–59).
     * @return int              Unix timestamp.
     */
    protected function day_timestamp(
        int $term_start,
        int $day_offset,
        int $hour,
        int $minute = 0
    ): int {
        return $term_start + ($day_offset * DAYSECS) + ($hour * HOURSECS) + ($minute * 60);
    }

    /**
     * Builds a Unix timestamp for a specific week offset and time-of-day.
     *
     * Convenience method for weekly profiles (8-week, 16-week).
     * $week_offset is 0-based from term start (0 = first week).
     * $day_of_week is 0-based from the term start day (0 = term start day).
     *
     * @param  int $term_start   Unix timestamp of term start.
     * @param  int $week_offset  Weeks after term start (0-based).
     * @param  int $day_of_week  Days within the week (0-based from term start day).
     * @param  int $hour         Hour of day (0–23).
     * @param  int $minute       Minute of hour (0–59).
     * @return int               Unix timestamp.
     */
    protected function week_timestamp(
        int $term_start,
        int $week_offset,
        int $day_of_week,
        int $hour,
        int $minute = 0
    ): int {
        return $term_start
            + ($week_offset * WEEKSECS)
            + ($day_of_week * DAYSECS)
            + ($hour * HOURSECS)
            + ($minute * 60);
    }
}
