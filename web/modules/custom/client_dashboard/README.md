# Client Dashboard Module

Provides a member dashboard for tracking client module progress and displaying role impact analysis results.

## Features

- Dashboard view at `/dashboard` showing module completion progress
- Progress bar visualization
- Integration with role impact analysis module
- Client-specific module listing

## Dependencies

- Webform Client Manager module (for client/module access logic)
- Role Impact Analysis module (for analysis availability checks)
- Drupal Views module
- Drupal Webform module

## Installation

1. Enable the module: `ddev drush en client_dashboard`
2. Clear cache: `ddev drush cr`

## Usage

Members will be automatically redirected to `/dashboard` upon login (configured in webform_client_manager module).
