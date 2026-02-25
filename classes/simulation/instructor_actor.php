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
 * Instructor actor for the activity simulator.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

namespace local_activitysimulator\simulation;

defined('MOODLE_INTERNAL') || die();

use local_activitysimulator\data\name_generator;

/**
 * Simulates the actions of a single instructor in a single activity window.
 *
 * Called by window_runner once per instructor per window, after all student
 * actors have run. Running after students ensures the instructor can see and
 * respond to student forum posts made during this window.
 *
 * INSTRUCTOR PROFILES
 * -------------------
 * Phase 1 implements three profile types as a string parameter. A full
 * abstract class hierarchy (analogous to base_learner_profile) is planned
 * for a later phase when instructor behaviour becomes more complex.
 *
 *   'responsive'    Posts every window. Reads all unread forum posts.
 *                   Replies to 50% of posts read. Grades assignments in
 *                   the same window.
 *
 *   'delayed'       Posts 80% of windows. Reads 60% of unread posts.
 *                   Replies to 20% of posts read. Grades 60% of windows.
 *
 *   'unresponsive'  Posts 30% of windows. Reads 20% of unread posts.
 *                   Replies to 5% of posts read. Grades 20% of windows.
 *
 * Note on delayed grading: in Phase 1, 'delayed' instructors grade at a
 * lower probability within the current window rather than tracking a queue
 * across windows. Full cross-window delayed grading is a Phase 2 concern.
 *
 * ACTION SEQUENCE
 * ---------------
 * 1. Post announcement (profile-dependent probability)
 * 2. Read unread forum posts in the section forum (profile-dependent probability
 *    per post), with replies at profile-dependent probability
 * 3. Grade assignments (profile-dependent probability)
 * 4. View gradebook (always, if grading occurred)
 *
 * FORUM READ BEHAVIOUR
 * --------------------
 * Like student_actor, the instructor queries forum_read and forum_posts at
 * execution time to find unread posts. Because instructor_actor runs after
 * all students in the window, the instructor sees all posts written this
 * window as well as any unread posts from prior windows.
 *
 * For each unread post:
 *   - Profile probability roll → write_forum_read() + read_forum logstore
 *     entry with relateduserid = post author (SNA: instructor-read-student edge)
 *   - If read: reply probability roll → reply_forum with relateduserid
 *     (SNA: instructor-replied-to-student edge)
 *
 * RETURN VALUE
 * ------------
 * simulate() returns a result object with:
 *   ->written           int   Total log entries written
 *   ->announcement_posted bool Whether an announcement was posted this window
 *
 * window_runner uses announcement_posted to set the flag passed to
 * student_actor on subsequent calls (for the read_announcement action).
 * Since instructor_actor runs after students, this flag applies to the
 * NEXT window's students, not the current one.
 */
class instructor_actor {

    /** @var float Reply probability for responsive instructors. */
    const RESPONSIVE_REPLY_PROBABILITY = 0.50;

    /**
     * Per-profile probability tables.
     * Keys: 'announce', 'read_post', 'reply_post', 'grade'
     */
    const PROFILE_PROBABILITIES = [
        'responsive'   => ['announce' => 1.00, 'read_post' => 1.00, 'reply_post' => 0.50, 'grade' => 1.00],
        'delayed'      => ['announce' => 0.80, 'read_post' => 0.60, 'reply_post' => 0.20, 'grade' => 0.60],
        'unresponsive' => ['announce' => 0.30, 'read_post' => 0.20, 'reply_post' => 0.05, 'grade' => 0.20],
    ];

    /** @var log_writer */
    private log_writer $log_writer;

    /** @var content_scanner */
    private content_scanner $scanner;

    /** @var name_generator */
    private name_generator $namegen;

    /** @var int Total windows in term. */
    private int $total_windows;

