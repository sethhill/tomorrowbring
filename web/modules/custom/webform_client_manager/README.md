# Webform Client Manager

This module provides client-specific webform access control and automated flow between webform modules.

## Features

- **Client Entity**: Create and manage client organizations
- **Module Access Control**: Configure which webform modules each client can access
- **Automatic Flow**: Users are automatically redirected to the next enabled module after completion
- **Free Navigation**: Users can navigate freely between their enabled modules
- **Custom Completion Redirect**: Configure a custom URL to redirect users after completing all modules

## Installation

1. Enable the module:
   ```bash
   ddev drush en webform_client_manager -y
   ```

2. The module will automatically create a `field_client` field on the User entity.

3. Clear cache:
   ```bash
   ddev drush cr
   ```

## Usage

### Creating a Client

1. Navigate to **Structure > Clients** (`/admin/structure/client`)
2. Click **Add Client**
3. Fill in:
   - **Client Name**: The organization name
   - **Machine name**: Auto-generated from the name
   - **Enabled Modules**: Select which webform modules this client can access
   - **Completion Redirect URL**: (Optional) URL to redirect users after completing all modules

### Assigning Users to Clients

1. Edit a user account
2. Find the **Client** field
3. Select the appropriate client
4. Save

### How It Works

1. **Access Control**: Users can only access webform modules enabled for their client
2. **Module Order**: Modules are presented in numerical order based on their "Module X:" prefix
3. **Automatic Flow**: After submitting a module, users are automatically redirected to the next enabled module
4. **Completion**: After the last module, users are redirected to the custom URL (if configured) or shown the default confirmation

### Adding the Handler to Webforms

For each webform module, you need to add the "Client Module Flow" handler:

1. Navigate to the webform settings (`/admin/structure/webform/manage/{webform_id}/handlers`)
2. Click **Add handler**
3. Select **Client Module Flow**
4. Save

Or use Drush to add it to all module webforms:

```bash
# This would need to be done manually via the UI or with a custom script
```

## Architecture

### Entities

- **Client** (`client`): Config entity storing client configuration
  - `label`: Client name
  - `enabled_modules`: Array of webform IDs
  - `completion_redirect_url`: Redirect URL after completion

### Services

- **webform_client_manager.manager**: Main service for managing client-webform relationships
  - `getCurrentUserClient()`: Get the current user's client
  - `userHasAccessToWebform($webform_id)`: Check if user can access a webform
  - `getNextWebform($current_webform_id)`: Get the next webform in sequence
  - `getEnabledWebforms()`: Get all enabled webforms for current user
  - `getCompletionRedirectUrl()`: Get the completion redirect URL

### Hooks

- **hook_webform_access()**: Controls access to webforms based on client settings

### Plugins

- **ClientModuleFlowHandler**: Webform handler that manages automatic redirects between modules

## Testing

1. Create a test client with modules 1, 2, and 10 enabled
2. Create a test user and assign them to the client
3. Log in as the test user
4. Verify:
   - Can access modules 1, 2, and 10
   - Cannot access other modules (access denied)
   - After completing module 1, automatically redirected to module 2
   - After completing module 2, automatically redirected to module 10
   - After completing module 10, redirected to completion URL or confirmation page

## Permissions

- **Administer clients**: Required to create, edit, and delete clients

## Notes

- Admin users (with "administer clients" permission) have full access to all webforms
- Module numbers are extracted from webform titles using the pattern "Module X:"
- Webforms without "Module" in their title are not affected by access control
