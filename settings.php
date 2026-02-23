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
 * Plugin administration settings.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    $settings = new admin_settingpage(
        'local_activitysimulator',
        get_string('pluginname', 'local_activitysimulator')
    );

    $ADMIN->add('localplugins', $settings);

    // -------------------------------------------------------------------------
    // Simulation control.
    // -------------------------------------------------------------------------

    $settings->add(new admin_setting_heading(
        'local_activitysimulator/heading_control',
        get_string('heading_control', 'local_activitysimulator'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_activitysimulator/enabled',
        get_string('enabled', 'local_activitysimulator'),
        get_string('enabled_desc', 'local_activitysimulator'),
        0   // Default: disabled until explicitly turned on.
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_activitysimulator/testmode',
        get_string('testmode', 'local_activitysimulator'),
        get_string('testmode_desc', 'local_activitysimulator'),
        0   // Default: off.
    ));

    // -------------------------------------------------------------------------
    // Term setup.
    // -------------------------------------------------------------------------

    $settings->add(new admin_setting_heading(
        'local_activitysimulator/heading_term',
        get_string('heading_term', 'local_activitysimulator'),
        ''
    ));

    // Course profile — determines section count, activity types, and window
    // schedule. Each profile is a PHP class in classes/course_profiles/.
    $settings->add(new admin_setting_configselect(
        'local_activitysimulator/course_profile',
        get_string('course_profile', 'local_activitysimulator'),
        get_string('course_profile_desc', 'local_activitysimulator'),
        'one_week_intensive',   // Default: rapid testing profile.
        [
            'one_week_intensive'     => get_string('profile_one_week_intensive', 'local_activitysimulator'),
            'eight_week_accelerated' => get_string('profile_eight_week_accelerated', 'local_activitysimulator'),
            'sixteen_week_semester'  => get_string('profile_sixteen_week_semester', 'local_activitysimulator'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_activitysimulator/courses_per_term',
        get_string('courses_per_term', 'local_activitysimulator'),
        get_string('courses_per_term_desc', 'local_activitysimulator'),
        10,
        PARAM_INT
    ));

    $days = [
        0 => get_string('sunday', 'calendar'),
        1 => get_string('monday', 'calendar'),
        2 => get_string('tuesday', 'calendar'),
        3 => get_string('wednesday', 'calendar'),
        4 => get_string('thursday', 'calendar'),
        5 => get_string('friday', 'calendar'),
        6 => get_string('saturday', 'calendar'),
    ];

    $settings->add(new admin_setting_configselect(
        'local_activitysimulator/term_start_day',
        get_string('term_start_day', 'local_activitysimulator'),
        get_string('term_start_day_desc', 'local_activitysimulator'),
        1,  // Default: Monday.
        $days
    ));

    $settings->add(new admin_setting_configselect(
        'local_activitysimulator/setup_day',
        get_string('setup_day', 'local_activitysimulator'),
        get_string('setup_day_desc', 'local_activitysimulator'),
        6,  // Default: Saturday.
        $days
    ));

    // Backfill: when creating a new term with a past start date, simulate all
    // elapsed windows immediately rather than waiting for scheduled task runs.
    $settings->add(new admin_setting_configcheckbox(
        'local_activitysimulator/backfill_on_create',
        get_string('backfill_on_create', 'local_activitysimulator'),
        get_string('backfill_on_create_desc', 'local_activitysimulator'),
        1   // Default: on — most useful behaviour for analytics testing.
    ));

    // When backfilling, how far back (in weeks) to allow a term start date.
    // Prevents accidentally backfilling years of data.
    $settings->add(new admin_setting_configtext(
        'local_activitysimulator/backfill_max_weeks',
        get_string('backfill_max_weeks', 'local_activitysimulator'),
        get_string('backfill_max_weeks_desc', 'local_activitysimulator'),
        20,     // Default: 20 weeks — covers a full semester plus buffer.
        PARAM_INT
    ));

    // -------------------------------------------------------------------------
    // User population.
    // -------------------------------------------------------------------------

    $settings->add(new admin_setting_heading(
        'local_activitysimulator/heading_users',
        get_string('heading_users', 'local_activitysimulator'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_activitysimulator/students_per_course',
        get_string('students_per_course', 'local_activitysimulator'),
        get_string('students_per_course_desc', 'local_activitysimulator'),
        100,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_activitysimulator/instructors_per_course',
        get_string('instructors_per_course', 'local_activitysimulator'),
        get_string('instructors_per_course_desc', 'local_activitysimulator'),
        2,
        PARAM_INT
    ));

    // Group distribution percentages — must sum to 100.
    // Validated (with a warning) in term_manager.php at term creation time.
    $settings->add(new admin_setting_heading(
        'local_activitysimulator/heading_groups',
        get_string('heading_groups', 'local_activitysimulator'),
        get_string('heading_groups_desc', 'local_activitysimulator')
    ));

    foreach (['overachiever', 'standard', 'intermittent', 'failing'] as $group) {
        $default = ($group === 'standard') ? 70 : 10;
        $settings->add(new admin_setting_configtext(
            'local_activitysimulator/group_pct_' . $group,
            get_string('group_pct_' . $group, 'local_activitysimulator'),
            get_string('group_pct_desc', 'local_activitysimulator'),
            $default,
            PARAM_INT
        ));
    }

    // -------------------------------------------------------------------------
    // Diligence scalar ranges.
    // -------------------------------------------------------------------------

    $settings->add(new admin_setting_heading(
        'local_activitysimulator/heading_diligence',
        get_string('heading_diligence', 'local_activitysimulator'),
        get_string('heading_diligence_desc', 'local_activitysimulator')
    ));

    $diligence_defaults = [
        'overachiever'  => ['mean' => '0.94', 'stddev' => '0.05'],
        'standard'      => ['mean' => '0.73', 'stddev' => '0.08'],
        'intermittent'  => ['mean' => '0.45', 'stddev' => '0.10'],
        'failing'       => ['mean' => '0.18', 'stddev' => '0.09'],
    ];

    foreach ($diligence_defaults as $group => $defaults) {
        $settings->add(new admin_setting_configtext(
            'local_activitysimulator/diligence_mean_' . $group,
            get_string('diligence_mean_' . $group, 'local_activitysimulator'),
            '',
            $defaults['mean'],
            PARAM_FLOAT
        ));
        $settings->add(new admin_setting_configtext(
            'local_activitysimulator/diligence_stddev_' . $group,
            get_string('diligence_stddev_' . $group, 'local_activitysimulator'),
            '',
            $defaults['stddev'],
            PARAM_FLOAT
        ));
    }

    // -------------------------------------------------------------------------
    // Activity window timing.
    // -------------------------------------------------------------------------

    $settings->add(new admin_setting_heading(
        'local_activitysimulator/heading_timing',
        get_string('heading_timing', 'local_activitysimulator'),
        get_string('heading_timing_desc', 'local_activitysimulator')
    ));

    $settings->add(new admin_setting_configtext(
        'local_activitysimulator/am_window_start',
        get_string('am_window_start', 'local_activitysimulator'),
        get_string('am_window_start_desc', 'local_activitysimulator'),
        '08:00',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_activitysimulator/am_window_end',
        get_string('am_window_end', 'local_activitysimulator'),
        get_string('am_window_end_desc', 'local_activitysimulator'),
        '12:00',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_activitysimulator/pm_window_start',
        get_string('pm_window_start', 'local_activitysimulator'),
        get_string('pm_window_start_desc', 'local_activitysimulator'),
        '13:00',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_activitysimulator/pm_window_end',
        get_string('pm_window_end', 'local_activitysimulator'),
        get_string('pm_window_end_desc', 'local_activitysimulator'),
        '17:00',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configselect(
        'local_activitysimulator/timezone',
        get_string('timezone', 'local_activitysimulator'),
        get_string('timezone_desc', 'local_activitysimulator'),
        '99',   // 99 = use Moodle site timezone.
        core_date::get_list_of_timezones(null, true)
    ));

}
