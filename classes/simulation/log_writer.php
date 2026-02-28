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
 * Records simulated actions in the plugin's run_log audit table.
 *
 * DESIGN
 * ------
 * This class no longer writes to any Moodle core table. All entries in
 * logstore_standard_log are produced by real Moodle events that fire as
 * side effects of API calls (forum_add_discussion, assign API, etc.) or
 * from explicitly triggered event classes (view events). The forum_read
 * table is populated by the \mod_forum\event\discussion_viewed observer.
 *
 * This class has two responsibilities:
 *
 * 1. record_api_action() — called after a real Moodle API creates an object.
 *    Writes a run_log row with the objectid of the created object.
 *
 * 2. fire_view_event() — fires the appropriate Moodle event class for a
 *    view/read action (view_page, view_course, read_forum, etc.) and then
 *    writes a run_log row. Firing a real event ensures any observers
 *    (including forum_read population) run correctly.
 *
 * SIMULATED_TIME
 * --------------
 * Every run_log row records a simulated_time drawn from the window's
 * scheduled time range. This is stored for analytical reference — it
 * documents what time-of-day the simulation was targeting — but is not
 * applied to any Moodle core table. Moodle objects receive real
 * wall-clock timestamps from the event system.
 *
 * ACTIVE VS PASSIVE
 * -----------------
 * The action_class column ('active' or 'passive') is derived from the
 * action_type and stored in run_log. This enables the view/engage split
 * analysis without requiring a join to any other table.
 */
class log_writer {

    /**
     * Action types that count as 'active' engagement.
     * All others are classified as 'passive'.
     *
     * @var string[]
     */
    const ACTIVE_ACTION_TYPES = [
        'post_forum',
        'reply_forum',
        'post_announcement',
        'submit_assignment',
        'grade_assignment',
        'attempt_quiz',
        'submit_quiz',
    ];

    /**
     * Maps view action_type strings to their Moodle event class names and
     * the data needed to construct them.
     *
     * Each entry specifies:
     *   class    — fully-qualified event class name
     *   context  — 'module' (uses activity cmid) or 'course'
     *
     * @var array<string, array>
     */
    const VIEW_EVENT_MAP = [
        'view_page'         => ['class' => '\mod_page\event\course_module_viewed',           'context' => 'module'],
        'view_forum'        => ['class' => '\mod_forum\event\course_module_viewed',          'context' => 'module'],
        'read_forum'        => ['class' => '\mod_forum\event\discussion_viewed',             'context' => 'module'],
        'read_announcement' => ['class' => '\mod_forum\event\discussion_viewed',             'context' => 'module'],
        'view_assignment'   => ['class' => '\mod_assign\event\course_module_viewed',         'context' => 'module'],
        'view_quiz_grade'   => ['class' => '\mod_quiz\event\attempt_reviewed',               'context' => 'module'],
        'view_course'       => ['class' => '\core\event\course_viewed',                      'context' => 'course'],
        'view_grades'       => ['class' => '\gradereport_user\event\grade_report_viewed',    'context' => 'course'],
        'view_gradebook'    => ['class' => '\gradereport_grader\event\grade_report_viewed',  'context' => 'course'],
    ];

    /** @var \stdClass Plugin config. */
    private \stdClass $config;

    /** @var \stdClass Window record from local_activitysimulator_windows. */
    private \stdClass $window;

    /** @var string 'am' or 'pm'. */
    private string $window_type;

    /** @var int 0-based window index within term. */
    private int $window_index;

    /** @var int[] [start_ts, end_ts] for AM time range on window date. */
    private array $am_range;

    /** @var int[] [start_ts, end_ts] for PM time range on window date. */
    private array $pm_range;

    /**
     * Constructor.
     *
     * @param \stdClass $window
     * @param string    $window_type  'am' or 'pm'
     * @param int       $window_index 0-based position within term
     */
    public function __construct(\stdClass $window, string $window_type, int $window_index) {
        $this->config       = get_config('local_activitysimulator');
        $this->window       = $window;
        $this->window_type  = $window_type;
        $this->window_index = $window_index;
        $this->am_range     = $this->build_time_range('am');
        $this->pm_range     = $this->build_time_range('pm');
    }

