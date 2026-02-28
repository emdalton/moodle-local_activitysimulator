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

### Real Moodle API approach — no direct core table writes

All simulated activity is created through real Moodle APIs (`forum_add_discussion()`, `forum_add_post()`, the `assign` API) with `$USER` temporarily switched to the simulated user. All view and read events fire real Moodle event classes (`\mod_forum\event\discussion_viewed`, `\core\event\course_viewed`, etc.) so that event observers run correctly — including the forum read-tracking observer that populates `forum_read`.

The plugin never writes directly to `logstore_standard_log`, `forum_posts`, `forum_discussions`, `assign_submission`, or any other Moodle core table. All logstore entries are produced as natural side effects of the API calls and event triggers. This means all Moodle-internal consistency guarantees hold: foreign keys, caches, and observer chains are all maintained correctly.

The tradeoff is that simulated data carries real wall-clock timestamps. A future enhancement could apply a uniform timestamp delta across all tables after a simulation run, but this is not implemented — see *Timestamp note* below.

### Timestamp note — no backdating

Timestamps in Moodle core tables (logstore, forum posts, etc.) reflect the actual wall-clock time of the simulation run. `run_log.simulated_time` records the target time-of-day from the window schedule for reference only; it is not applied to any core table.

Backdating was explored and rejected. Moodle maintains many interdependent timestamp fields across related tables when events fire. Attempting to patch individual fields after the fact would miss dependencies and risk data corruption. A future approach would be a separate tool that computes the delta between the simulation run time and the target historical date, then applies it uniformly across all affected tables in a single coordinated update.

### Activity window model

Rather than simulating continuous activity, the plugin uses a discrete **activity window** model. A window is a named time slot (e.g. "Monday AM", "Week 3 Early") defined by the course profile. The daily scheduled task finds pending windows whose `scheduled_time` has passed and runs them. Within a window, `run_log.simulated_time` values are randomly distributed across the configured AM or PM time range (default 08:00–12:00 and 13:00–17:00), giving realistic within-window variation in the audit log.

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

Every log entry is classified as either `passive` (viewing) or `active` (submitting). This split is recorded in `run_log.action_class`. It enables the **view/engage split** — a common early-warning signal where a learner's passive views continue even after active submissions stop. The intermittent and failing profiles are specifically tuned to produce this pattern (wider passive/active gap, lower active base probability).

### Forum discussion and reply model

The standard student forum pattern follows common online course pedagogy: each student must create one original discussion thread, then reply to at least two peers. The simulation implements this as follows:

- **Active roll**: if the student has not yet started a discussion in this forum, `forum_add_discussion()` is called. If they already have one, `forum_add_post()` replies to a randomly chosen discussion started by another student.
- **Passive roll**: the student reads up to `get_max_forum_reads_per_window()` unread discussions. Overachievers read everything; standard learners read up to 2; intermittent learners read at most 1; failing learners read 0.

Reading a discussion fires `\mod_forum\event\discussion_viewed`, which populates `forum_read` via Moodle's built-in observer. This is critical for social network analysis — see *Forum read tracking and social network analysis* below.

### Forum read tracking and social network analysis

`\mod_forum\event\discussion_viewed` requires a `relateduserid` field identifying the discussion author. This creates a directed edge in `logstore_standard_log`: `userid` (the reader) → `relateduserid` (the author). These directed edges are the foundation of a reading network graph suitable for social network analysis.

The `relateduserid` value is the `userid` field from `forum_discussions` — the user who started the thread. If this value cannot be determined for a given discussion, the event is skipped entirely rather than recorded with a fabricated author. A false edge in an SNA graph is worse than a missing one.

### Direct SQL access to `forum_discussions` — known Moodle coding guideline deviation

`content_scanner::get_unread_discussions()` queries `forum_discussions` and `forum_posts` directly via SQL rather than using the `forum_get_discussions()` API function. This is a deliberate deviation from Moodle's coding guidelines, which prefer API access for schema stability.

The direct SQL approach was chosen because:
1. `forum_get_discussions()` fetches significantly more data than needed and performs additional joins, adding overhead when called hundreds of times per window across many courses.
2. The query requires a `LEFT JOIN` against `forum_read` to find unread discussions in a single pass, which the API does not support directly.
3. `forum_discussions.userid` is a stable, well-documented column with no history of breaking changes.

**If this plugin is submitted to the Moodle plugin directory**, this direct SQL should be replaced with API calls for compliance with Moodle's coding guidelines. The risk is low for a dedicated test-instance plugin, but should be revisited before any public release.

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

### Synthetic user conventions

Usernames follow a SIS-style convention with a group prefix and zero-padded sequence number: `a001`–`a050` (overachievers), `b001`–`b350` (standard), `c001`–`c050` (intermittent), `f001`–`f050` (failing), `t001`–`t002` (instructors). All users are assigned to a "Simulated Users" cohort for easy identification and bulk management. Email addresses use the `.invalid` TLD (RFC-reserved) so they can never route to real addresses.

