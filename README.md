# local_activitysimulator

A Moodle local plugin that generates realistic synthetic learning activity data on a dedicated test instance. Its primary purpose is to populate Moodle's standard logstore and grade tables with historically plausible data so that learning analytics tools, early-warning dashboards, and reporting queries can be developed and validated against a known ground truth.

**This plugin is intended for use on a dedicated test Moodle instance only. It writes directly to core Moodle tables with backdated timestamps and creates a large number of synthetic users. It must never be installed on a production site.**

---

## What it does

The plugin simulates a series of academic terms, each containing a configurable number of courses. Within each term, simulated students and instructors interact with course content — viewing pages, attempting quizzes, submitting assignments, posting to forums — according to behavioural profiles that produce realistic engagement patterns. Every simulated action is written to Moodle's `logstore_standard_log` with a backdated timestamp, and to the plugin's own `run_log` audit table. The result is a dataset that looks, to analytics tools, like real historical usage data.

A key feature is that the ground truth is known: the `run_log` table records every simulated action with its user, course, activity, timestamp, and learner group. This allows analytics results to be validated against the known inputs, which is not possible with real user data.

---

## Architecture overview

```
Scheduled tasks
  setup_term          (Saturday)   — creates term category, course copies, enrolments
  simulate_windows    (daily 03:00) — runs pending activity windows

Managers
  term_manager        — term and window CRUD, backfill logic
  user_manager        — synthetic user pool creation, cohort management

Simulation engine
  window_runner       — orchestrates one window across all courses
  student_actor       — simulates one student's actions in one window
  instructor_actor    — simulates one instructor's actions in one window
  content_scanner     — discovers activities present in a course at runtime
  log_writer          — writes backdated entries to logstore + run_log

Course profiles
  base_profile              — abstract interface
  one_week_intensive        — 5 sections, 10 windows (AM+PM × 5 days)
  eight_week_accelerated    — 8 sections, 3 windows/week  [planned]
  sixteen_week_semester     — 16 sections, 3 windows/week [planned]

Learner profiles
  base_learner_profile  — engagement formula, decay model, factory
  overachiever          — high engagement, minimal decay
  standard              — solid engagement, gentle decay
  intermittent          — moderate engagement, pronounced decay
  failing               — low engagement, steep early decay

Data helpers
  name_generator        — synthetic names, course titles, post text
  stats_helper          — truncated normal distribution (Box-Muller)
```

---

## Key design decisions

### Backdating via direct DB writes

Moodle's event system does not allow the `timecreated` timestamp to be overridden — events fire with the current time. To generate historically accurate log data, `log_writer` inserts rows directly into `logstore_standard_log` rather than going through the event API. This is intentional and appropriate for a simulation plugin on a test instance. The tradeoff is that these entries will not trigger any real-time event observers, which is acceptable since the goal is to produce analytics data, not to drive live Moodle behaviour.

### Activity window model

Rather than simulating continuous activity, the plugin uses a discrete **activity window** model. A window is a named time slot (e.g. "Monday AM", "Week 3 Early") defined by the course profile. The daily scheduled task finds pending windows whose `scheduled_time` has passed and runs them. Within a window, log entry timestamps are randomly distributed across the configured AM or PM time range (default 08:00–12:00 and 13:00–17:00), giving realistic within-window variation.

This model was chosen because it makes the simulation tractable and testable: each window is an atomic unit of work that can be retried, audited, and reasoned about independently.

### Learner engagement model

Each student's engagement is determined by three factors multiplied together:

```
P(engage) = base_probability(action_class) × diligence_scalar × decay(window_index, total_windows)
```

- **`base_probability`** is set per learner group archetype (overachiever/standard/intermittent/failing) and differs between passive actions (viewing) and active actions (submitting). Passive probabilities are always higher than active, reflecting the research finding that learners view content more reliably than they submit work.

- **`diligence_scalar`** is a persistent per-user multiplier drawn once at user creation time from a truncated normal distribution within the group's range. It never changes across terms. This means a user who is a strong standard learner stays a strong standard learner — the variation within groups is stable, not random per window.

- **`decay`** is a temporal multiplier that falls from 1.0 at the start of the term toward a group-specific floor. Failing learners decay steeply and early; overachievers barely decay at all. This produces the realistic pattern where at-risk learners' activity drops off mid-term while high performers remain consistent.

### Passive vs. active action classification

Every log entry is classified as either `passive` (viewing) or `active` (submitting). This split is recorded in `run_log.action_class` and in the logstore `edulevel` column. It enables the **view/engage split** — a common early-warning signal where a learner's passive views continue even after active submissions stop. The intermittent and failing profiles are specifically tuned to produce this pattern (wider passive/active gap, lower active base probability).

### Student distribution and the sliding window enrolment model

Students are drawn from a shared pool of 500 users (default: 50 overachievers, 350 standard, 50 intermittent, 50 failing). Each course enrols 30 students by default, drawn from the pool in proportion to the configured `group_pct_*` percentages (default 10/70/10/10).

