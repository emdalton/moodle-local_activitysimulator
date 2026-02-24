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
 * Overachiever learner profile.
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
 * Overachiever archetype: high engagement, minimal decay.
 *
 * Overachievers view nearly all content and submit nearly all work.
 * Their engagement stays high throughout the term with very little
 * fall-off toward the end. Diligence scalars are drawn from a tight
 * distribution near the top of the range (mean 0.94, stddev 0.05).
 *
 * Typical analytics signature: consistent high activity across all
 * windows, high grades, few or no missed submissions.
 */
class overachiever extends base_learner_profile {

    /**
     * Base probabilities:
     *   passive — 0.97: views almost all content
     *   active  — 0.93: submits almost all work
     *
     * @param  string $action_class
     * @return float
     */
    public function get_base_probability(string $action_class): float {
        return $action_class === self::ACTION_ACTIVE ? 0.93 : 0.97;
    }

    /**
     * Minimal decay rate — engagement barely changes across the term.
     *
     * @return float
     */
    public function get_decay_rate(): float {
        return 0.05;
    }

    /**
     * High decay floor — even at end of term, engagement stays above 90%.
     *
     * @return float
     */
    public function get_decay_floor(): float {
        return 0.90;
    }

    /**
     * @return string
     */
    public function get_group_type(): string {
        return 'overachiever';
    }
}
