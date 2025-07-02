# ContributorUserSync Plugin for OJS

This plugin automatically syncs contributors to user accounts and allows you to customize the available contributor roles in Open Journal Systems (OJS) 3.3+ and 3.4+.

## Features
- Automatically create user accounts for contributors listed during submission.
- Select which contributor roles are available in the "Add Contributor" form.
- Localized role names and settings UI.
- Compatible with OJS 3.3+ and 3.4+ (Repository pattern, no deprecated DAOs).

## Installation
1. Clone or download this repository into your OJS `plugins/generic/` directory:
   ```
   git clone https://github.com/thathman/contributorUserSync.git
   ```
2. Enable the plugin from the OJS site administration dashboard.
3. Configure the available contributor roles in the plugin settings.

When no roles are selected, OJS will fall back to the default set of contributor roles.

## Usage
- When enabled, the plugin will automatically create user accounts for new contributors during submission.
- The "Contributor Role" dropdown in the submission metadata form will only show the roles you select in the plugin settings.
- Use the "Sync Contributors of Past Submissions" button on the settings page to process existing submissions. A progress bar and summary log will show the results of the sync.

## Customization
- To add or reorder roles, edit `contributorRoles.json` and update the locale file for translations.

## License
GPL v3

## Maintainers
- [thathman](https://github.com/thathman)

---
Pull requests and issues are welcome!
