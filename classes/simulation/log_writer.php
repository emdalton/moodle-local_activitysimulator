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
 * Log writer for the activity simulator.
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
 * Writes backdated log entries to Moodle's standard logstore and to the
 * plugin's own run_log audit table.
 *
 * BACKDATING APPROACH
 * -------------------
 * Moodle's event system fires events with timecreated = time() and provides
 * no API to override the timestamp. To generate historically accurate log
 * data, this class writes directly to logstore_standard_log rather than
 * going through the event system.
 *
 * This is intentional and appropriate for a simulation plugin running on a
 * dedicated test Moodle instance. It should never be used on a production
 * site with real user data.
 *
 * TIMESTAMP GENERATION
 * --------------------
 * Each log entry receives a timestamp randomly distributed within the
 * window's time-of-day range (AM or PM), on the date of the window's
 * scheduled_time. The AM and PM ranges are read from plugin settings
 * (default 08:00–12:00 and 13:00–17:00).
 *
 * This means multiple log entries for the same user in the same window
 * will have slightly different timestamps, which is realistic and important
 * for analytics tools that look at time-between-events.
 *
 * ACTION TYPES AND EVENT METADATA
 * --------------------------------
 * Each action type maps to a set of logstore column values (eventname,
 * component, action, target, crud, edulevel). These are defined in
 * EVENT_DEFINITIONS below. Only the action types actually simulated by
 * student_actor and instructor_actor need entries here.
 *
 * RUN LOG
 * -------
 * Every call to write_action() also inserts a row in
 * local_activitysimulator_run_log. This provides a ground-truth audit trail
 * that can be used to verify analytics results against known inputs.
 */
class log_writer {

    /**
     * Maps action_type strings to logstore_standard_log column values.
     *
     * Keys are the action_type strings used in run_log and passed to
     * write_action(). Values are arrays of logstore column values.
     *
     * crud values: 'c' = create, 'r' = read, 'u' = update, 'd' = delete
     * edulevel values: 0 = other, 1 = teaching, 2 = participating
     *
     * @var array<string, array>
     */
    const EVENT_DEFINITIONS = [

        // ---- Page ----
        'view_page' => [
            'eventname'   => '\mod_page\event\course_module_viewed',
            'component'   => 'mod_page',
            'action'      => 'viewed',
            'target'      => 'course_module',
            'objecttable' => 'page',
            'crud'        => 'r',
            'edulevel'    => 2,
        ],

        // ---- Quiz ----
        'attempt_quiz' => [
            'eventname'   => '\mod_quiz\event\attempt_started',
            'component'   => 'mod_quiz',
            'action'      => 'started',
            'target'      => 'attempt',
            'objecttable' => 'quiz_attempts',
            'crud'        => 'c',
            'edulevel'    => 2,
        ],
        'submit_quiz' => [
            'eventname'   => '\mod_quiz\event\attempt_submitted',
            'component'   => 'mod_quiz',
            'action'      => 'submitted',
            'target'      => 'attempt',
            'objecttable' => 'quiz_attempts',
            'crud'        => 'u',
            'edulevel'    => 2,
        ],
        'view_quiz_grade' => [
            'eventname'   => '\mod_quiz\event\attempt_reviewed',
            'component'   => 'mod_quiz',
            'action'      => 'reviewed',
            'target'      => 'attempt',
            'objecttable' => 'quiz_attempts',
            'crud'        => 'r',
            'edulevel'    => 2,
        ],

        // ---- Assignment ----
        'view_assignment' => [
            'eventname'   => '\mod_assign\event\course_module_viewed',
            'component'   => 'mod_assign',
            'action'      => 'viewed',
            'target'      => 'course_module',
            'objecttable' => 'assign',
            'crud'        => 'r',
            'edulevel'    => 2,
        ],
        'submit_assignment' => [
            'eventname'   => '\mod_assign\event\assessable_submitted',
            'component'   => 'mod_assign',
            'action'      => 'submitted',
            'target'      => 'assessable',
            'objecttable' => 'assign_submission',
            'crud'        => 'u',
            'edulevel'    => 2,
        ],
        'grade_assignment' => [
            'eventname'   => '\mod_assign\event\submission_graded',
            'component'   => 'mod_assign',
            'action'      => 'graded',
            'target'      => 'submission',
            'objecttable' => 'assign_grades',
            'crud'        => 'u',
            'edulevel'    => 1,
        ],

        // ---- Forum ----
        'view_forum' => [
            'eventname'   => '\mod_forum\event\course_module_viewed',
            'component'   => 'mod_forum',
            'action'      => 'viewed',
            'target'      => 'course_module',
            'objecttable' => 'forum',
            'crud'        => 'r',
            'edulevel'    => 2,
        ],
        'post_forum' => [
            'eventname'   => '\mod_forum\event\post_created',
            'component'   => 'mod_forum',
            'action'      => 'created',
            'target'      => 'post',
            'objecttable' => 'forum_posts',
            'crud'        => 'c',
            'edulevel'    => 2,
        ],
        'reply_forum' => [
            'eventname'   => '\mod_forum\event\post_created',
            'component'   => 'mod_forum',
            'action'      => 'created',
            'target'      => 'post',
            'objecttable' => 'forum_posts',
            'crud'        => 'c',
            'edulevel'    => 2,
        ],
        'read_forum' => [
            'eventname'   => '\mod_forum\event\discussion_viewed',
            'component'   => 'mod_forum',
            'action'      => 'viewed',
            'target'      => 'discussion',
            'objecttable' => 'forum_discussions',
            'crud'        => 'r',
            'edulevel'    => 2,
        ],

        // ---- Announcements forum ----
        'post_announcement' => [
            'eventname'   => '\mod_forum\event\post_created',
            'component'   => 'mod_forum',
            'action'      => 'created',
            'target'      => 'post',
            'objecttable' => 'forum_posts',
            'crud'        => 'c',
            'edulevel'    => 1,
        ],
        'read_announcement' => [
            'eventname'   => '\mod_forum\event\discussion_viewed',
            'component'   => 'mod_forum',
            'action'      => 'viewed',
            'target'      => 'discussion',
            'objecttable' => 'forum_discussions',
            'crud'        => 'r',
            'edulevel'    => 2,
        ],

        // ---- Course-level ----
        'view_course' => [
            'eventname'   => '\core\event\course_viewed',
            'component'   => 'core',
            'action'      => 'viewed',
            'target'      => 'course',
            'objecttable' => null,
            'crud'        => 'r',
            'edulevel'    => 2,
        ],
        'view_grades' => [
            'eventname'   => '\gradereport_user\event\grade_report_viewed',
            'component'   => 'gradereport_user',
            'action'      => 'viewed',
            'target'      => 'grade_report',
            'objecttable' => null,
            'crud'        => 'r',
            'edulevel'    => 2,
        ],
        'view_gradebook' => [
            'eventname'   => '\gradereport_grader\event\grade_report_viewed',
            'component'   => 'gradereport_grader',
            'action'      => 'viewed',
            'target'      => 'grade_report',
            'objecttable' => null,
            'crud'        => 'r',
            'edulevel'    => 1,
        ],
    ];

