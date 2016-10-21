# Sumac

![sumac monster consuming time](monster-sumac.gif "sumac monster consuming time")   :rocket:  :raised_hands: :pray: :point_right: :satellite: :soon: ![Redmine](redmine.png "Redmine") 

A simple command line utility for pulling Harvest entries and pushing them into Redmine. Harvest, Redmine, a red harvest... sumac.

How does it work? Log a time entry in Harvest and say "Worked on issue #123". Run `php sumac.php sync 20151202` and the app will pull your time entry from Harvest and create a new time entry in Redmine.

## Running with Docker

Within the `sumac` directory, run `docker run --net redmine_default -it --rm -v $(pwd):/tmp/sumac savaslabs/sumac sync -c /tmp/sumac/config.yml -u 20160915:20160915`.

Adjust the `--net redmine_default` parameter to match the network your Redmine instance is running on  (use `docker network ls` to find the correct value).

If you are developing, you'll want to run `composer install` on the host, then:

```
docker run -e SUMAC_HARVEST_MAIL=kosta@savaslabs.com --net redmine_default -it --rm -v $(pwd):/usr/src/sumac savaslabs/sumac sync -u 20160915:20160915
```

You can also use environment variables to set the configuration:

``` bash
docker run -e SUMAC_HARVEST_MAIL=someone@savaslabs.com -e SUMAC_HARVEST_PASS=supersecret -e SUMAC_HARVEST_ACCOUNT=someaccount -e SUMAC_REDMINE_APIKEY=verysecret -e SUMAC_REDMINE_URL=http://app:3000 -e SUMAC_SYNC_PROJECTS_EXCLUDE=123,456 --net redmine_default -it --rm savaslabs/sumac sync -u 20160915:20160915
```

## Requirements

- Redmine 3
- Admin access to Harvest and Redmine

Please use the `--dry-run` option to confirm changes. Always back up your database before running. Things might break.

## Usage

### Crontab

Recommended usage is via a crontab entry, once a day at a time when no timers are running. You'll want to call the app like so: `php sumac.php sync`.

### Standalone

You can also run the app on its own with a bit more granularity. For example, pass in an argument like `20150101:20151208` to sync all entries from Jan 1, 2015 to Dec 8, 2015.

#### Options

You may also use the `--dry-run` flag to not actually post any data to Redmine.

Use the `--update` option if you'd like to make updates to existing Redmine time entries. This is helpful if a user has gone back into Harvest and adjusted wording for Redmine issue descriptions, or time amounts, etc.

## Configuration

Copy `config.example.yml` to `config.yml`.

### Redmine and Harvest Authorization

Fill in your credentials for Harvest and Redmine. The user account should have admin privileges to both systems.

### Redmine configuration

Sumac will look for a custom redmine project field called `Harvest Project ID(s)`, and use the values of that field to populate the mapping between Redmine projects and Harvest projects. You'll need to create this field if it doesn't exist, and also populate it for each project which you want to sync properly.

### Sync settings

#### Projects

You can use the `exclude` section to add the Harvest IDs for projects that you don't want to push time entries to. This is helpful for "Internal" or "Overhead" projects that you don't need to sync with Redmine projects.
