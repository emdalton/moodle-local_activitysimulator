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
 * English language strings for local_activitysimulator.
 *
 * @package     local_activitysimulator
 * @copyright   2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * Developed with assistance from Anthropic Claude (claude.ai).
 */

defined('MOODLE_INTERNAL') || die();

// -------------------------------------------------------------------------
// Required by Moodle for all plugins.
// -------------------------------------------------------------------------

$string['pluginname'] = 'Activity Simulator';

// -------------------------------------------------------------------------
// Scheduled task names — displayed in Admin > Server > Scheduled tasks.
// -------------------------------------------------------------------------

$string['task_setup_term']        = 'Activity Simulator: Set up next term';
$string['task_simulate_windows']  = 'Activity Simulator: Simulate pending activity windows';

// -------------------------------------------------------------------------
// Settings page — section headings.
// -------------------------------------------------------------------------

$string['heading_control']    = 'Simulation control';
$string['heading_term']       = 'Term setup';
$string['heading_users']      = 'User population';
$string['heading_groups']     = 'Learner group distribution';
$string['heading_groups_desc'] = 'Percentage of students assigned to each behaviour group. Values must sum to 100. A warning will be shown at term creation time if they do not.';
$string['heading_diligence']      = 'Diligence scalar ranges';
$string['heading_diligence_desc'] = 'Controls the bell-curve distribution of individual engagement levels within each learner group. Each user is assigned a persistent diligence scalar drawn from a truncated normal distribution using these parameters.';
$string['heading_timing']         = 'Activity window timing';
$string['heading_timing_desc']    = 'Defines the time-of-day ranges used when writing backdated log entries. All times are in 24-hour HH:MM format.';

// -------------------------------------------------------------------------
// Settings — simulation control.
// -------------------------------------------------------------------------

$string['enabled']      = 'Enable simulation';
$string['enabled_desc'] = 'When disabled, all scheduled tasks will exit immediately without making any changes. Use this to pause the simulation without uninstalling the plugin.';

$string['testmode']      = 'Test mode';
$string['testmode_desc'] = 'When enabled, completed activity windows can be re-run using the --force flag on CLI tools, and verbose logging is written to the Moodle log. Do not leave this enabled in production.';

// -------------------------------------------------------------------------
// Settings — term setup.
// -------------------------------------------------------------------------

$string['course_profile']      = 'Course profile';
$string['course_profile_desc'] = 'Determines the number of sections, activity types per section, and the activity window schedule for each simulated course. Each profile is a PHP class in classes/course_profiles/.';

$string['profile_one_week_intensive']     = 'One-week intensive (5 sections, 2 windows/day)';
$string['profile_eight_week_accelerated'] = 'Eight-week accelerated (8 sections, 3 windows/week)';
$string['profile_sixteen_week_semester']  = 'Sixteen-week semester (16 sections, 3 windows/week)';

$string['courses_per_term']      = 'Courses per term';
$string['courses_per_term_desc'] = 'Number of courses to create in each term. Each is a copy of the master course for the selected profile.';

$string['term_start_day']      = 'Term start day';
$string['term_start_day_desc'] = 'Day of the week on which each term begins. Defaults to Monday.';

$string['setup_day']      = 'Term setup day';
$string['setup_day_desc'] = 'Day of the week on which the setup task runs to create the next term\'s category, courses, and enrolments. Defaults to Saturday.';

$string['backfill_on_create']      = 'Backfill elapsed windows on term creation';
$string['backfill_on_create_desc'] = 'When a term is created with a start date in the past, immediately simulate all activity windows that have already elapsed, using backdated timestamps. This allows a full semester of realistic data to be generated in minutes. If disabled, only future windows will be simulated on their scheduled dates.';

$string['backfill_max_weeks']      = 'Maximum backfill duration (weeks)';
$string['backfill_max_weeks_desc'] = 'The furthest back (in weeks from today) that a term start date may be set when backfilling. Prevents accidental generation of unreasonably large datasets. Default is 20 weeks.';

