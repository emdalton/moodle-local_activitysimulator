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
 * Content scanner for the activity simulator.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

namespace local_activitysimulator\simulation;

defined('MOODLE_INTERNAL') || die();

/**
 * Discovers activities present in a course section at runtime.
 *
 * Rather than hardcoding which activities exist in which section,
 * window_runner calls content_scanner to discover what is actually present.
 * This makes the simulation engine work correctly on any course that follows
 * the structural conventions of the active course profile, regardless of
 * exact activity names or IDs.
 *
 * USAGE
 * -----
 * Instantiate once per course per window run. The modinfo object is cached
 * on the instance so repeated calls to get_activities_in_section() for the
 * same course are cheap.
 *
 *   $scanner = new content_scanner($courseid);
 *   $activities = $scanner->get_activities_in_section($section_number);
 *   foreach ($activities as $activity) {
 *       // $activity->type, ->cmid, ->instanceid, ->name, ->duedate
 *   }
 *
 * ACTIVITY DESCRIPTORS
 * --------------------
 * Each returned descriptor is a plain object with these properties:
 *
 *   type       string   Activity type: 'page', 'quiz', 'assignment', 'forum'
 *   cmid       int      Course module ID (used in Moodle API calls)
 *   instanceid int      ID in the activity's own table (quiz.id, assign.id, etc.)
 *   name       string   Display name of the activity
 *   section    int      Section number (1-based, matches course profile sections)
 *   duedate    int|null Unix timestamp of due date, or null if none set
 *
 * SUPPORTED ACTIVITY TYPES
 * ------------------------
 * Only activity types recognised by student_actor and instructor_actor are
 * returned. Unknown module types (e.g. LTI, SCORM) are silently skipped.
 * To add support for a new type, add it to SUPPORTED_MODNAMES and implement
 * the corresponding handler in student_actor/instructor_actor.
 *
 * ANNOUNCEMENTS FORUM
 * -------------------
 * The course-level Announcements forum (forum type = 'news') is excluded
 * from section scans. It is handled separately by instructor_actor as a
 * special case. This is true even if the Announcements forum is somehow
 * placed inside a numbered section.
 */
class content_scanner {

    /**
     * Map from Moodle modname to the activity type string used in this plugin.
     * Only modnames listed here are returned by get_activities_in_section().
     *
     * @var array<string,string>
     */
    const SUPPORTED_MODNAMES = [
        'page'   => 'page',
        'quiz'   => 'quiz',
        'assign' => 'assignment',
        'forum'  => 'forum',
    ];

    /** @var int Course ID. */
    private int $courseid;

    /** @var \course_modinfo|null Cached modinfo for this course. */
    private ?\course_modinfo $modinfo = null;

    /** @var array<int,int|null> Cache of due dates keyed by cmid. */
    private array $duedate_cache = [];

    /**
     * Constructor.
     *
     * @param int $courseid Moodle course ID.
     */
    public function __construct(int $courseid) {
        $this->courseid = $courseid;
    }

    /**
     * Returns all supported activities in a given course section.
     *
     * Section numbers are 1-based, matching the course profile convention.
     * Moodle internally uses 0-based section numbers (section 0 is the
     * course header); this method handles the offset transparently.
     *
     * Activities are returned in the order Moodle stores them within the
     * section (i.e. the order they appear on the course page).
     *
     * @param  int      $section 1-based section number.
     * @return \stdClass[]       Array of activity descriptor objects.
     */
    public function get_activities_in_section(int $section): array {
        $modinfo  = $this->get_modinfo();
        $sections = $modinfo->get_section_info_all();

        // Convert 1-based section number to 0-based Moodle section index.
        // Section 0 in Moodle is the course header; our section 1 = Moodle section 1.
        $moodle_section = $section; // No offset needed: profile section 1 = Moodle section 1.

        if (!isset($sections[$moodle_section])) {
            return [];
        }

        $section_info = $sections[$moodle_section];
        $cms          = $modinfo->get_cms();
        $activities   = [];

        foreach ($section_info->sequence as $cmid) {
            if (!isset($cms[$cmid])) {
                continue;
            }

            $cm = $cms[$cmid];

            // Skip deleted or invisible modules.
            if ($cm->deletioninprogress || !$cm->uservisible) {
                continue;
            }

            // Skip unsupported module types.
            if (!array_key_exists($cm->modname, self::SUPPORTED_MODNAMES)) {
                continue;
            }

            // Skip the Announcements forum.
            if ($cm->modname === 'forum' && $this->is_announcements_forum($cm)) {
                continue;
            }

            $descriptor           = new \stdClass();
            $descriptor->type     = self::SUPPORTED_MODNAMES[$cm->modname];
            $descriptor->cmid     = (int)$cm->id;
            $descriptor->instanceid = (int)$cm->instance;
            $descriptor->name     = $cm->name;
            $descriptor->section  = $section;
            $descriptor->duedate  = $this->get_duedate($cm);

            $activities[] = $descriptor;
        }

        return $activities;
    }