### Ground truth audit log

Every simulated action inserts a row in `local_activitysimulator_run_log` in addition to any logstore entries produced by the event system. This table records the term, window, course, user, action type, action class (passive/active), activity (cmid), the id of any created Moodle object (`objectid`), a target simulated timestamp (for reference only), and an outcome string. It is the authoritative ground truth for validating analytics results: given a query result from the logstore, you can JOIN to `run_log` via `objectid` to confirm it reflects what was actually simulated.

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

## CLI tools

Both tools must be run from the Moodle server as a user with access to `config.php`. They use Moodle's standard CLI bootstrap and emit output via `mtrace()`.

### `cli/setup.php` — create a term

```bash
# Create the next scheduled term (next Monday by default):
php local/activitysimulator/cli/setup.php

# Create a term starting on a specific date (backfill):
php local/activitysimulator/cli/setup.php --date=2026-01-06

# Dry run — validate settings and report what would be created:
php local/activitysimulator/cli/setup.php --date=2026-01-06 --dry-run

# Bootstrap the user pool without creating a term:
php local/activitysimulator/cli/setup.php --users-only --verbose
```

Options: `--date=YYYY-MM-DD`, `--users-only`, `--dry-run` / `-n`, `--verbose` / `-v`, `--help` / `-h`

### `cli/run_window.php` — run simulation windows

```bash
# Run all pending windows (same as the scheduled task):
php local/activitysimulator/cli/run_window.php

# List active terms and window status:
php local/activitysimulator/cli/run_window.php --list

# List windows for a specific term:
php local/activitysimulator/cli/run_window.php --list --term=3

# Run pending windows for one term only:
php local/activitysimulator/cli/run_window.php --term=3

# Re-run one specific window with full verbose output (primary debug tool):
php local/activitysimulator/cli/run_window.php --window=42 --force --verbose

# Step through a backfill 5 windows at a time:
php local/activitysimulator/cli/run_window.php --term=3 --limit=5
```

Options: `--term=ID`, `--window=ID`, `--limit=N`, `--force`, `--list` / `-l`, `--verbose` / `-v`, `--help` / `-h`

`--window` implies `--force` — naming a window explicitly always re-runs it regardless of status. `--force` without `--window` requires `testmode` to be enabled in plugin settings to prevent accidental mass re-simulation.

---

## Known limitations

### `require_once` for forum/assign APIs only works via CLI

`cli/run_window.php` loads `mod/forum/lib.php` and `mod/assign/locallib.php` after
`config.php` has fully initialised `$CFG`. This works correctly for CLI invocation.

**It will not work when windows are run via a Moodle scheduled task.** The task
runner is Moodle's own cron entry point, not our CLI script, so these `require_once`
calls never execute and `forum_add_discussion()` / `forum_add_post()` will be
undefined.

**Before implementing scheduled task execution of windows**, move the `require_once`
calls into the individual methods that use them:
- `instructor_actor::post_announcement()` → needs `mod/forum/lib.php`
- `instructor_actor::reply_to_discussion()` → needs `mod/forum/lib.php`
- `student_actor::post_to_forum()` → needs `mod/forum/lib.php`
- `student_actor::reply_to_forum_discussion()` → needs `mod/forum/lib.php`
- Any assignment submission methods → need `mod/assign/locallib.php`

`require_once` is idempotent so there is no performance cost to including it in
multiple methods.

---

## Build status

| Component | Status |
|---|---|
| `version.php` + `settings.php` | ✓ Complete |
| `db/install.xml` | ✓ Complete |
| `db/tasks.php` | ✓ Complete |
| `user_manager` | ✓ Complete |
| Course profiles (`base_profile`, `one_week_intensive`) | ✓ Complete |
| `term_manager` | ✓ Complete |
| `classes/task/setup_term.php` | ✓ Complete |
| `content_scanner` | ✓ Complete |
| Learner profiles (all four) | ✓ Complete |
| `student_actor` + `instructor_actor` | ✓ Complete |
| `log_writer` | ✓ Complete |
| `window_runner` | ✓ Complete |
| `classes/task/simulate_windows.php` | ✓ Complete |
| `cli/setup.php` + `cli/run_window.php` | ✓ Complete |

**All planned components are implemented.** Remaining work: `eight_week_accelerated` and `sixteen_week_semester` course profiles (referenced in settings, classes not yet written), and integration testing on a live Moodle instance.

---

## License

GPL v3 or later. See `LICENSE`.

Copyright 2026 Elizabeth Dalton <dalton_moodle@gaeacoop.org>. Developed with assistance from Anthropic Claude.
