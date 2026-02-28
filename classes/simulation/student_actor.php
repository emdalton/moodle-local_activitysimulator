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
 * Student actor for the activity simulator.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

namespace local_activitysimulator\simulation;

defined('MOODLE_INTERNAL') || die();

// mod/forum/lib.php and mod/assign/locallib.php are require_once'd by window_runner.php
// before this class is instantiated. Loading them here at autoload time is unreliable.

use local_activitysimulator\learner_profiles\base_learner_profile;
use local_activitysimulator\data\name_generator;

/**
 * Simulates the actions of a single student in a single activity window.
 *
 * Called by window_runner once per enrolled student per window. Uses real
 * Moodle APIs with $USER temporarily switched to the simulated student so
 * that all created objects are correctly attributed.
 *
 * ACTION SEQUENCE
 * ---------------
 * 1. Engagement roll — if the student doesn't engage, exit with no actions.
 *
 * 2. View course (passive view event).
 *
 * 3. For each activity in the section:
 *
 *    Page:
 *      passive roll → fire view_page event
 *
 *    Forum:
 *      Active path:
 *        If student has no discussion in this forum yet:
 *          active roll → forum_add_discussion() → post_forum
 *        If student already has a discussion:
 *          active roll → forum_add_post() replying to a random other
 *                        student's discussion → reply_forum
 *        (If no other discussions exist to reply to, skip active action.)
 *      Passive path (independent of active):
 *        Read up to profile->get_max_forum_reads_per_window() unread
 *        discussions via discussion_viewed event → read_forum
 *
 *    Assignment:
 *      active roll  → assign API submit → submit_assignment
 *      else passive roll → view_assignment event
 *
 *    Quiz: stub — deferred to Stage 3.
 *
 * 4. Read unread announcements (passive read event).
 *
 * 5. View grades (passive, probability weighted toward later windows).
 *
 * USER SWITCHING
 * --------------
 * Every action that calls a Moodle API or fires a Moodle event uses
 * user_switcher to ensure $USER is the simulated student. The switcher
 * is constructed and restored within each private simulate_* method
 * using try/finally so that $USER is always restored even if the API
 * throws an exception.
 */
class student_actor {

    /** @var log_writer */
    private log_writer $log_writer;

    /** @var content_scanner */
    private content_scanner $scanner;

    /** @var name_generator */
    private name_generator $namegen;

    /** @var int Total windows in term, for decay and grade-view weighting. */
    private int $total_windows;

    /** @var bool Emit per-student mtrace() lines. */
    private bool $verbose;

    /**
     * Constructor.
     *
     * @param log_writer      $log_writer
     * @param content_scanner $scanner
     * @param name_generator  $namegen
     * @param int             $total_windows
     * @param bool            $verbose
     */
    public function __construct(
        log_writer $log_writer,
        content_scanner $scanner,
        name_generator $namegen,
        int $total_windows,
        bool $verbose = false
    ) {
        $this->log_writer    = $log_writer;
        $this->scanner       = $scanner;
        $this->namegen       = $namegen;
        $this->total_windows = $total_windows;
        $this->verbose       = $verbose;
    }

