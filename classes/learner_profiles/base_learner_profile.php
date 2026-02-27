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
 * Abstract base class for learner profiles.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

namespace local_activitysimulator\learner_profiles;

defined('MOODLE_INTERNAL') || die();

/**
 * Defines the engagement probability model used by all learner profile types.
 *
 * Each simulated user belongs to a group archetype (overachiever, standard,
 * intermittent, failing) and has a persistent diligence scalar assigned at
 * user creation time. Together these determine whether the user engages with
 * each activity in each window.
 *
 * ENGAGEMENT FORMULA
 * ------------------
 * For each user × activity × window, the probability of engagement is:
 *
 *   P = base_P(action_class) × diligence_scalar × decay(window_index, total_windows)
 *
 * Where:
 *   base_P         — group archetype's base probability for passive or active actions
 *   diligence_scalar — persistent per-user multiplier (0.05–1.00), from DB
 *   decay          — temporal factor; engagement falls off as the course progresses,
 *                    at a rate and floor defined per group archetype
 *
 * ACTION CLASSES
 * --------------
 * 'passive' — viewing content: page views, reading announcements, reading
 *             forum posts, viewing grades. Higher base probability than active.
 *             Research shows learners are more likely to view than submit.
 *
 * 'active'  — submitting work: quiz attempts, assignment submissions, forum
 *             posts and replies. Lower base probability, but a much stronger
 *             predictor of long-term success and persistence.
 *
 * This distinction is recorded in run_log.action_class and enables the
 * view/engage split in analytics reports.
 *
 * TEMPORAL DECAY
 * --------------
 * Engagement probability decreases over the course of a term. The shape
 * and depth of decay differs by group:
 *
 *   Overachievers:  minimal decay — engagement stays high throughout
 *   Standard:       gentle decay — slight drop toward end of term
 *   Intermittent:   pronounced decay — strong early, weak late
 *   Failing:        steep early decay — disengage quickly
 *
 * Decay formula: max(floor, 1.0 - rate × (window_index / total_windows))
 *
 * In a one-week intensive (10 windows) the effect is small but present.
 * In a 16-week semester (48+ windows) it produces analytically meaningful
 * early-warning signals.
 *
 * ADDING A NEW LEARNER PROFILE TYPE
 * ----------------------------------
 * 1. Create a new class in classes/learner_profiles/ extending this base.
 * 2. Implement get_base_probability(), get_decay_rate(), get_decay_floor(),
 *    and get_group_type().
 * 3. Add the group type string to user_manager's group definitions.
 * 4. The factory method for_user() will instantiate it automatically.
 */
abstract class base_learner_profile {

    /** Action class constant for passive (view) actions. */
    const ACTION_PASSIVE = 'passive';

    /** Action class constant for active (submit) actions. */
    const ACTION_ACTIVE = 'active';

    /** @var float Persistent diligence scalar for this user (0.05–1.00). */
    protected float $diligence_scalar;

    /**
     * Constructor.
     *
     * @param float $diligence_scalar Persistent per-user engagement multiplier.
     */
    public function __construct(float $diligence_scalar) {
        $this->diligence_scalar = $diligence_scalar;
    }

    // -------------------------------------------------------------------------
    // Abstract methods — each subclass defines its archetype values
    // -------------------------------------------------------------------------

    /**
     * Returns the base engagement probability for the given action class.
     *
     * @param  string $action_class One of ACTION_PASSIVE or ACTION_ACTIVE.
     * @return float                Probability in range (0, 1].
     */
    abstract public function get_base_probability(string $action_class): float;

    /**
     * Returns the decay rate for this group archetype.
     *
     * Higher values mean faster disengagement over the course of a term.
     * Used in: max(floor, 1.0 - rate × (window_index / total_windows))
     *
     * @return float
     */
    abstract public function get_decay_rate(): float;

    /**
     * Returns the decay floor for this group archetype.
     *
     * The minimum multiplier that decay can reach — engagement never falls
     * below base_P × diligence_scalar × floor, no matter how late in the term.
     *
     * @return float
     */
    abstract public function get_decay_floor(): float;