// -------------------------------------------------------------------------
// Settings — user population.
// -------------------------------------------------------------------------

$string['students_per_course']      = 'Students per course';
$string['students_per_course_desc'] = 'Number of students enrolled in each simulated course. Students are drawn from the pool using a sliding window rotation, so each student appears in approximately (students_per_course × courses_per_term) / pool_size courses before top-up. At the default of 30 students, 10 courses, and a pool of 500, each student appears in at least 2 courses after the minimum-2 top-up.';

$string['instructors_per_course']      = 'Instructors per course';
$string['instructors_per_course_desc'] = 'Number of instructors assigned to each course. Instructors are distributed across courses so each teaches approximately the same number.';

// -------------------------------------------------------------------------
// Settings — learner group distribution.
// -------------------------------------------------------------------------

$string['group_pct_overachiever']  = 'Overachievers (%)';
$string['group_pct_standard']      = 'Standard (%)';
$string['group_pct_intermittent']  = 'Intermittent (%)';
$string['group_pct_failing']       = 'Failing (%)';
$string['group_pct_desc']          = 'Percentage of the student pool assigned to this group.';

$string['group_count_overachiever'] = 'Overachievers: pool size';
$string['group_count_standard']     = 'Standard: pool size';
$string['group_count_intermittent'] = 'Intermittent: pool size';
$string['group_count_failing']      = 'Failing: pool size';
$string['group_count_desc']         = 'Total number of simulated users to create in this group. Determines the user pool size. At defaults the pool is 500 students (50/350/50/50); increase proportionally if you need more variety across a larger number of courses or terms.';

// -------------------------------------------------------------------------
// Settings — diligence scalars.
// -------------------------------------------------------------------------

$string['diligence_mean_overachiever']  = 'Overachiever: mean diligence';
$string['diligence_mean_standard']      = 'Standard: mean diligence';
$string['diligence_mean_intermittent']  = 'Intermittent: mean diligence';
$string['diligence_mean_failing']       = 'Failing: mean diligence';

$string['diligence_stddev_overachiever']  = 'Overachiever: diligence std dev';
$string['diligence_stddev_standard']      = 'Standard: diligence std dev';
$string['diligence_stddev_intermittent']  = 'Intermittent: diligence std dev';
$string['diligence_stddev_failing']       = 'Failing: diligence std dev';

// -------------------------------------------------------------------------
// Settings — activity window timing.
// -------------------------------------------------------------------------

$string['am_window_start']      = 'AM window start';
$string['am_window_start_desc'] = 'Start of the morning activity window in 24-hour HH:MM format. Log entries for AM activities will have timestamps randomly distributed between this time and the AM window end.';

$string['am_window_end']      = 'AM window end';
$string['am_window_end_desc'] = 'End of the morning activity window in 24-hour HH:MM format.';

$string['pm_window_start']      = 'PM window start';
$string['pm_window_start_desc'] = 'Start of the afternoon activity window in 24-hour HH:MM format.';

$string['pm_window_end']      = 'PM window end';
$string['pm_window_end_desc'] = 'End of the afternoon activity window in 24-hour HH:MM format.';

$string['timezone']      = 'Simulation timezone';
$string['timezone_desc'] = 'Timezone used when calculating activity window timestamps. Select "Server timezone" to use the Moodle site timezone, which is recommended for most installations.';

// -------------------------------------------------------------------------
// Error and status messages — used by tasks and CLI tools.
// -------------------------------------------------------------------------

$string['error_plugin_disabled']       = 'Activity Simulator is disabled. Enable it in Site administration > Plugins > Local plugins > Activity Simulator.';
$string['error_group_pct_not_100']     = 'Learner group percentages sum to {$a}%, not 100%. Check the group distribution settings before creating a term.';
$string['error_backfill_too_far']      = 'Term start date is {$a} weeks in the past, which exceeds the maximum backfill duration. Increase the backfill limit or choose a more recent start date.';
$string['error_no_active_term']        = 'No active term found. Run the term setup task or CLI setup tool first.';
$string['error_profile_not_found']     = 'Course profile class not found: {$a}. Check the course_profile setting and ensure the profile class exists in classes/course_profiles/.';

