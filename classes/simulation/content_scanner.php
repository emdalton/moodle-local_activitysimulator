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
 * Discovers activities present in a course section at runtime and provides
 * forum state queries used by student_actor and instructor_actor.
 *
 * USAGE
 * -----
 * Instantiate once per course per window run. The modinfo object is cached
 * on the instance so repeated calls to get_activities_in_section() are cheap.
 *
 * ACTIVITY DESCRIPTORS
 * --------------------
 * get_activities_in_section() returns plain objects with:
 *
 *   type       string   'page', 'quiz', 'assignment', 'forum'
 *   cmid       int      Course module ID
 *   instanceid int      ID in the activity's own table
 *   name       string   Display name
 *   section    int      1-based section number
 *   duedate    int|null Unix timestamp of due date, or null
 *
 * FORUM STATE QUERIES
 * -------------------
 * Three methods provide the forum state needed by the simulation:
 *
 *   get_user_discussion()    — the discussion this user started (or null)
 *   get_other_discussions()  — discussions started by other users (reply targets)
 *   get_unread_discussions() — discussions the user hasn't read yet
 *
 * These all query at execution time, so the state naturally accumulates as
 * actors run sequentially within a window.
 *
 * ANNOUNCEMENTS FORUM
 * -------------------
 * The course Announcements forum (forum.type = 'news') is excluded from
 * section scans and returned separately via get_announcements_forum().
 */
class content_scanner {

    /**
     * Map from Moodle modname to plugin activity type string.
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

    /** @var \course_modinfo|null Cached modinfo. */
    private ?\course_modinfo $modinfo = null;

    /** @var array<int,int|null> Due date cache keyed by cmid. */
    private array $duedate_cache = [];

    /** @var array<int,bool> Announcements forum cache keyed by forum instanceid. */
    private array $news_forum_cache = [];

    /**
     * Constructor.
     *
     * @param int $courseid
     */
    public function __construct(int $courseid) {
        $this->courseid = $courseid;
    }

    // =========================================================================
    // Public: section scanning
    // =========================================================================

    /**
     * Returns all supported activities in a given course section.
     *
     * Section numbers are 1-based (matching course profile convention).
     * Moodle section 0 is the course header and is never scanned here.
     *
     * @param  int        $section 1-based section number.
     * @return \stdClass[]
     */
    public function get_activities_in_section(int $section): array {
        $modinfo  = $this->get_modinfo();
        $sections = $modinfo->get_section_info_all();

        if (!isset($sections[$section])) {
            return [];
        }

        $section_info = $sections[$section];
        $cms          = $modinfo->get_cms();
        $activities   = [];

        foreach (explode(',', $section_info->sequence ?: '') as $cmid) {
            $cmid = (int)$cmid;
            if ($cmid === 0 || !isset($cms[$cmid])) {
                continue;
            }

            $cm = $cms[$cmid];

            if ($cm->deletioninprogress || !$cm->uservisible) {
                continue;
            }

            if (!array_key_exists($cm->modname, self::SUPPORTED_MODNAMES)) {
                continue;
            }

            if ($cm->modname === 'forum' && $this->is_announcements_forum($cm->instance)) {
                continue;
            }

            $descriptor             = new \stdClass();
            $descriptor->type       = self::SUPPORTED_MODNAMES[$cm->modname];
            $descriptor->cmid       = (int)$cm->id;
            $descriptor->instanceid = (int)$cm->instance;
            $descriptor->name       = $cm->name;
            $descriptor->section    = $section;
            $descriptor->duedate    = $this->get_duedate($cm);

            $activities[] = $descriptor;
        }

        return $activities;
    }

    /**
     * Returns the Announcements forum cm_info for this course, or null.
     *
     * @return \cm_info|null
     */
    public function get_announcements_forum(): ?\cm_info {
        $modinfo = $this->get_modinfo();

        foreach ($modinfo->get_instances_of('forum') as $cm) {
            if ($this->is_announcements_forum($cm->instance)) {
                return $cm;
            }
        }

        return null;
    }

    // =========================================================================
    // Public: forum state queries
    // =========================================================================

    /**
     * Returns the discussion this user started in the given forum, or null.
     *
     * Used by student_actor to decide whether to create a new discussion
     * (if null) or reply to someone else's (if already posted).
     *
     * Queries forum_discussions directly for authoritative state. Results
     * are not cached — state changes as actors run within the window.
     *
     * @param  int      $forumid  forum.id (instance id, not cmid).
     * @param  int      $userid
     * @return \stdClass|null     forum_discussions record, or null.
     */
    public function get_user_discussion(int $forumid, int $userid): ?\stdClass {
        global $DB;

        $record = $DB->get_record('forum_discussions', [
            'forum'  => $forumid,
            'userid' => $userid,
        ]);

        return $record ?: null;
    }