    /**
     * Returns the group type string for this archetype.
     *
     * Must match the value stored in
     * local_activitysimulator_learner_profiles.group_type.
     *
     * @return string e.g. 'overachiever', 'standard', 'intermittent', 'failing'
     */
    abstract public function get_group_type(): string;

    /**
     * Returns the maximum number of forum discussions this learner will read
     * in a single activity window.
     *
     * This caps the passive forum reading loop in student_actor. It reflects
     * the real-world pattern where different learner archetypes invest
     * different amounts of time reading peers' work:
     *
     *   overachiever  — reads every available discussion (PHP_INT_MAX)
     *   standard      — reads up to 2 (the typical course requirement)
     *   intermittent  — reads at most 1
     *   failing       — reads 0 (relies entirely on the base passive roll;
     *                    rarely engages with forum at all)
     *
     * @return int Maximum discussions to read per window (PHP_INT_MAX = unlimited).
     */
    abstract public function get_max_forum_reads_per_window(): int;

    // -------------------------------------------------------------------------
    // Concrete methods — shared logic for all subtypes
    // -------------------------------------------------------------------------

    /**
     * Returns true if this user should engage with an activity in this window.
     *
     * This is the single method called by student_actor for every user ×
     * activity × window combination. All probability logic is encapsulated
     * here so actors never need to know the internals.
     *
     * @param  string $action_class   ACTION_PASSIVE or ACTION_ACTIVE.
     * @param  int    $window_index   0-based position of this window within
     *                                the term (from term_manager::get_window_index()).
     * @param  int    $total_windows  Total windows in the term
     *                                (from base_profile::get_total_window_count()).
     * @return bool
     */
    public function should_engage(
        string $action_class,
        int $window_index,
        int $total_windows
    ): bool {
        $p = $this->get_base_probability($action_class)
           * $this->diligence_scalar
           * $this->decay($window_index, $total_windows);

        // Clamp to [0, 1] as a safety measure against misconfigured values.
        $p = max(0.0, min(1.0, $p));

        return (mt_rand(1, 1000) / 1000.0) <= $p;
    }

    /**
     * Returns the diligence scalar for this user.
     *
     * Exposed for logging and debugging purposes.
     *
     * @return float
     */
    public function get_diligence_scalar(): float {
        return $this->diligence_scalar;
    }

    /**
     * Calculates the temporal decay multiplier for a given window position.
     *
     * Formula: max(floor, 1.0 - rate × (window_index / total_windows))
     *
     * Returns 1.0 when window_index is 0 (start of course) and approaches
     * get_decay_floor() as window_index approaches total_windows.
     *
     * @param  int $window_index  0-based window position.
     * @param  int $total_windows Total windows in term.
     * @return float              Multiplier in [get_decay_floor(), 1.0].
     */
    protected function decay(int $window_index, int $total_windows): float {
        if ($total_windows <= 0) {
            return 1.0;
        }
        $position = $window_index / $total_windows;
        return max($this->get_decay_floor(), 1.0 - $this->get_decay_rate() * $position);
    }

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Instantiates the correct learner profile subclass for a given user.
     *
     * Reads group_type and diligence_scalar from
     * local_activitysimulator_learner_profiles. Returns null if no profile
     * row exists for the user (e.g. instructors).
     *
     * Class names are derived from group_type by convention:
     *   'overachiever' -> \local_activitysimulator\learner_profiles\overachiever
     *
     * @param  int $userid Moodle user ID.
     * @return self|null
     */
    public static function for_user(int $userid): ?self {
        global $DB;

        $record = $DB->get_record(
            'local_activitysimulator_learner_profiles',
            ['userid' => $userid]
        );

        if (!$record) {
            return null;
        }

        $classname = '\\local_activitysimulator\\learner_profiles\\' . $record->group_type;

        if (!class_exists($classname)) {
            throw new \coding_exception(
                "Learner profile class not found: $classname"
            );
        }

        return new $classname((float)$record->diligence_scalar);
    }
}
