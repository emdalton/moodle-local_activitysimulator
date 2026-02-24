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
 * Standard learner profile.
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
 * Standard archetype: solid engagement, gentle decay.
 *
 * Standard learners view most content and submit most work, with a slight
 * drop in engagement toward the end of the term. They make up the majority
 * of the student pool (default 70%). Diligence scalars are drawn from a
 * mid-range distribution (mean 0.73, stddev 0.08).
 *
 * Typical analytics signature: steady activity with minor gaps, good
 * completion rates, occasional missed submission near end of term.
 */
class standard extends base_learner_profile {

    /**
     * Base probabilities:
     *   passive — 0.85: views most content
     *   active  — 0.72: submits most work
     *
     * @param  string $action_class
     * @return float
     */
    public function get_base_probability(string $action_class): float {
        return $action_class === self::ACTION_ACTIVE ? 0.72 : 0.85;
    }

    /**
     * Gentle decay rate — modest drop toward end of term.
     *
     * @return float
     */
    public function get_decay_rate(): float {
        return 0.20;
    }

    /**
     * Mid-range decay floor — engagement doesn't fall below ~65% of base.
     *
     * @return float
     */
    public function get_decay_floor(): float {
        return 0.65;
    }

    /**
     * @return string
     */
    public function get_group_type(): string {
        return 'standard';
    }
}
