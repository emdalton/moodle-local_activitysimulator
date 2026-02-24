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
 * Failing learner profile.
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
 * Failing archetype: low engagement, steep early decay.
 *
 * Failing learners have low base engagement that drops off steeply and
 * quickly. Even passive activity (page views) is unreliable. By the
 * middle of a term their effective probability of any engagement is very
 * low. Diligence scalars are drawn from the lowest distribution
 * (mean 0.18, stddev 0.09).
 *
 * Typical analytics signature: sparse early activity, almost no
 * submissions after the first few windows, occasional isolated page views
 * late in the term. This pattern closely mirrors real at-risk learner
 * behaviour and should be detectable by early-warning analytics tools.
 *
 * Note: even at the decay floor, some engagement still occurs. Complete
 * non-participation would require a diligence_scalar at the very bottom
 * of the range combined with late-term windows. This is realistic —
 * few learners truly do nothing; most have at least sporadic contact.
 */
class failing extends base_learner_profile {

    /**
     * Base probabilities:
     *   passive — 0.45: views content sporadically
     *   active  — 0.15: rarely submits work
     *
     * @param  string $action_class
     * @return float
     */
    public function get_base_probability(string $action_class): float {
        return $action_class === self::ACTION_ACTIVE ? 0.15 : 0.45;
    }

    /**
     * Steep decay rate — rapid disengagement early in the term.
     *
     * @return float
     */
    public function get_decay_rate(): float {
        return 0.80;
    }

    /**
     * Very low decay floor — engagement can fall to 5% of base.
     *
     * @return float
     */
    public function get_decay_floor(): float {
        return 0.05;
    }

    /**
     * @return string
     */
    public function get_group_type(): string {
        return 'failing';
    }
}
