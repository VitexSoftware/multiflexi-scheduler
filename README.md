# multiflexi-scheduler

Schedule MultiFlexi jobs.

multiflexi-scheduler runs scheduled tasks for the MultiFlexi platform, triggering configured jobs on a defined cadence (minute, hourly, daily, or custom schedules).

## Single-instance protection

The scheduler daemon acquires an exclusive `flock` lock on a pidfile (`$TMPDIR/multiflexi-scheduler.pid`) at startup. If a second instance is launched while the first is already running, it exits immediately with an error logged to syslog. This prevents the same RunTemplate from being queued twice when a scheduler restart or misconfigured cron overlaps an already-running daemon process.

![Scheduler Logo](multiflexi-scheduler.svg?raw=true)

## MultiFlexi

multiflexi-scheduler is part of [MultiFlexi](https://multiflexi.eu) suite.
See the full list of ready-to-run applications within the MultiFlexi platform on the [application list page](https://www.multiflexi.eu/apps.php).

[![MultiFlexi App](https://github.com/VitexSoftware/MultiFlexi/blob/main/doc/multiflexi-app.svg)](https://www.multiflexi.eu/)