    // =========================================================================
    // Public: API-created actions
    // =========================================================================

    /**
     * Records an action performed via a real Moodle API call.
     *
     * The API call (e.g. forum_add_discussion()) fires events internally,
     * which write to logstore_standard_log. This method only needs to write
     * the run_log row.
     *
     * Call this immediately after the API call returns, passing the ID of
     * the object the API created.
     *
     * @param  int            $userid
     * @param  int            $courseid
     * @param  string         $action_type  e.g. 'post_forum', 'submit_assignment'
     * @param  \stdClass|null $activity     Activity descriptor from content_scanner, or null.
     * @param  int|null       $objectid     ID of the created Moodle object.
     * @param  string|null    $outcome      e.g. 'posted', 'submitted', 'graded'
     * @return int            run_log record ID.
     */
    public function record_api_action(
        int $userid,
        int $courseid,
        string $action_type,
        ?\stdClass $activity = null,
        ?int $objectid = null,
        ?string $outcome = null
    ): int {
        return $this->write_run_log(
            $userid, $courseid, $action_type,
            $activity, $objectid, $outcome
        );
    }

    // =========================================================================
    // Public: view events
    // =========================================================================

    /**
     * Fires a real Moodle event for a view/read action and records it in run_log.
     *
     * Firing real events ensures all observers run — most importantly the
     * forum observer that populates forum_read when a discussion is viewed.
     *
     * $USER must already be the simulated user when this is called (i.e.
     * user_switcher must be active at the call site).
     *
     * @param  int            $userid        Must match the currently active $USER->id.
     * @param  int            $courseid
     * @param  string         $action_type   Must be a key in VIEW_EVENT_MAP.
     * @param  \stdClass|null $activity      Activity descriptor, or null for course-level.
     * @param  int|null       $objectid      Object being viewed (e.g. discussion id, attempt id).
     * @param  string|null    $outcome
     * @param  int|null       $relateduserid For read_forum/read_announcement: the post author.
     * @return int            run_log record ID.
     */
    public function fire_view_event(
        int $userid,
        int $courseid,
        string $action_type,
        ?\stdClass $activity = null,
        ?int $objectid = null,
        ?string $outcome = null,
        ?int $relateduserid = null
    ): int {
        if (!array_key_exists($action_type, self::VIEW_EVENT_MAP)) {
            throw new \coding_exception("log_writer: '$action_type' is not a recognised view action type");
        }

        $map = self::VIEW_EVENT_MAP[$action_type];

        if ($map['context'] === 'module') {
            if ($activity === null) {
                throw new \coding_exception("log_writer: activity required for module-context event '$action_type'");
            }
            $context = \context_module::instance($activity->cmid);
        } else {
            $context = \context_course::instance($courseid);
        }

        $this->trigger_view_event($action_type, $map, $context, $courseid, $activity, $objectid, $relateduserid);

        return $this->write_run_log(
            $userid, $courseid, $action_type,
            $activity, $objectid, $outcome
        );
    }

    // =========================================================================
    // Private: event triggering
    // =========================================================================