$string['status_term_created']         = 'Term created: {$a}';
$string['status_window_simulated']     = 'Window simulated: {$a}';
$string['status_window_skipped']       = 'Window already complete, skipped: {$a}';
$string['status_window_forced']        = 'Window re-run forced (test mode): {$a}';
$string['status_backfill_started']     = 'Backfill started: {$a} elapsed windows to process.';
$string['status_backfill_complete']    = 'Backfill complete: {$a} windows simulated.';

// =========================================================================
// GENERATIVE TEXT DATA
//
// The strings below are used by classes/data/name_generator.php to produce
// synthetic names, course titles, and forum post bodies. They are stored
// here rather than hardcoded in PHP so that the plugin can be adapted for
// different languages, institutional styles, or content policies without
// touching class files.
//
// FORMAT: each string is a pipe-delimited list of items. name_generator.php
// explodes on '|' to get an array. Do not add spaces around the pipe.
//
// HOW TO SUBSTITUTE YOUR OWN TEXT
// --------------------------------
// To replace these strings with custom content, create a lang override file
// at:
//
//   {moodledata}/lang/en_local/local_activitysimulator.php
//
// (where 'en_local' is your site's language pack override folder — adjust
// for your locale). In that file, redefine only the keys you want to change.
// Moodle's string system will use your overrides in preference to these
// defaults. You do not need to modify the plugin files.
//
// NOTES ON FORUM POST TEXT
// ------------------------
// Forum post bodies use 'gen_post_verses' (see below). The 26 verses cycle
// repeatedly — most installations will have far more posts than verses, so
// the same verse will appear in many posts. This is intentional. The content
// of simulated posts has no semantic meaning and is not expected to be
// unique. Analytics tools operate on authorship, timing, and submission
// metadata, not post content.
//
// The verses are drawn from Edward Lear's nonsense alphabet (public domain).
// They were chosen because they are short, memorable, and clearly synthetic
// — it is immediately obvious to anyone browsing the site that the data is
// simulated, which reduces the risk of the data being mistaken for real
// student work.
//
// If you want posts that look more like academic writing, replace
// 'gen_post_verses' with 26 short paragraphs of Lorem Ipsum or similar.
// Keep exactly 26 entries (one per letter) so the cycling behaviour is
// predictable. If you use fewer, the generator will still work but the
// cycling will repeat sooner.
// =========================================================================

// -------------------------------------------------------------------------
// Forum post bodies — 26 Lear nonsense verses, one per letter of the
// alphabet. Used by name_generator::get_post_text(). Cycles silently when
// all 26 are exhausted.
//
// Source: Edward Lear, illustrated alphabet (published in various collections
// including "Laughable Lyrics", 1877). Public domain.
// -------------------------------------------------------------------------