    /**
     * Returns discussions in a forum not started by the given user.
     *
     * Used by student_actor to find reply targets. Returns all qualifying
     * discussions ordered by creation time ascending (oldest first), so
     * students who run later in a window have more discussions to reply to.
     *
     * @param  int      $forumid
     * @param  int      $userid  The user who will be replying (exclude their own).
     * @return \stdClass[]       forum_discussions records.
     */
    public function get_other_discussions(int $forumid, int $userid): array {
        global $DB;

        return array_values($DB->get_records_select(
            'forum_discussions',
            'forum = :forumid AND userid != :userid',
            ['forumid' => $forumid, 'userid' => $userid],
            'timemodified ASC'
        ));
    }

    /**
     * Returns discussions in a forum that the given user has not yet read.
     *
     * A discussion is considered read if the user has a forum_read entry
     * for the first post of that discussion (the discussion post itself).
     *
     * Used by student_actor and instructor_actor to find discussions to read.
     * Results are ordered by creation time ascending so older discussions
     * are read first — consistent with natural reading order.
     *
     * Each returned object has:
     *   ->discussionid  int  forum_discussions.id
     *   ->firstpostid   int  forum_posts.id of the discussion's first post
     *   ->authorid      int  userid of the discussion creator
     *   ->forumid       int  forum.id
     *
     * @param  int      $forumid
     * @param  int      $userid
     * @return \stdClass[]
     */
    public function get_unread_discussions(int $forumid, int $userid): array {
        global $DB;

        // A discussion's "first post" is the post where forum_posts.parent = 0
        // within that discussion. We check forum_read against that post id.
        $sql = "SELECT fd.id AS discussionid,
                       fp.id AS firstpostid,
                       fd.userid AS authorid,
                       fd.forum AS forumid
                  FROM {forum_discussions} fd
                  JOIN {forum_posts} fp
                    ON fp.discussion = fd.id
                   AND fp.parent = 0
             LEFT JOIN {forum_read} fr
                    ON fr.postid = fp.id
                   AND fr.userid = :userid
                 WHERE fd.forum = :forumid
                   AND fd.userid != :userid2
                   AND fr.id IS NULL
              ORDER BY fd.timemodified ASC";

        return array_values($DB->get_records_sql($sql, [
            'forumid' => $forumid,
            'userid'  => $userid,
            'userid2' => $userid,
        ]));
    }

    /**
     * Returns all section numbers that contain at least one supported activity.
     *
     * @return int[] 1-based section numbers.
     */
    public function get_populated_sections(): array {
        $modinfo  = $this->get_modinfo();
        $sections = $modinfo->get_section_info_all();
        $result   = [];

        for ($i = 1; $i < count($sections); $i++) {
            if (!empty($this->get_activities_in_section($i))) {
                $result[] = $i;
            }
        }

        return $result;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

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
     * Returns true if the given forum instance id is the Announcements forum.
     *
     * Result is cached per instance id to avoid repeated DB queries when
     * the same forum appears in multiple section scans.
     *
     * @param  int  $foruminstanceid  forum.id
     * @return bool
     */
    private function is_announcements_forum(int $foruminstanceid): bool {
        if (!array_key_exists($foruminstanceid, $this->news_forum_cache)) {
            global $DB;
            $type = $DB->get_field('forum', 'type', ['id' => $foruminstanceid]);
            $this->news_forum_cache[$foruminstanceid] = ($type === 'news');
        }
        return $this->news_forum_cache[$foruminstanceid];
    }

    /**
     * Returns the due date for an activity, or null if none is set.
     *
     * Due date columns by modname:
     *   quiz   — timeclose (0 = no due date)
     *   assign — duedate   (0 = no due date)
     *   page, forum — no due date
     *
     * @param  \cm_info  $cm
     * @return int|null
     */
    private function get_duedate(\cm_info $cm): ?int {
        $cmid = (int)$cm->id;

        if (array_key_exists($cmid, $this->duedate_cache)) {
            return $this->duedate_cache[$cmid];
        }

        global $DB;
        $duedate = null;

        switch ($cm->modname) {
            case 'quiz':
                $val     = $DB->get_field('quiz', 'timeclose', ['id' => $cm->instance]);
                $duedate = ($val && $val > 0) ? (int)$val : null;
                break;
            case 'assign':
                $val     = $DB->get_field('assign', 'duedate', ['id' => $cm->instance]);
                $duedate = ($val && $val > 0) ? (int)$val : null;
                break;
            default:
                $duedate = null;
        }

        $this->duedate_cache[$cmid] = $duedate;
        return $duedate;
    }
}