    /**
     * Constructor.
     *
     * @param log_writer      $log_writer
     * @param content_scanner $scanner
     * @param name_generator  $namegen
     * @param int             $total_windows
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
     * Simulates one instructor's actions in one window.
     *
     * @param  int    $userid        Moodle user ID of the instructor.
     * @param  int    $courseid      Moodle course ID.
     * @param  int    $section       1-based section number for this window.
     * @param  int    $window_index  0-based window position within term.
     * @param  string $profile_type  'responsive', 'delayed', or 'unresponsive'.
     * @return \stdClass             Result: ->written (int), ->announcement_posted (bool).
     */
    public function simulate(
        int $userid,
        int $courseid,
        int $section,
        int $window_index,
        string $profile_type = 'responsive'
    ): \stdClass {
        $probs   = self::PROFILE_PROBABILITIES[$profile_type]
                   ?? self::PROFILE_PROBABILITIES['responsive'];
        $written = 0;
        $announcement_posted = false;

        // 1. Post announcement.
        if ($this->roll($probs['announce'])) {
            $announcements_cm = $this->scanner->get_announcements_forum();
            if ($announcements_cm !== null) {
                $activity           = $this->cm_to_descriptor($announcements_cm, 0);
                $this->log_writer->write_action(
                    $userid,
                    $courseid,
                    'post_announcement',
                    $activity,
                    null,
                    $this->namegen->get_post_text()
                );
                $written++;
                $announcement_posted = true;
            }
        }

        // 2. Read and reply to unread forum posts in section forum.
        $activities = $this->scanner->get_activities_in_section($section);
        foreach ($activities as $activity) {
            if ($activity->type !== 'forum') {
                continue;
            }
            $written += $this->simulate_forum_reads($userid, $courseid, $activity, $probs);
        }

        // 3. Grade assignments in this section.
        if ($this->roll($probs['grade'])) {
            foreach ($activities as $activity) {
                if ($activity->type !== 'assignment') {
                    continue;
                }
                $this->log_writer->write_action(
                    $userid,
                    $courseid,
                    'grade_assignment',
                    $activity,
                    $activity->instanceid,
                    'graded'
                );
                $written++;
            }

            // 4. View gradebook after grading.
            $this->log_writer->write_action($userid, $courseid, 'view_gradebook');
            $written++;
        }

        $result = new \stdClass();
        $result->written              = $written;
        $result->announcement_posted  = $announcement_posted;
        return $result;
    }

    // -------------------------------------------------------------------------
    // Private: forum reads
    // -------------------------------------------------------------------------

    /**
     * Reads unread posts in a section forum, replies to some.
     *
     * @param  int       $userid
     * @param  int       $courseid
     * @param  \stdClass $activity  Forum activity descriptor.
     * @param  array     $probs     Probability table for this profile.
     * @return int       Log entries written.
     */
    private function simulate_forum_reads(
        int $userid,
        int $courseid,
        \stdClass $activity,
        array $probs
    ): int {
        global $DB;

        $written = 0;

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
            // Profile probability roll: read this post.
            if (!$this->roll($probs['read_post'])) {
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

            // Reply probability roll.
            if ($this->roll($probs['reply_post'])) {
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

        return $written;
    }

    // -------------------------------------------------------------------------
    // Private: helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true with the given probability.
     *
     * @param  float $probability 0.0–1.0.
     * @return bool
     */
    private function roll(float $probability): bool {
        return (mt_rand(1, 1000) / 1000.0) <= $probability;
    }

    /**
     * Converts a cm_info object to a minimal activity descriptor stdClass
     * compatible with log_writer::write_action().
     *
     * Used for the announcements forum, which is returned as a cm_info by
     * content_scanner::get_announcements_forum() rather than as a descriptor.
     *
     * @param  \cm_info $cm
     * @param  int      $section 1-based section number (0 for course header).
     * @return \stdClass
     */
    private function cm_to_descriptor(\cm_info $cm, int $section): \stdClass {
        $d             = new \stdClass();
        $d->type       = 'forum';
        $d->cmid       = (int)$cm->id;
        $d->instanceid = (int)$cm->instance;
        $d->name       = $cm->name;
        $d->section    = $section;
        $d->duedate    = null;
        return $d;
    }
}
