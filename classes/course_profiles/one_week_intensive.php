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
 * One-week intensive course profile.
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
 * Course profile for a one-week intensive course.
 *
 * STRUCTURE
 * ---------
 * - 5 sections, one per weekday (Monday–Friday)
 * - Each section contains: page, quiz, assignment, forum
 * - 10 activity windows: AM and PM for each of the 5 days
 * - The course also has a top-level Announcements forum (not section-specific;
 *   managed by setup_term, not content_scanner)
 *
 * WINDOW SCHEDULE
 * ---------------
 * period_index = weekday number (1=Monday … 5=Friday)
 * window_key   = 'am' or 'pm'
 *
 * Scheduled times are set to mid-morning (09:00) and early afternoon (14:00)
 * of each day. These are the times the simulate task uses to determine when
 * a window is due. Actual log entry timestamps are randomised within the
 * configured AM/PM window ranges by log_writer — not by this profile.
 *
 * SECTION-TO-WINDOW MAPPING
 * -------------------------
 * period_index maps directly to section number. Both AM and PM windows for
 * a given day work within the same section:
 *   period 1 (Monday)    -> section 1
 *   period 2 (Tuesday)   -> section 2
 *   ...
 *   period 5 (Friday)    -> section 5
 *
 * DECAY MODEL
 * -----------
 * Total windows = 10. Window index 0 = Monday AM, index 9 = Friday PM.
 * The decay model receives window_index / 10 as the normalised position
 * within the course. With only 10 windows the decay effect is small but
 * present — it becomes more significant in the 8-week and 16-week profiles.
 */
class one_week_intensive extends base_profile {

    /** @var int Number of weekdays in the course. */
    const WEEKDAYS = 5;

    /** @var int Total number of windows (2 per day × 5 days). */
    const TOTAL_WINDOWS = 10;

    /**
     * Scheduled hour for AM windows (24-hour). Mid-morning — falls within
     * the default AM window range of 08:00–12:00.
     */
    const AM_HOUR = 9;

    /**
     * Scheduled hour for PM windows (24-hour). Early afternoon — falls within
     * the default PM window range of 13:00–17:00.
     */
    const PM_HOUR = 14;

    /** @var string[] Day names for window labels, indexed 0–4 (Mon–Fri). */
    const DAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

    // -------------------------------------------------------------------------
    // Term structure
    // -------------------------------------------------------------------------

    /**
     * Returns the number of sections: one per weekday.
     *
     * @return int
     */
    public function get_section_count(): int {
        return self::WEEKDAYS;
    }

    /**
     * Returns the activity types for a section.
     *
     * All sections in this profile have the same four activity types.
     * The section number is accepted but not used — it is present to satisfy
     * the interface and to allow future profiles to vary activities by section.
     *
     * @param  int $section 1-based section number (unused in this profile).
     * @return string[]
     */
    public function get_activities_for_section(int $section): array {
        return ['page', 'quiz', 'assignment', 'forum'];
    }

    /**
     * Returns the term end timestamp.
     *
     * The term runs Monday through Friday (5 days). End timestamp is set to
     * Saturday at 00:00 — one second after the last window's day ends and
     * before the Saturday wrap-up window that instructors and overachievers
     * use in the spec. Saturday activity is covered by the Friday PM window
     * being the last in the schedule; there is no Saturday window in this
     * profile.
     *
     * @param  int $term_start Unix timestamp of term start (Monday 00:00).
     * @return int
     */
    public function get_term_end_timestamp(int $term_start): int {
        // Saturday 00:00 = 5 days after Monday 00:00.
        return $term_start + (self::WEEKDAYS * DAYSECS);
    }

    // -------------------------------------------------------------------------
    // Window schedule
    // -------------------------------------------------------------------------

    /**
     * Returns the full 10-window schedule for a one-week intensive term.
     *
     * Windows are returned in chronological order (Monday AM first,
     * Friday PM last). term_manager assigns 0-based window_index from
     * this ordering.
     *
     * @param  int $term_start Unix timestamp of term start (Monday 00:00).
     * @return array[]
     */
    public function get_window_schedule(int $term_start): array {
        $windows = [];

        for ($day = 0; $day < self::WEEKDAYS; $day++) {
            $period_index = $day + 1; // 1-based.
            $day_name     = self::DAY_NAMES[$day];

            $windows[] = [
                'period_index'   => $period_index,
                'window_key'     => 'am',
                'window_label'   => "$day_name AM",
                'scheduled_time' => $this->day_timestamp($term_start, $day, self::AM_HOUR),
            ];

            $windows[] = [
                'period_index'   => $period_index,
                'window_key'     => 'pm',
                'window_label'   => "$day_name PM",
                'scheduled_time' => $this->day_timestamp($term_start, $day, self::PM_HOUR),
            ];
        }

        return $windows;
    }

    /**
     * Returns the section number for a given window.
     *
     * In this profile period_index equals section number directly.
     * Both AM and PM windows for the same day work within the same section.
     *
     * @param  int    $period_index 1–5 (Monday–Friday).
     * @param  string $window_key   'am' or 'pm' (not used in this profile).
     * @return int                  Section number (1–5).
     */
    public function get_section_for_window(int $period_index, string $window_key): int {
        return $period_index;
    }

    /**
     * Returns the total number of windows in a full term.
     *
     * @return int
     */
    public function get_total_window_count(): int {
        return self::TOTAL_WINDOWS;
    }

    // -------------------------------------------------------------------------
    // Window metadata
    // -------------------------------------------------------------------------

    /**
     * Returns the time-of-day type for a window key.
     *
     * Used by window_runner to look up the AM or PM time range from settings
     * when generating backdated log entry timestamps.
     *
     * @param  string $window_key 'am' or 'pm'.
     * @return string             'am' or 'pm'.
     */
    public function get_window_type(string $window_key): string {
        return $window_key; // Window keys and types are identical in this profile.
    }
}