Enrolment uses a **sliding window rotation** rather than random sampling. Each group's students are assigned to courses with a fixed offset, so a given student appears in a predictable consecutive run of courses. At the defaults (30 students per course, 10 courses, 500-student pool), each student appears in approximately `ceil((30 × 10) / 500) = 1` course before a minimum-2 top-up is applied, guaranteeing every student appears in at least 2 courses across a term. This makes the dataset more analytically useful than one where many students appear in only a single course.

### Instructor behaviour model

Instructors use a simpler string-based profile rather than the full learner profile class hierarchy. Three types are defined:

| Profile | Announce | Read posts | Reply to posts | Grade |
|---|---|---|---|---|
| `responsive` | 100% | 100% | 50% | 100% |
| `delayed` | 80% | 60% | 20% | 60% |
| `unresponsive` | 30% | 20% | 5% | 20% |

Phase 1 assigns all instructors the `responsive` profile. A full instructor profile class hierarchy (analogous to `base_learner_profile`) is planned for a later phase.

### Instructor announcements and the within-window execution order

Within each window, students run first, then instructors. This means instructors see all student forum posts from the current window when they run their forum-read pass. Instructor announcements are posted at the end of the window and appear as unread posts to students in the **next** window — mirroring the common real-world pattern of an instructor posting a wrap-up or preview after the main activity period.

### Forum content

Forum posts use text from Edward Lear's nonsense alphabet (26 verses, public domain). The verses cycle repeatedly — most installations will see the same verse appear in many posts. This is intentional. Post content has no semantic meaning in this simulation; analytics tools operate on authorship, timing, and submission metadata. The Lear text was chosen because it is immediately recognisable as synthetic, reducing the risk of simulated data being mistaken for real student work.

All generative text (names, course titles, section labels, forum post bodies) is stored in the lang file rather than hardcoded in PHP. This allows content to be customised via Moodle's standard lang override mechanism without modifying plugin files.

### Backfill mode

When a term is created with a start date in the past, the plugin can immediately simulate all elapsed windows with appropriately backdated timestamps. This allows a full semester of realistic data to be generated in a single run rather than waiting for windows to become due day by day. The maximum backfill depth is configurable (default 20 weeks) to prevent accidental generation of unreasonably large datasets.

### Synthetic user conventions

Usernames follow a SIS-style convention with a group prefix and zero-padded sequence number: `a001`–`a050` (overachievers), `b001`–`b350` (standard), `c001`–`c050` (intermittent), `f001`–`f050` (failing), `t001`–`t002` (instructors). All users are assigned to a "Simulated Users" cohort for easy identification and bulk management. Email addresses use the `.invalid` TLD (RFC-reserved) so they can never route to real addresses.

### Ground truth audit log

Every simulated action inserts a row in `local_activitysimulator_run_log` in addition to the logstore entry. This table records the term, window, course, user, action type, action class (passive/active), activity, backdated timestamp, and outcome. It is the authoritative ground truth for validating analytics results: given a query result, you can check it against the run log to confirm it reflects what was actually simulated.

---

## Database tables

| Table | Purpose |
|---|---|
| `local_activitysimulator_terms` | One row per simulated term (course category) |
| `local_activitysimulator_windows` | One row per activity window within a term |
| `local_activitysimulator_learner_profiles` | Persistent diligence scalars for simulated users |
| `local_activitysimulator_run_log` | Audit log of every simulated action |

---

## Configuration

All settings are under **Site administration → Plugins → Local plugins → Activity Simulator**.

| Setting | Default | Notes |
|---|---|---|
| Enable simulation | Off | Master switch; tasks exit immediately when off |
| Test mode | Off | Enables `--force` re-runs and verbose logging |
| Course profile | One-week intensive | Determines section count and window schedule |
| Courses per term | 10 | |
| Term start day | Monday | |
| Term setup day | Saturday | Day the setup task runs |
| Backfill on create | On | Simulate elapsed windows immediately on term creation |
| Backfill max weeks | 20 | Safety limit on how far back a term start date can be |
| Students per course | 30 | See pool size note below |
| Instructors per course | 2 | |
| Group pool sizes | 50/350/50/50 | Total pool of 500; see enrolment model above |
| Group enrolment % | 10/70/10/10 | Must sum to 100 |
| Diligence mean/stddev | per group | Controls per-user scalar distribution |
| AM/PM window times | 08:00–12:00 / 13:00–17:00 | Time ranges for backdated timestamps |
| Simulation timezone | Server timezone | |

---

## Build status

| Step | Status |
|---|---|
| `version.php` + `settings.php` | ✓ Complete |
| `db/install.xml` | ✓ Complete |
| `db/tasks.php` | ✓ Complete |
| `user_manager` | ✓ Complete |
| Course profiles (`base_profile`, `one_week_intensive`) | ✓ Complete |
| `term_manager` | ✓ Complete |
| `classes/task/setup_term.php` | ⏳ Stub — next |
| `content_scanner` | ✓ Complete |
| Learner profiles (all four) | ✓ Complete |
| `student_actor` + `instructor_actor` | ✓ Complete |
| `log_writer` | ✓ Complete |
| `window_runner` | ✓ Complete |
| `classes/task/simulate_windows.php` | ⏳ Stub — next |
| `cli/setup.php` + `cli/run_window.php` | ⏳ Not started |

---

## License

GPL v3 or later. See `LICENSE`.

Copyright 2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>. Developed with assistance from Anthropic Claude.
