# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Drupal 11 site using DDEV for local development. The project follows Drupal's recommended project structure with a relocated document root (`web/`).

## Development Environment

- **DDEV project name**: tomorrowbring
- **Site URL**: https://tomorrowbring.ddev.site
- **PHP version**: 8.3
- **Webserver**: nginx-fpm
- **Database**: MariaDB 10.11
- **Composer**: Version 2

## Essential Commands

### DDEV Commands
```bash
ddev start                    # Start the development environment
ddev stop                     # Stop the environment
ddev restart                  # Restart services
ddev ssh                      # SSH into web container
ddev exec <command>           # Run command in web container
ddev composer <command>       # Run Composer commands
ddev drush <command>          # Run Drush commands
ddev import-db --file=<file>  # Import database
ddev export-db --file=<file>  # Export database
ddev describe                 # Show project info and URLs
ddev logs                     # View logs
```

### Drupal/Drush Commands (run via `ddev drush`)
```bash
ddev drush cr                       # Clear cache
ddev drush updb                     # Run database updates
ddev drush cex                      # Export configuration to config/sync
ddev drush cim                      # Import configuration from config/sync
ddev drush uli                      # Generate one-time login link
ddev drush status                   # Show Drupal status
ddev drush en <module>              # Enable module
ddev drush pmu <module>             # Uninstall module
ddev drush watchdog:show            # View logs
```

### Composer Commands (run via `ddev composer`)
```bash
ddev composer require drupal/<module>     # Add a module
ddev composer update                       # Update dependencies
ddev composer install                      # Install dependencies
```

## Project Structure

- **`web/`**: Document root containing Drupal core
  - `web/core/`: Drupal core files
  - `web/modules/contrib/`: Contributed modules
  - `web/modules/custom/`: Custom modules (currently empty)
  - `web/themes/contrib/`: Contributed themes
  - `web/themes/custom/`: Custom themes (currently empty)
- **`config/sync/`**: Configuration management directory
- **`vendor/`**: Composer dependencies
- **`composer.json`**: Dependency management
- **`.ddev/`**: DDEV configuration

## Installed Contrib Modules

- Gin Admin Theme (`drupal/gin`)
- Range Slider (`drupal/range_slider`)
- Webform (`drupal/webform`)

## Configuration Management

Configuration is stored in `config/sync/`. When making configuration changes:
1. Export config: `ddev drush cex`
2. Commit changes to version control
3. Import on other environments: `ddev drush cim`

## Important Notes

- All Drupal commands must be run through DDEV (e.g., `ddev drush` not just `drush`)
- Configuration changes should always be exported via `ddev drush cex`
- Custom code should go in `web/modules/custom/` or `web/themes/custom/`
- Composer is used for dependency management; manually editing `web/core` or contrib modules is not supported