    /**
     * Simulates one student's actions in one window.
     *
     * @param  int                  $userid
     * @param  int                  $courseid
     * @param  int                  $section       1-based section number.
     * @param  int                  $window_index  0-based window position within term.
     * @param  base_learner_profile $profile
     * @return int                  Number of actions taken (0 = student skipped window).
     */
    public function simulate(
        int $userid,
        int $courseid,
        int $section,
        int $window_index,
        base_learner_profile $profile
    ): int {
        $written = 0;

        // Initial engagement roll.
        if (!$profile->should_engage(base_learner_profile::ACTION_PASSIVE, $window_index, $this->total_windows)) {
            if ($this->verbose) {
                mtrace(sprintf('      student %s [%s] window %d: skipped',
                    $this->get_username($userid), $profile->get_group_type(), $window_index));
            }
            return 0;
        }

        // 1. View course.
        $switcher = new user_switcher($userid);
        try {
            $this->log_writer->fire_view_event($userid, $courseid, 'view_course');
        } finally {
            $switcher->restore();
        }
        $written++;

        // 2. Activities in section.
        $activities = $this->scanner->get_activities_in_section($section);
        foreach ($activities as $activity) {
            $written += $this->simulate_activity($userid, $courseid, $activity, $window_index, $profile);
        }

        // 3. Read unread announcements.
        $written += $this->simulate_announcements($userid, $courseid, $window_index, $profile);

        // 4. View grades (weighted toward later windows).
        if ($this->should_view_grades($window_index, $profile)) {
            $switcher = new user_switcher($userid);
            try {
                $this->log_writer->fire_view_event($userid, $courseid, 'view_grades');
            } finally {
                $switcher->restore();
            }
            $written++;
        }

        if ($this->verbose) {
            mtrace(sprintf('      student %s [%s] window %d: %d actions',
                $this->get_username($userid), $profile->get_group_type(), $window_index, $written));
        }

        return $written;
    }

    // =========================================================================
    // Private: per-activity dispatch
    // =========================================================================

    /**
     * @param  int                  $userid
     * @param  int                  $courseid
     * @param  \stdClass            $activity
     * @param  int                  $window_index
     * @param  base_learner_profile $profile
     * @return int
     */
    private function simulate_activity(
        int $userid,
        int $courseid,
        \stdClass $activity,
        int $window_index,
        base_learner_profile $profile
    ): int {
        switch ($activity->type) {
            case 'page':
                return $this->simulate_page($userid, $courseid, $activity, $window_index, $profile);
            case 'forum':
                return $this->simulate_forum($userid, $courseid, $activity, $window_index, $profile);
            case 'assignment':
                return $this->simulate_assignment($userid, $courseid, $activity, $window_index, $profile);
            case 'quiz':
                // Stage 3 — not yet implemented.
                return 0;
            default:
                return 0;
        }
    }

    // =========================================================================
    // Private: page
    // =========================================================================

    /**
     * Passive view only.
     *
     * @return int
     */
    private function simulate_page(
        int $userid,
        int $courseid,
        \stdClass $activity,
        int $window_index,
        base_learner_profile $profile
    ): int {
        if (!$profile->should_engage(base_learner_profile::ACTION_PASSIVE, $window_index, $this->total_windows)) {
            return 0;
        }

        $switcher = new user_switcher($userid);
        try {
            $this->log_writer->fire_view_event($userid, $courseid, 'view_page', $activity, $activity->instanceid);
        } finally {
            $switcher->restore();
        }

        return 1;
    }

    // =========================================================================
    // Private: forum
    // =========================================================================