    /**
     * Returns the Announcements forum cm_info for this course, or null.
     *
     * Used by instructor_actor to post announcements. Returns null if no
     * Announcements forum exists in the course.
     *
     * @return \cm_info|null
     */
    public function get_announcements_forum(): ?\cm_info {
        $modinfo = $this->get_modinfo();

        foreach ($modinfo->get_instances_of('forum') as $cm) {
            if ($this->is_announcements_forum($cm)) {
                return $cm;
            }
        }

        return null;
    }

    /**
     * Returns all section numbers that contain at least one supported activity.
     *
     * Useful for window_runner to verify the course was set up correctly
     * before attempting simulation.
     *
     * @return int[] 1-based section numbers.
     */
    public function get_populated_sections(): array {
        $modinfo  = $this->get_modinfo();
        $sections = $modinfo->get_section_info_all();
        $result   = [];

        // Start from section index 1 — skip section 0 (course header).
        for ($i = 1; $i < count($sections); $i++) {
            $activities = $this->get_activities_in_section($i);
            if (!empty($activities)) {
                $result[] = $i;
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns (and caches) the modinfo object for this course.
     *
     * @return \course_modinfo
     */
    private function get_modinfo(): \course_modinfo {
        if ($this->modinfo === null) {
            $this->modinfo = get_fast_modinfo($this->courseid);
        }
        return $this->modinfo;
    }

    /**
     * Returns true if the given forum cm_info is the Announcements forum.
     *
     * Checks the forum.type column in the database. Results are not cached
     * since this is called infrequently (once per forum per scan).
     *
     * @param  \cm_info $cm
     * @return bool
     */
    private function is_announcements_forum(\cm_info $cm): bool {
        global $DB;
        $type = $DB->get_field('forum', 'type', ['id' => $cm->instance]);
        return $type === 'news';
    }

    /**
     * Returns the due date for an activity, or null if none is set.
     *
     * Due dates are fetched from the activity's own table and cached by cmid.
     * Supported activity types and their due date columns:
     *   quiz   — timeclose (0 = no due date)
     *   assign — duedate   (0 = no due date)
     *   page   — no due date
     *   forum  — no due date
     *
     * @param  \cm_info  $cm
     * @return int|null  Unix timestamp, or null if no due date.
     */
    private function get_duedate(\cm_info $cm): ?int {
        global $DB;

        $cmid = (int)$cm->id;

        if (array_key_exists($cmid, $this->duedate_cache)) {
            return $this->duedate_cache[$cmid];
        }

        $duedate = null;

        switch ($cm->modname) {
            case 'quiz':
                $val = $DB->get_field('quiz', 'timeclose', ['id' => $cm->instance]);
                $duedate = ($val && $val > 0) ? (int)$val : null;
                break;

            case 'assign':
                $val = $DB->get_field('assign', 'duedate', ['id' => $cm->instance]);
                $duedate = ($val && $val > 0) ? (int)$val : null;
                break;

            case 'page':
            case 'forum':
            default:
                $duedate = null;
                break;
        }

        $this->duedate_cache[$cmid] = $duedate;
        return $duedate;
    }
}
