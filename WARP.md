# WARP.md - Working AI Reference for multiflexi-scheduler

## Project Overview
**Type**: PHP Project/Debian Package - Systemd Service
**Purpose**: MultiFlexi job scheduling daemon (v2.x+)
**Status**: Active
**Repository**: git@github.com:VitexSoftware/multiflexi-scheduler.git

## Key Technologies
- PHP 8.2+
- Composer
- Systemd service
- dragonmantank/cron-expression library
- Debian Packaging

## Architecture & Structure
```
multiflexi-scheduler/
├── src/
│   ├── daemon.php           # Main daemon entry point
│   ├── scheduler.php        # Legacy/manual execution
│   └── MultiFlexi/
│       └── CronScheduler.php  # Scheduling logic
├── debian/
│   ├── multiflexi-scheduler.service  # Systemd unit file
│   └── multiflexi-scheduler.postinst # Service installation
├── tests/         # Test files
└── docs/          # Documentation
```

## Service Architecture (v2.x+)

The scheduler runs as a continuous systemd service (``multiflexi-scheduler.service``) under the ``multiflexi`` user:

- **Service file**: ``/lib/systemd/system/multiflexi-scheduler.service``
- **Executable**: ``/usr/lib/multiflexi-scheduler/daemon.php``
- **Configuration**: ``/etc/multiflexi/multiflexi.env``

### How It Works

1. Daemon runs continuously in a loop
2. Each cycle calls ``CronScheduler::scheduleCronJobs()``
3. Queries database for active RunTemplates (``active=true``, ``next_schedule=null``, ``interv != 'n'``)
4. For each RunTemplate:
   - Determines cron expression (custom or converted from interval shorthand)
   - Calculates next run time using dragonmantank/cron-expression
   - Applies optional startup delay
   - Creates Job record via ``Job::prepareJob()``
   - Inserts into ``schedule`` table with timestamp
5. Sleep 1 second between cycles (configurable)

### Configuration Options

- ``MULTIFLEXI_DAEMONIZE=true``: Run continuously (default) or exit after one cycle
- Standard MultiFlexi database settings (``DB_*``)
- Logging configuration via ``EASE_LOGGER``

## Development Workflow

### Prerequisites
- Development environment setup
- Required dependencies

### Setup Instructions
```bash
# Clone the repository
git clone git@github.com:VitexSoftware/multiflexi-scheduler.git
cd multiflexi-scheduler

# Install dependencies
composer install
```

### Build & Run
```bash
dpkg-buildpackage -b -uc
```

### Testing
```bash
composer test
```

## Key Concepts
- **Main Components**: Core functionality and modules
- **Configuration**: Configuration files and environment variables
- **Integration Points**: External services and dependencies

## Common Tasks

### Development
- Review code structure
- Implement new features
- Fix bugs and issues

### Deployment
- Build and package
- Deploy to target environment
- Monitor and maintain

## Troubleshooting
- **Common Issues**: Check logs and error messages
- **Debug Commands**: Use appropriate debugging tools
- **Support**: Check documentation and issue tracker

## Additional Notes
- Project-specific conventions
- Development guidelines
- Related documentation