    /**
     * Forum: create discussion or reply (active), then read discussions (passive).
     *
     * Active path (one attempt per window per forum):
     *   - If student has no discussion in this forum → create one.
     *   - If student already has a discussion → reply to a random other's discussion.
     *   - If no other discussions exist to reply to → skip active action gracefully.
     *
     * Passive path (independent of active, runs regardless):
     *   - Read up to profile->get_max_forum_reads_per_window() unread discussions.
     *   - Each read fires a discussion_viewed event (which populates forum_read).
     *
     * @return int
     */
    private function simulate_forum(
        int $userid,
        int $courseid,
        \stdClass $activity,
        int $window_index,
        base_learner_profile $profile
    ): int {
        global $DB, $CFG;

        $written = 0;

        // --- Active path ---
        if ($profile->should_engage(base_learner_profile::ACTION_ACTIVE, $window_index, $this->total_windows)) {
            $existing = $this->scanner->get_user_discussion($activity->instanceid, $userid);

            if ($existing === null) {
                // Create a new discussion.
                $written += $this->create_forum_discussion($userid, $courseid, $activity);
            } else {
                // Reply to another student's discussion.
                $written += $this->reply_to_forum_discussion($userid, $courseid, $activity);
            }
        }

        // --- Passive path: read unread discussions ---
        $max_reads   = $profile->get_max_forum_reads_per_window();
        $reads_done  = 0;

        if ($max_reads > 0 &&
            $profile->should_engage(base_learner_profile::ACTION_PASSIVE, $window_index, $this->total_windows)) {

            $unread = $this->scanner->get_unread_discussions($activity->instanceid, $userid);

            foreach ($unread as $disc) {
                if ($reads_done >= $max_reads) {
                    break;
                }

                // discussion_viewed requires relateduserid = discussion author.
                // This is the directed edge in social network analysis: viewer -> author.
                // Skip the event entirely if authorid is missing — a false edge
                // is worse than a missing one for SNA purposes.
                $authorid = (int)$disc->authorid;
                if ($authorid === 0) {
                    mtrace("    Warning: discussion {$disc->discussionid} has no authorid, skipping read_forum event");
                    continue;
                }
                $switcher = new user_switcher($userid);
                try {
                    $this->log_writer->fire_view_event(
                        $userid,
                        $courseid,
                        'read_forum',
                        $activity,
                        (int)$disc->discussionid,
                        null,
                        $authorid
                    );
                } finally {
                    $switcher->restore();
                }

                $written++;
                $reads_done++;
            }
        }

        return $written;
    }

    /**
     * Creates a new forum discussion using forum_add_discussion().
     *
     * @param  int      $userid
     * @param  int      $courseid
     * @param  \stdClass $activity
     * @return int      Number of actions written (1 on success, 0 on failure).
     */
    private function create_forum_discussion(int $userid, int $courseid, \stdClass $activity): int {
        global $DB;

        $forum = $DB->get_record('forum', ['id' => $activity->instanceid], '*', MUST_EXIST);

        $discussion              = new \stdClass();
        $discussion->course      = $courseid;
        $discussion->forum       = $activity->instanceid;
        $discussion->name        = $this->namegen->get_discussion_subject();
        $discussion->message     = $this->namegen->get_post_text();
        $discussion->messageformat = FORMAT_PLAIN;
        $discussion->messagetrust = 0;
        $discussion->attachmentid = null;
        $discussion->timelocked  = 0;
        $discussion->mailnow     = 0;
        $discussion->timestart   = 0;
        $discussion->timeend     = 0;

        $switcher = new user_switcher($userid);
        try {
            $discussionid = \forum_add_discussion($discussion);
        } finally {
            $switcher->restore();
        }

        if (!$discussionid) {
            mtrace("    Warning: forum_add_discussion() returned false for user $userid in forum {$activity->instanceid}");
            return 0;
        }

        // The first post of the discussion is the one with parent=0.
        $firstpost = $DB->get_record_select(
            'forum_posts',
            'discussion = :did AND parent = 0',
            ['did' => $discussionid]
        );

        $this->log_writer->record_api_action(
            $userid, $courseid, 'post_forum', $activity,
            $firstpost ? (int)$firstpost->id : null,
            'posted'
        );

        return 1;
    }

