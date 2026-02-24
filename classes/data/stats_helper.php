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
 * Statistical helper functions for the activity simulator.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

namespace local_activitysimulator\data;

defined('MOODLE_INTERNAL') || die();

/**
 * Provides statistical sampling methods used across the simulation engine.
 *
 * Currently used by:
 *   - user_manager     — generating per-user diligence scalars at creation time
 *   - learner_profiles — future: sampling engagement probabilities with noise
 *
 * All methods are static. This class has no state.
 */
class stats_helper {

    /**
     * Draws a single sample from a truncated normal distribution.
     *
     * Uses the Box-Muller transform to generate a normally distributed
     * value, then rejects and resamples any value outside [$min, $max].
     * This is the rejection-sampling approach: theoretically unbounded in
     * the worst case, but in practice converges in 1–3 iterations for the
     * parameter ranges used in this plugin (stddev is always much smaller
     * than the range width).
     *
     * The diligence scalar ranges used by each learner group are:
     *
     *   Overachiever:  mean 0.94, stddev 0.05, range [0.85, 1.00]
     *   Standard:      mean 0.73, stddev 0.08, range [0.60, 0.85]
     *   Intermittent:  mean 0.45, stddev 0.10, range [0.30, 0.60]
     *   Failing:       mean 0.18, stddev 0.09, range [0.05, 0.35]
     *
     * For these parameters the probability of a single draw falling outside
     * the range is low (< 5%), so the rejection loop is rarely needed.
     *
     * @param  float $mean   Centre of the distribution.
     * @param  float $stddev Standard deviation. Must be > 0.
     * @param  float $min    Lower bound (inclusive). Sample will be >= $min.
     * @param  float $max    Upper bound (inclusive). Sample will be <= $max.
     * @param  int   $maxiter Safety limit on rejection loop iterations.
     *                        If reached, $mean is returned as a fallback.
     * @return float
     */
    public static function truncated_normal(
        float $mean,
        float $stddev,
        float $min,
        float $max,
        int   $maxiter = 100
    ): float {
        if ($stddev <= 0) {
            throw new \coding_exception('stats_helper::truncated_normal: stddev must be > 0');
        }
        if ($min >= $max) {
            throw new \coding_exception('stats_helper::truncated_normal: min must be < max');
        }

        for ($i = 0; $i < $maxiter; $i++) {
            $sample = self::normal($mean, $stddev);
            if ($sample >= $min && $sample <= $max) {
                return $sample;
            }
        }

        // Safety fallback: return the mean clamped to range.
        // This should never be reached for the parameter ranges in this plugin.
        return max($min, min($max, $mean));
    }

    /**
     * Draws a single sample from an unbounded normal distribution using
     * the Box-Muller transform.
     *
     * Box-Muller produces two independent standard normal values from two
     * uniform random values. We use only one of the pair and discard the
     * other, keeping the implementation simple. If performance becomes a
     * concern (very large user pools), this could be changed to cache the
     * second value.
     *
     * @param  float $mean   Mean of the distribution.
     * @param  float $stddev Standard deviation. Caller is responsible for
     *                       ensuring this is > 0.
     * @return float
     */
    private static function normal(float $mean, float $stddev): float {
        // Generate two uniform random values in (0, 1).
        // mt_rand gives integers; divide by mt_getrandmax() for floats.
        // We add 1 to numerator and denominator to avoid log(0).
        do {
            $u1 = (mt_rand() + 1) / (mt_getrandmax() + 2);
            $u2 = (mt_rand() + 1) / (mt_getrandmax() + 2);
        } while ($u1 <= 0); // Paranoia: log(0) guard.

        // Box-Muller transform.
        $z = sqrt(-2.0 * log($u1)) * cos(2.0 * M_PI * $u2);

        return $mean + $stddev * $z;
    }

    /**
     * Rounds a float to a given number of decimal places.
     *
     * Convenience wrapper used when storing diligence scalars, which are
     * defined to 3 decimal places in the database schema (DECIMAL 4,3).
     *
     * @param  float $value
     * @param  int   $places
     * @return float
     */
    public static function round_to(float $value, int $places = 3): float {
        return round($value, $places);
    }
}
