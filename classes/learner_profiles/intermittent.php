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
 * Intermittent learner profile.
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
 * Intermittent archetype: moderate engagement, pronounced decay.
 *
 * Intermittent learners engage reasonably well early in the term but
 * disengage significantly as the term progresses. They view content more
 * reliably than they submit work — the passive/active gap is wider than
 * for standard learners. Diligence scalars are drawn from a lower
 * distribution (mean 0.45, stddev 0.10).
 *
 * Typical analytics signature: decent early activity, increasing gaps in
 * submissions mid-term, page views continuing after active submissions stop.
 * This last pattern — views without submissions — is an important
 * early-warning signal for predictive analytics tools.
 */
class intermittent extends base_learner_profile {

    /**
     * Base probabilities:
     *   passive — 0.65: views content reasonably often
     *   active  — 0.40: submits work less reliably
     *
     * The wider passive/active gap than standard learners reflects the
     * research finding that struggling learners continue viewing content
     * well after they stop submitting — a key early-warning signal.
     *
     * @param  string $action_class
     * @return float
     */
    public function get_base_probability(string $action_class): float {
        return $action_class === self::ACTION_ACTIVE ? 0.40 : 0.65;
    }

    /**
     * Pronounced decay rate — significant disengagement over the term.
     *
     * @return float
     */
    public function get_decay_rate(): float {
        return 0.50;
    }

    /**
     * Low decay floor — engagement can fall to 25% of base by end of term.
     *
     * @return float
     */
    public function get_decay_floor(): float {
        return 0.25;
    }

    /**
     * @return string
     */
    public function get_group_type(): string {
        return 'intermittent';
    }
}