    /**
     * Replies to a randomly chosen discussion started by another student.
     *
     * If no other discussions exist yet (student is the first to act in this
     * forum), skips gracefully and returns 0.
     *
     * @param  int      $userid
     * @param  int      $courseid
     * @param  \stdClass $activity
     * @return int      Number of actions written (1 on success, 0 if no target).
     */
    private function reply_to_forum_discussion(int $userid, int $courseid, \stdClass $activity): int {
        global $DB;

        $others = $this->scanner->get_other_discussions($activity->instanceid, $userid);

        if (empty($others)) {
            // No other discussions to reply to yet — skip gracefully.
            $this->log_writer->record_api_action(
                $userid, $courseid, 'reply_forum', $activity, null, 'no_reply_target'
            );
            return 0;
        }

        // Pick a random discussion to reply to.
        $target_discussion = $others[array_rand($others)];

        // Get the first post of that discussion as the parent.
        $parent_post = $DB->get_record_select(
            'forum_posts',
            'discussion = :did AND parent = 0',
            ['did' => $target_discussion->id],
            '*',
            MUST_EXIST
        );

        $post              = new \stdClass();
        $post->discussion  = $target_discussion->id;
        $post->parent      = $parent_post->id;
        $post->userid      = $userid;
        $post->message     = $this->namegen->get_post_text();
        $post->messageformat = FORMAT_PLAIN;
        $post->messagetrust  = 0;
        $post->attachments   = null;
        $post->mailnow       = 0;

        $switcher = new user_switcher($userid);
        try {
            $postid = \forum_add_post($post);
        } finally {
            $switcher->restore();
        }

        if (!$postid) {
            mtrace("    Warning: forum_add_post() returned false for user $userid");
            return 0;
        }

        $this->log_writer->record_api_action(
            $userid, $courseid, 'reply_forum', $activity,
            (int)$postid,
            'posted'
        );

        return 1;
    }

    // =========================================================================
    // Private: assignment
    // =========================================================================

    /**
     * Assignment: submit (active) or view only (passive).
     *
     * Uses the assign API for submissions. Submits online text content
     * generated by name_generator.
     *
     * @return int
     */
    private function simulate_assignment(
        int $userid,
        int $courseid,
        \stdClass $activity,
        int $window_index,
        base_learner_profile $profile
    ): int {
        global $DB;

        $written = 0;

        if ($profile->should_engage(base_learner_profile::ACTION_ACTIVE, $window_index, $this->total_windows)) {

            // Check if student has already submitted this assignment.
            $existing = $DB->get_record('assign_submission', [
                'assignment' => $activity->instanceid,
                'userid'     => $userid,
                'status'     => 'submitted',
            ]);

            if ($existing) {
                // Already submitted — view only.
                $switcher = new user_switcher($userid);
                try {
                    $this->log_writer->fire_view_event($userid, $courseid, 'view_assignment', $activity, $activity->instanceid);
                } finally {
                    $switcher->restore();
                }
                return 1;
            }

            // Submit via assign API.
            $switcher = new user_switcher($userid);
            try {
                $assign = new \assign(\context_module::instance($activity->cmid), null, null);
                $submissionid = $this->do_assign_submission($assign, $userid, $activity);
            } finally {
                $switcher->restore();
            }

            if ($submissionid) {
                $this->log_writer->record_api_action(
                    $userid, $courseid, 'submit_assignment', $activity,
                    $submissionid, 'submitted'
                );
                $written++;
            }

        } else if ($profile->should_engage(base_learner_profile::ACTION_PASSIVE, $window_index, $this->total_windows)) {
            // Viewed but did not submit.
            $switcher = new user_switcher($userid);
            try {
                $this->log_writer->fire_view_event(
                    $userid, $courseid, 'view_assignment', $activity,
                    $activity->instanceid, 'viewed_only'
                );
            } finally {
                $switcher->restore();
            }
            $written++;
        }

        return $written;
    }