$string['gen_post_verses'] =
    'A was an ape, who stole some white tape, and tied up his toes in four beautiful bows.|' .
    'B was a bat, who slept all the day, and fluttered about when the sun went away.|' .
    'C was a camel: you rode on his hump; and if you fell off, you came down such a bump!|' .
    'D was a dove, who lived in a wood, with such pretty soft wings, and so gentle and good!|' .
    'E was an eagle, who sat on the rocks, and looked down on the fields and the-far-away flocks.|' .
    'F was a fan made of beautiful stuff; and when it was used, it went puffy-puff-puff!|' .
    'G was a gooseberry, perfectly red; to be made into jam, and eaten with bread.|' .
    'H was a heron, who stood in a stream: the length of his neck and his legs was extreme.|' .
    'I was an inkstand, which stood on a table, with a nice pen to write with when we are able.|' .
    'J was a jug, so pretty and white, with fresh water in it at morning and night.|' .
    'K was a kingfisher: quickly he flew, so bright and so pretty!— green, purple, and blue.|' .
    'L was a lily, so white and so sweet! To see it and smell it was quite a nice treat.|' .
    'M was a man, who walked round and round; and he wore a long coat that came down to the ground.|' .
    'N was a nut so smooth and so brown! And when it was ripe, it fell tumble-dum-down.|' .
    'O was an oyster, who lived in his shell: if you let him alone, he felt perfectly well.|' .
    'P was a polly, all red, blue, and green,— the most beautiful polly that ever was seen.|' .
    'Q was a quill made into a pen; but I do not know where, and I cannot say when.|' .
    'R was a rattlesnake, rolled up so tight, those who saw him ran quickly, for fear he should bite.|' .
    'S was a screw to screw down a box; and then it was fastened without any locks.|' .
    'T was a thimble, of silver so bright! When placed on the finger, it fitted so tight!|' .
    'U was an upper-coat, woolly and warm, to wear over all In the snow or the storm.|' .
    'V was a veil with a border upon it, and a ribbon to tie it all round a pink bonnet.|' .
    'W was a watch, where, in letters of gold, the hour of the day you might always behold.|' .
    'X was King Xerxes, who wore on his head a mighty large turban, green, yellow, and red.|' .
    'Y was a yak, from the land of Thibet: except his white tail, he was all black as jet.|' .
    'Z was a zebra, all striped white and black; and if he were tame, you might ride on his back.';

// -------------------------------------------------------------------------
// Given names (first names) — used by name_generator::get_firstname().
// Drawn from a diverse range of cultural backgrounds to produce realistic
// synthetic populations.
//
// To add names: append to the pipe-delimited list.
// To replace entirely: override this key in your lang override file.
// -------------------------------------------------------------------------

$string['gen_given_names'] =
    'Adriana|Ahmed|Aiko|Alejandro|Amara|Amelia|Anastasia|Andre|Anita|Antonio|' .
    'Asha|Beatriz|Benjamin|Brendan|Caitlin|Carlos|Carmen|Catherine|Chioma|Clara|' .
    'Daisuke|Daniel|Danielle|David|Deepa|Delia|Dmitri|Elena|Elias|Elizabeth|' .
    'Emeka|Emily|Enrique|Fatima|Felix|Fiona|Francisco|Gabriel|Giulia|Grace|' .
    'Hannah|Hassan|Helena|Ibrahim|Ingrid|Isabel|Ivan|Jae-won|James|Jana|' .
    'Javier|Jennifer|Jonas|Jorge|Josef|Julia|Julien|Karin|Kaito|Kemi|' .
    'Khalid|Kofi|Laila|Laura|Lena|Leon|Leona|Liam|Lila|Lin|' .
    'Luisa|Magnus|Malik|Maria|Marta|Mateo|Maya|Mei|Miguel|Miriam|' .
    'Mohamed|Naomi|Natalia|Nathan|Nia|Nikolai|Nina|Nora|Olga|Oliver|' .
    'Olivia|Omar|Priya|Rafael|Rania|Rebecca|Riku|Rosa|Samuel|Sara|' .
    'Sebastian|Selma|Shreya|Sofia|Stefan|Sun-hee|Tariq|Tomas|Valentina|Yuki';