    /**
     * Action types that count as 'active' engagement for run_log.action_class.
     * All others are classified as 'passive'.
     *
     * @var string[]
     */
    const ACTIVE_ACTION_TYPES = [
        'attempt_quiz',
        'submit_quiz',
        'submit_assignment',
        'post_forum',
        'reply_forum',
        'post_announcement',
        'grade_assignment',
    ];

    /** @var \stdClass Plugin config. */
    private \stdClass $config;

    /** @var \stdClass Window record (from local_activitysimulator_windows). */
    private \stdClass $window;

    /** @var string Window type: 'am' or 'pm'. */
    private string $window_type;

    /** @var int Window index within term (0-based), for run_log. */
    private int $window_index;

    /** @var int[] Parsed AM window range [start_ts, end_ts] for window's date. */
    private array $am_range;

    /** @var int[] Parsed PM window range [start_ts, end_ts] for window's date. */
    private array $pm_range;

    /**
     * Constructor.
     *
     * @param \stdClass $window       Window record from local_activitysimulator_windows.
     * @param string    $window_type  'am' or 'pm' (from profile::get_window_type()).
     * @param int       $window_index 0-based position of window within term.
     */
    public function __construct(\stdClass $window, string $window_type, int $window_index) {
        $this->config       = get_config('local_activitysimulator');
        $this->window       = $window;
        $this->window_type  = $window_type;
        $this->window_index = $window_index;

        // Pre-compute AM and PM timestamp ranges for this window's date.
        $this->am_range = $this->build_time_range('am');
        $this->pm_range = $this->build_time_range('pm');
    }

    /**
     * Writes a simulated action to the logstore and to the plugin run_log.
     *
     * This is the single method called by student_actor and instructor_actor
     * for every simulated action.
     *
     * @param  int            $userid      Moodle user ID of the simulated user.
     * @param  int            $courseid    Moodle course ID.
     * @param  string         $action_type Action type key (must exist in EVENT_DEFINITIONS).
     * @param  \stdClass|null $activity    Activity descriptor from content_scanner,
     *                                    or null for course-level actions.
     * @param  int|null       $objectid    ID of the specific object acted on
     *                                    (e.g. quiz_attempt.id), or null.
     * @param  string|null    $outcome     Optional outcome string for run_log
     *                                    (e.g. 'score_90', 'submitted', 'skipped').
     * @return int            ID of the inserted run_log record.
     */
    public function write_action(
        int $userid,
        int $courseid,
        string $action_type,
        ?\stdClass $activity = null,
        ?int $objectid = null,
        ?string $outcome = null
    ): int {
        $simulated_time = $this->random_timestamp();

        $this->write_logstore_entry(
            $userid,
            $courseid,
            $action_type,
            $activity,
            $objectid,
            $simulated_time
        );

        return $this->write_run_log(
            $userid,
            $courseid,
            $action_type,
            $activity,
            $simulated_time,
            $outcome
        );
    }