    /**
     * Creates an online text submission for the given assignment.
     *
     * Uses the assign API's save_submission() pathway, which is the
     * same route the student-facing form uses. This ensures the submission
     * record, submission plugin record, and event all fire correctly.
     *
     * @param  \assign   $assign
     * @param  int       $userid
     * @param  \stdClass $activity
     * @return int|null  assign_submission.id on success, null on failure.
     */
    private function do_assign_submission(\assign $assign, int $userid, \stdClass $activity): ?int {
        global $DB;

        // Build the submission data object that assign expects.
        // This replicates the structure that mod/assign/locallib.php uses
        // when processing a submission form.
        $data                            = new \stdClass();
        $data->onlinetext_editor         = [
            'text'   => $this->namegen->get_post_text(),
            'format' => FORMAT_PLAIN,
            'itemid' => 0,
        ];

        // save_submission() writes the submission record and fires the event.
        // It expects $USER to be the submitting student (set by user_switcher).
        try {
            $assign->save_submission($data, $notices);
        } catch (\Throwable $e) {
            mtrace("    Warning: assign save_submission() failed for user $userid: " . $e->getMessage());
            return null;
        }

        // Retrieve the submission record we just created.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $activity->instanceid,
            'userid'     => $userid,
        ]);

        if (!$submission) {
            return null;
        }

        // Mark as submitted (save_submission saves as draft by default).
        $submission->status = ASSIGN_SUBMISSION_STATUS_SUBMITTED;
        $DB->update_record('assign_submission', $submission);

        return (int)$submission->id;
    }

    // =========================================================================
    // Private: announcements
    // =========================================================================

    /**
     * Reads unread announcements posted by instructors.
     *
     * Each unread announcement discussion fires a discussion_viewed event,
     * which populates forum_read and writes to the logstore.
     *
     * @param  int                  $userid
     * @param  int                  $courseid
     * @param  int                  $window_index
     * @param  base_learner_profile $profile
     * @return int
     */
    private function simulate_announcements(
        int $userid,
        int $courseid,
        int $window_index,
        base_learner_profile $profile
    ): int {
        $written = 0;

        $announcements_cm = $this->scanner->get_announcements_forum();
        if ($announcements_cm === null) {
            return 0;
        }

        // Build a minimal activity descriptor for the announcements forum.
        $activity             = new \stdClass();
        $activity->type       = 'forum';
        $activity->cmid       = (int)$announcements_cm->id;
        $activity->instanceid = (int)$announcements_cm->instance;
        $activity->name       = $announcements_cm->name;
        $activity->section    = 0;
        $activity->duedate    = null;

        $unread = $this->scanner->get_unread_discussions((int)$announcements_cm->instance, $userid);

        foreach ($unread as $disc) {
            if (!$profile->should_engage(base_learner_profile::ACTION_PASSIVE, $window_index, $this->total_windows)) {
                continue;
            }

            // discussion_viewed requires relateduserid = discussion author.
            // Directed edge for SNA: student -> instructor who posted announcement.
            $authorid = (int)$disc->authorid;
            if ($authorid === 0) {
                mtrace("    Warning: announcement {$disc->discussionid} has no authorid, skipping read_announcement event");
                continue;
            }
            $switcher = new user_switcher($userid);
            try {
                $this->log_writer->fire_view_event(
                    $userid, $courseid,
                    'read_announcement',
                    $activity,
                    (int)$disc->discussionid,
                    null,
                    $authorid
                );
            } finally {
                $switcher->restore();
            }

            $written++;
        }

        return $written;
    }

    // =========================================================================
    // Private: grade-view weighting
    // =========================================================================

    /**
     * Returns true if the student should view grades this window.
     *
     * Probability scales linearly from 0 at window 0 to full passive
     * probability at the final window.
     *
     * @param  int                  $window_index
     * @param  base_learner_profile $profile
     * @return bool
     */
    private function should_view_grades(int $window_index, base_learner_profile $profile): bool {
        if ($this->total_windows <= 1) {
            return false;
        }

        $weight = $window_index / ($this->total_windows - 1);

        $base_p = $profile->get_base_probability(base_learner_profile::ACTION_PASSIVE)
                * $profile->get_diligence_scalar()
                * $weight;

        $base_p = max(0.0, min(1.0, $base_p));

        return (mt_rand(1, 1000) / 1000.0) <= $base_p;
    }

    // =========================================================================
    // Private: verbose helper
    // =========================================================================

    /** @var array<int,string> Username cache for verbose output. */
    private array $username_cache = [];

    /**
     * @param  int    $userid
     * @return string
     */
    private function get_username(int $userid): string {
        if (!isset($this->username_cache[$userid])) {
            global $DB;
            $this->username_cache[$userid] = $DB->get_field('user', 'username', ['id' => $userid]) ?? "user$userid";
        }
        return $this->username_cache[$userid];
    }
}