$string['gen_family_names'] =
    'Abramowitz|Adeyemi|Agarwal|Andersen|Antonescu|Araujo|Asante|Baek|Bakr|Banerjee|' .
    'Bergstrom|Boateng|Bouchard|Castillo|Cheung|Christodoulou|Czajkowski|Dalton|Diallo|Diaz|' .
    'Dubois|Ekwueme|Eriksson|Esposito|Ferreira|Fischer|Fitzpatrick|Fontaine|Fonseca|Garcia|' .
    'Garza|Guerrero|Gupta|Haddad|Halvorsen|Hansen|Hassan|Hernandez|Hoffmann|Huang|' .
    'Ivanova|Janssen|Johansson|Kamau|Kaur|Khoury|Kim|Kowalski|Kumar|Larsen|' .
    'Laurent|Levi|Lima|Liu|Lopez|Madeira|Mahfouz|Maki|Marino|Martinez|' .
    'Mendoza|Moreira|Moretti|Mueller|Murphy|Nakamura|Ndiaye|Nguyen|Nielsen|Nkosi|' .
    'Novak|Obi|Okonkwo|Oliveira|Osei|Park|Patel|Pereira|Petrov|Popescu|' .
    'Ramirez|Reinholt|Rivera|Rodrigues|Romano|Rossi|Russo|Saito|Salazar|Santos|' .
    'Schmidt|Shkreli|Silva|Smirnov|Svensson|Tanaka|Torres|Tremblay|Vance|Vargas|' .
    'Vasquez|Vogel|Wang|Weber|Williams|Yamamoto|Yilmaz|Zaborski|Zhu|Zimmermann';

// -------------------------------------------------------------------------
// Course name word lists — used by name_generator::get_course_name() to
// produce academic-sounding course titles in the form:
//   "[qualifier] [subject] [preposition] [discipline]"
// e.g. "Advanced Studies in Comparative Literature"
//
// Adapted from local_pseudonymise (Elizabeth Dalton / GPL).
// -------------------------------------------------------------------------

$string['gen_course_qualifiers'] =
    'Advanced|Applied|Comparative|Contemporary|Critical|Elementary|Experimental|' .
    'Foundations of|Independent Study in|Introduction to|Perspectives on|' .
    'Principles of|Selected Topics in|Special Problems in|Survey of|Topics in';

$string['gen_course_subjects'] =
    'Analysis|Communication|Composition|Design|Development|History|Management|' .
    'Methods|Perspectives|Practice|Research|Studies|Theory|Writing';

$string['gen_course_prepositions'] =
    'and|for|in|of|with';

$string['gen_course_disciplines'] =
    'Analytical Chemistry|Applied Ethics|Biochemistry|Biomedical Engineering|' .
    'Business Administration|Cell Biology|Clinical Psychology|Cognitive Science|' .
    'Comparative Literature|Computer Science|Creative Writing|Cultural Studies|' .
    'Data Science|Development Economics|Digital Media|Early Childhood Education|' .
    'Educational Technology|Environmental Policy|Environmental Science|Evolutionary Biology|' .
    'Forensic Accounting|Gender Studies|Global Health|Human Geography|Human Nutrition|' .
    'Industrial Engineering|Information Systems|International Relations|Labour Economics|' .
    'Linguistics|Marine Biology|Media Studies|Medical Imaging|Microbiology|' .
    'Molecular Genetics|Musculoskeletal Employment|Neuroscience|Nursing Practice|' .
    'Operations Research|Organizational Behaviour|Palaeontology|Philosophy of Mind|' .
    'Political Economy|Public Administration|Public Health|Quantum Mechanics|' .
    'Religious Studies|Social Policy|Sociology|Software Engineering|Sports Science|' .
    'Statistical Methods|Urban Planning|Visual Arts|Wildlife Conservation';

// -------------------------------------------------------------------------
// Section name adjectives and nouns — used by name_generator::get_section_name()
// to produce short descriptive section labels.
// e.g. "Fundamental Structures", "Applied Frameworks"
// -------------------------------------------------------------------------

$string['gen_section_adjectives'] =
    'Applied|Comparative|Core|Critical|Elementary|Essential|Foundational|' .
    'Fundamental|Integrated|Intermediate|Introductory|Key|Practical|' .
    'Primary|Theoretical';

$string['gen_section_nouns'] =
    'Approaches|Concepts|Contexts|Dimensions|Elements|Frameworks|' .
    'Methods|Models|Perspectives|Practices|Principles|Processes|' .
    'Structures|Themes|Topics';