    /**
     * Constructs and triggers the Moodle event for a view action.
     *
     * Each event class has slightly different required fields in its data
     * array. This method handles the per-class variations.
     *
     * @param  string      $action_type
     * @param  array       $map          Entry from VIEW_EVENT_MAP.
     * @param  \context    $context
     * @param  int         $courseid
     * @param  \stdClass|null $activity
     * @param  int|null    $objectid
     * @param  int|null    $relateduserid
     * @return void
     */
    private function trigger_view_event(
        string $action_type,
        array $map,
        \context $context,
        int $courseid,
        ?\stdClass $activity,
        ?int $objectid,
        ?int $relateduserid
    ): void {
        $classname = $map['class'];

        // Base data common to all events.
        // NOTE: do not set objectid in the base array — only set it per-case
        // for events whose init() method declares an objecttable. Moodle throws
        // a coding_exception if objectid is present without a matching objecttable.
        $data = [
            'context'  => $context,
            'courseid' => $courseid,
        ];

        if ($relateduserid !== null) {
            $data['relateduserid'] = $relateduserid;
        }

        // Per-event-class variations.
        // Only set objectid for events whose class declares objecttable in init().
        switch ($action_type) {

            case 'view_page':
                // objecttable = 'page'
                $data['objectid'] = $activity->instanceid;
                break;

            case 'view_forum':
                // objecttable = 'forum'
                $data['objectid'] = $activity->instanceid;
                break;

            case 'read_forum':
            case 'read_announcement':
                // objecttable = 'forum_discussions', objectid = discussion id passed by caller
                $data['objectid'] = $objectid;
                break;

            case 'view_assignment':
                // objecttable = 'assign'
                $data['objectid'] = $activity->instanceid;
                break;

            case 'view_quiz_grade':
                // objecttable = 'quiz_attempts', objectid = attempt id passed by caller
                $data['objectid'] = $objectid;
                $data['other']    = ['attemptid' => $objectid];
                break;

            case 'view_course':
                // \core\event\course_viewed has no objecttable — do not set objectid.
                break;

            case 'view_grades':
                // no objecttable
                $data['other'] = ['courseid' => $courseid];
                break;

            case 'view_gradebook':
                // no objecttable
                $data['other'] = ['courseid' => $courseid];
                break;
        }

        $event = $classname::create($data);
        $event->trigger();
    }

    // =========================================================================
    // Private: run_log
    // =========================================================================

    /**
     * Inserts a row into local_activitysimulator_run_log.
     *
     * simulated_time is drawn from the window's scheduled time range and
     * stored here for reference. It is not applied to any Moodle core table.
     *
     * @param  int            $userid
     * @param  int            $courseid
     * @param  string         $action_type
     * @param  \stdClass|null $activity
     * @param  int|null       $objectid
     * @param  string|null    $outcome
     * @return int            Inserted record ID.
     */
    private function write_run_log(
        int $userid,
        int $courseid,
        string $action_type,
        ?\stdClass $activity,
        ?int $objectid,
        ?string $outcome
    ): int {
        global $DB;

        $record = new \stdClass();
        $record->termid         = $this->window->termid;
        $record->windowid       = $this->window->id;
        $record->window_index   = $this->window_index;
        $record->courseid       = $courseid;
        $record->userid         = $userid;
        $record->action_type    = $action_type;
        $record->action_class   = in_array($action_type, self::ACTIVE_ACTION_TYPES) ? 'active' : 'passive';
        $record->cmid           = $activity ? $activity->cmid : null;
        $record->objectid       = $objectid;
        $record->simulated_time = $this->random_timestamp();
        $record->outcome        = $outcome;
        $record->timecreated    = time();

        return (int)$DB->insert_record('local_activitysimulator_run_log', $record);
    }

    // =========================================================================
    // Private: timestamp generation
    // =========================================================================

    /**
     * Returns a random Unix timestamp within the current window's time range.
     *
     * Used only for run_log.simulated_time (reference only).
     *
     * @return int
     */
    private function random_timestamp(): int {
        $range = $this->window_type === 'am' ? $this->am_range : $this->pm_range;
        return mt_rand($range[0], $range[1]);
    }

    /**
     * Builds a [start_ts, end_ts] pair for 'am' or 'pm' on the window date.
     *
     * @param  string $type 'am' or 'pm'
     * @return int[]
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
     * Converts HH:MM string to seconds since midnight.
     *
     * @param  string $hhmm e.g. '08:00', '13:30'
     * @return int
     */
    private function hhmm_to_seconds(string $hhmm): int {
        $parts = explode(':', $hhmm);
        return ((int)$parts[0] * HOURSECS) + ((int)($parts[1] ?? 0) * 60);
    }
}
