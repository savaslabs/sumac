# Sumac

A simple command line utility for pulling Harvest entries and pushing them into Redmine. Harvest, Redmine, a red harvest... sumac.

How does it work? Log a time entry in Harvest and say "Worked on issue #123". Run `php sumac.php sync 20151202` and the app will pull your time entry from Harvest and create a new time entry in Redmine.

## Configuration

Copy `config.example.yml` to `config.yml`.

### Redmine and Harvest Authorization

Fill in your credentials for Harvest and Redmine. The user account should have admin privileges to both systems.

### Sync settings

There are two major sections, `users` and `projects`.

#### Users

This is where we tell Sumac how a Harvest user ID (a number like `785018`) maps to a Redmine username (N.B., the username, not their numeric ID).

This app won't push time entries into Harvest unless it can find a match between the user's Harvest ID in this section, so make sure you fill it out.

#### Projects

This section contains two sub-sections, `exclude` and `map`. You can use the `exclude` section to add the Harvest IDs for projects that you don't want to push time entries to. This is helpful for "Internal" or "Overhead" projects that you don't need to sync with Redmine projects.

The `map` sub-section is where we tell Sumac how a Harvest project ID maps to a Redmine project name. This is optional, but recommended. When a mapping is defined here, Sumac will validate that the Redmine issue number in a Harvest time entry is valid for the corresponding project in Redmine.