    // -------------------------------------------------------------------------
    // Private: logstore
    // -------------------------------------------------------------------------

    /**
     * Inserts a row directly into logstore_standard_log with a backdated
     * timecreated value.
     *
     * @param  int            $userid
     * @param  int            $courseid
     * @param  string         $action_type
     * @param  \stdClass|null $activity
     * @param  int|null       $objectid
     * @param  int            $simulated_time
     * @return void
     */
    private function write_logstore_entry(
        int $userid,
        int $courseid,
        string $action_type,
        ?\stdClass $activity,
        ?int $objectid,
        int $simulated_time
    ): void {
        global $DB;

        if (!array_key_exists($action_type, self::EVENT_DEFINITIONS)) {
            throw new \coding_exception("log_writer: unknown action_type '$action_type'");
        }

        $def = self::EVENT_DEFINITIONS[$action_type];

        $context = $activity !== null
            ? \context_module::instance($activity->cmid)
            : \context_course::instance($courseid);

        $record = new \stdClass();
        $record->eventname         = $def['eventname'];
        $record->component         = $def['component'];
        $record->action            = $def['action'];
        $record->target            = $def['target'];
        $record->objecttable       = $def['objecttable'];
        $record->objectid          = $objectid;
        $record->crud              = $def['crud'];
        $record->edulevel          = $def['edulevel'];
        $record->contextid         = $context->id;
        $record->contextlevel      = $context->contextlevel;
        $record->contextinstanceid = $activity ? $activity->cmid : $courseid;
        $record->userid            = $userid;
        $record->courseid          = $courseid;
        $record->relateduserid     = null;
        $record->anonymous         = 0;
        $record->other             = null;
        $record->timecreated       = $simulated_time;

        $DB->insert_record('logstore_standard_log', $record, false);
    }

    // -------------------------------------------------------------------------
    // Private: run_log
    // -------------------------------------------------------------------------

    /**
     * Inserts a row into local_activitysimulator_run_log.
     *
     * @param  int            $userid
     * @param  int            $courseid
     * @param  string         $action_type
     * @param  \stdClass|null $activity
     * @param  int            $simulated_time
     * @param  string|null    $outcome
     * @return int            Inserted record ID.
     */
    private function write_run_log(
        int $userid,
        int $courseid,
        string $action_type,
        ?\stdClass $activity,
        int $simulated_time,
        ?string $outcome
    ): int {
        global $DB;

        $action_class = in_array($action_type, self::ACTIVE_ACTION_TYPES)
            ? 'active'
            : 'passive';

        $record = new \stdClass();
        $record->termid         = $this->window->termid;
        $record->windowid       = $this->window->id;
        $record->window_index   = $this->window_index;
        $record->courseid       = $courseid;
        $record->userid         = $userid;
        $record->action_type    = $action_type;
        $record->action_class   = $action_class;
        $record->cmid           = $activity ? $activity->cmid : null;
        $record->simulated_time = $simulated_time;
        $record->outcome        = $outcome;
        $record->timecreated    = time();

        return (int)$DB->insert_record('local_activitysimulator_run_log', $record);
    }

    // -------------------------------------------------------------------------
    // Private: timestamp generation
    // -------------------------------------------------------------------------

    /**
     * Returns a random Unix timestamp within the current window's time range.
     *
     * @return int
     */
    private function random_timestamp(): int {
        $range = $this->window_type === 'am' ? $this->am_range : $this->pm_range;
        return mt_rand($range[0], $range[1]);
    }

    /**
     * Builds a [start_timestamp, end_timestamp] pair for a given window type
     * on the date of this window's scheduled_time.
     *
     * @param  string $type 'am' or 'pm'.
     * @return int[]        [start_unix_timestamp, end_unix_timestamp].
     */
    private function build_time_range(string $type): array {
        $start_hhmm = $this->config->{$type . '_window_start'} ?? ($type === 'am' ? '08:00' : '13:00');
        $end_hhmm   = $this->config->{$type . '_window_end'}   ?? ($type === 'am' ? '12:00' : '17:00');

        $midnight = mktime(0, 0, 0,
            (int)date('n', $this->window->scheduled_time),
            (int)date('j', $this->window->scheduled_time),
            (int)date('Y', $this->window->scheduled_time)
        );

        return [
            $midnight + $this->hhmm_to_seconds($start_hhmm),
            $midnight + $this->hhmm_to_seconds($end_hhmm),
        ];
    }

    /**
     * Converts an HH:MM string to seconds since midnight.
     *
     * @param  string $hhmm e.g. '08:00', '13:30'.
     * @return int
     */
    private function hhmm_to_seconds(string $hhmm): int {
        $parts = explode(':', $hhmm);
        return ((int)$parts[0] * HOURSECS) + ((int)($parts[1] ?? 0) * 60);
    }
}
