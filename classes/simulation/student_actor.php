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

use local_activitysimulator\learner_profiles\base_learner_profile;
use local_activitysimulator\data\name_generator;

/**
 * Simulates the actions of a single student in a single activity window.
 *
 * Called by window_runner once per enrolled student per window. Decides
 * which actions to simulate based on the student's learner profile and
 * writes them via log_writer.
 *
 * ACTION SEQUENCE
 * ---------------
 * For each window a student is present in, the sequence is:
 *
 * 1. View course (passive) — entry point to the LMS session. Skipped only
 *    if the student does not engage with anything in this window at all.
 *    Determined by a single passive engagement roll before the activity loop.
 *
 * 2. For each activity in the section (in section order):
 *
 *    Page:
 *      passive roll → view_page
 *
 *    Quiz:
 *      active roll  → attempt_quiz + submit_quiz
 *      passive roll → view_quiz_grade  (only if active succeeded)
 *      (no passive-only path for quiz — a student either attempts or skips)
 *
 *    Assignment:
 *      active roll  → view_assignment + submit_assignment
 *      else passive roll → view_assignment  (looked but didn't submit)
 *
 *    Forum:
 *      active roll  → post_forum (with generated text) + read_forum
 *      else passive roll → view_forum  (read without posting)
 *
 * 3. View grades (passive, late-term weighted) — probability increases
 *    linearly from 0 at window 0 to full passive probability at the final
 *    window. Reflects the realistic pattern of grade-checking becoming more
 *    common as assessments accumulate.
 *
 * 4. Read announcements (passive) — only if an announcement was posted by
 *    the instructor in this window. The instructor_actor sets this flag on
 *    the window context object passed to simulate().
 *
 * EARLY EXIT
 * ----------
 * If the initial passive engagement roll fails, the student does nothing
 * this window. No log entries are written. This is the primary mechanism
 * by which failing and intermittent students generate sparse activity
 * patterns.
 *
 * FORUM TEXT
 * ----------
 * Forum posts use text from name_generator::get_post_text(), which cycles
 * through the 26 Lear verses. Post content has no semantic meaning — it
 * exists only to populate the forum_posts table with plausible-length text.
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

    /**
     * Constructor.
     *
     * @param log_writer      $log_writer
     * @param content_scanner $scanner
     * @param name_generator  $namegen
     * @param int             $total_windows Total windows in the term.
     */
    public function __construct(
        log_writer $log_writer,
        content_scanner $scanner,
        name_generator $namegen,
        int $total_windows
    ) {
        $this->log_writer    = $log_writer;
        $this->scanner       = $scanner;
        $this->namegen       = $namegen;
        $this->total_windows = $total_windows;
    }

    /**
     * Simulates one student's actions in one window.
     *
     * @param  int                $userid        Moodle user ID.
     * @param  int                $courseid      Moodle course ID.
     * @param  int                $section       1-based section number for this window.
     * @param  int                $window_index  0-based window position within term.
     * @param  base_learner_profile $profile     The student's learner profile instance.
     * @param  bool               $announcement_posted  True if instructor posted an
     *                                           announcement this window.
     * @return int                Number of log entries written (0 = student skipped window).
     */
    public function simulate(
        int $userid,
        int $courseid,
        int $section,
        int $window_index,
        base_learner_profile $profile,
        bool $announcement_posted = false
    ): int {
        $written = 0;

        // Initial engagement roll — if the student doesn't engage at all,
        // exit immediately with no log entries.
        if (!$profile->should_engage(base_learner_profile::ACTION_PASSIVE, $window_index, $this->total_windows)) {
            return 0;
        }

        // 1. View course — the student has shown up.
        $this->log_writer->write_action($userid, $courseid, 'view_course');
        $written++;

        // 2. Activities in this section.
        $activities = $this->scanner->get_activities_in_section($section);

        foreach ($activities as $activity) {
            $written += $this->simulate_activity($userid, $courseid, $activity, $window_index, $profile);
        }

        // 3. View grades — passive, weighted toward later windows.
        if ($this->should_view_grades($window_index, $profile)) {
            $this->log_writer->write_action($userid, $courseid, 'view_grades');
            $written++;
        }

        // 4. Read announcements — only if instructor posted one this window.
        if ($announcement_posted) {
            if ($profile->should_engage(base_learner_profile::ACTION_PASSIVE, $window_index, $this->total_windows)) {
                $this->log_writer->write_action($userid, $courseid, 'read_announcement');
                $written++;
            }
        }

        return $written;
    }

    // -------------------------------------------------------------------------
    // Private: per-activity simulation
    // -------------------------------------------------------------------------

    /**
     * Simulates a student's interaction with a single activity.
     *
     * @param  int                  $userid
     * @param  int                  $courseid
     * @param  \stdClass            $activity   Descriptor from content_scanner.
     * @param  int                  $window_index
     * @param  base_learner_profile $profile
     * @return int                  Number of log entries written.
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
            case 'quiz':
                return $this->simulate_quiz($userid, $courseid, $activity, $window_index, $profile);
            case 'assignment':
                return $this->simulate_assignment($userid, $courseid, $activity, $window_index, $profile);
            case 'forum':
                return $this->simulate_forum($userid, $courseid, $activity, $window_index, $profile);
            default:
                return 0;
        }
    }

    /**
     * Page: passive view only.
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
        $this->log_writer->write_action($userid, $courseid, 'view_page', $activity);
        return 1;
    }

    /**
     * Quiz: active attempt + submit, with optional passive grade review.
     *
     * No passive-only path — a student either attempts the quiz or skips it.
     * Grade review only happens if the attempt succeeded.
     *
     * @return int
     */
    private function simulate_quiz(
        int $userid,
        int $courseid,
        \stdClass $activity,
        int $window_index,
        base_learner_profile $profile
    ): int {
        $written = 0;

        if (!$profile->should_engage(base_learner_profile::ACTION_ACTIVE, $window_index, $this->total_windows)) {
            return 0;
        }

        $this->log_writer->write_action($userid, $courseid, 'attempt_quiz', $activity, $activity->instanceid);
        $written++;

        $this->log_writer->write_action($userid, $courseid, 'submit_quiz', $activity, $activity->instanceid, 'submitted');
        $written++;

        // Passive follow-up: check grade after submitting.
        if ($profile->should_engage(base_learner_profile::ACTION_PASSIVE, $window_index, $this->total_windows)) {
            $this->log_writer->write_action($userid, $courseid, 'view_quiz_grade', $activity, $activity->instanceid);
            $written++;
        }

        return $written;
    }

    /**
     * Assignment: active submit, or passive view-only.
     *
     * A student who doesn't submit may still view the assignment brief —
     * this is the "looked but didn't submit" pattern that is analytically
     * significant for intermittent and failing learners.
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
        $written = 0;

        if ($profile->should_engage(base_learner_profile::ACTION_ACTIVE, $window_index, $this->total_windows)) {
            // View then submit.
            $this->log_writer->write_action($userid, $courseid, 'view_assignment', $activity);
            $written++;
            $this->log_writer->write_action($userid, $courseid, 'submit_assignment', $activity, $activity->instanceid, 'submitted');
            $written++;
        } else if ($profile->should_engage(base_learner_profile::ACTION_PASSIVE, $window_index, $this->total_windows)) {
            // Viewed but did not submit.
            $this->log_writer->write_action($userid, $courseid, 'view_assignment', $activity, null, 'viewed_only');
            $written++;
        }

        return $written;
    }

    /**
     * Forum: read unread posts (passive), reply to some (active), post new (active).
     *
     * Queries forum_read and forum_posts at execution time to find posts this
     * user has not yet read. Students who execute later in the window naturally
     * see more unread posts from peers who executed earlier.
     *
     * For each unread post:
     *   passive roll → write_forum_read() + read_forum logstore entry (relateduserid = post author)
     *   if read: active roll → reply_forum logstore entry (relateduserid = post author)
     *
     * New post (active roll, independent of reads):
     *   active roll → post_forum with generated text
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
        global $DB;

        $written = 0;

        // --- Read unread posts ---
        $sql = "SELECT fp.id AS postid, fp.userid AS authorid,
                       fd.id AS discussionid, f.id AS forumid
                  FROM {forum_posts} fp
                  JOIN {forum_discussions} fd ON fd.id = fp.discussion
                  JOIN {forum} f ON f.id = fd.forum
             LEFT JOIN {forum_read} fr ON fr.postid = fp.id AND fr.userid = :userid
                 WHERE f.id = :forumid
                   AND fp.userid != :userid2
                   AND fr.id IS NULL
              ORDER BY fp.created ASC";

        $unread = $DB->get_records_sql($sql, [
            'userid'  => $userid,
            'forumid' => $activity->instanceid,
            'userid2' => $userid,
        ]);

        foreach ($unread as $post) {
            // Passive roll: read this post.
            if (!$profile->should_engage(base_learner_profile::ACTION_PASSIVE, $window_index, $this->total_windows)) {
                continue;
            }

            // Mark read in Moodle's forum_read table.
            $this->log_writer->write_forum_read(
                $userid,
                (int)$post->postid,
                (int)$post->discussionid,
                (int)$post->forumid
            );

            // Logstore entry with relateduserid for SNA.
            $this->log_writer->write_action(
                $userid,
                $courseid,
                'read_forum',
                $activity,
                (int)$post->postid,
                null,
                (int)$post->authorid
            );
            $written++;

            // Active roll: reply to this post.
            if ($profile->should_engage(base_learner_profile::ACTION_ACTIVE, $window_index, $this->total_windows)) {
                $this->log_writer->write_action(
                    $userid,
                    $courseid,
                    'reply_forum',
                    $activity,
                    (int)$post->postid,
                    $this->namegen->get_post_text(),
                    (int)$post->authorid
                );
                $written++;
            }
        }

        // --- Post new content (independent active roll) ---
        if ($profile->should_engage(base_learner_profile::ACTION_ACTIVE, $window_index, $this->total_windows)) {
            $this->log_writer->write_action(
                $userid,
                $courseid,
                'post_forum',
                $activity,
                null,
                $this->namegen->get_post_text()
            );
            $written++;
        }

        return $written;
    }

    // -------------------------------------------------------------------------
    // Private: grade-view weighting
    // -------------------------------------------------------------------------

    /**
     * Returns true if the student should view their grades this window.
     *
     * Probability scales linearly from 0 at window 0 to the student's full
     * passive engagement probability at the final window. This reflects the
     * realistic pattern of grade-checking becoming more common as assessments
     * accumulate through the term.
     *
     * @param  int                  $window_index
     * @param  base_learner_profile $profile
     * @return bool
     */
    private function should_view_grades(int $window_index, base_learner_profile $profile): bool {
        if ($this->total_windows <= 1) {
            return false;
        }

        // Linear weight: 0.0 at first window, 1.0 at last window.
        $weight = $window_index / ($this->total_windows - 1);

        // Apply weight as an additional multiplier on a passive engagement roll.
        $base_p = $profile->get_base_probability(base_learner_profile::ACTION_PASSIVE)
                * $profile->get_diligence_scalar()
                * $weight;

        $base_p = max(0.0, min(1.0, $base_p));

        return (mt_rand(1, 1000) / 1000.0) <= $base_p;
    }
}
