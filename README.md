# Sumac

[![Maintainability](https://api.codeclimate.com/v1/badges/5bd0fe53491d1eea2b28/maintainability)](https://codeclimate.com/github/savaslabs/sumac/maintainability)
[![Test Coverage](https://api.codeclimate.com/v1/badges/5bd0fe53491d1eea2b28/test_coverage)](https://codeclimate.com/github/savaslabs/sumac/test_coverage)

![sumac monster consuming time](monster-sumac.gif "sumac monster consuming time")   :rocket:  :raised_hands: :pray: :point_right: :satellite: :soon: ![Redmine](redmine.png "Redmine") 

A simple command line utility for pulling Harvest entries and pushing them into Redmine. Harvest, Redmine, a red harvest... sumac.

How does it work? Log a time entry in Harvest and say "Worked on issue #123".

## Running with Docker (recommended)

#### In production

You do not need to clone the repo in production. Instead pull the `savaslabs/sumac` image from docker hub and run it. Create a new blank directory and populate it with a `config.yml` file, using the example in `config.example.yml`.

Run `docker run --rm -v $(pwd):/tmp/sumac savaslabs/sumac sync -c /tmp/sumac/config.yml -u 20160915:20160916` (or whatever other date range). Add `--dry-run` to test.

#### For local development

Spin up Redmine locally.

Copy `config.example.yml` to `config.yml` and fill in any placeholder values. Particularly, you'll need the Slack webhook URL if you want to test slack integration, you'll need to fill in some Harvest credentials which have the proper permissions, and you'll need the Remine API key for the instance you're running locally.

The Slack webhook url and Harvest credentials can be obtained either from a team member with those credentials, or from the production `config.yml` located on our CI application at `/configfiles/show?id=redmine-sumac`.

The Redmine `apikey` should be for the `savasadmin` user on your local instance of Redmine. To obtain the API key, first reset the `savasadmin` user's Redmine password locally:

- From the Redmine project root, shell into the Redmine DB container via `docker-compose exec db /bin/bash`
- Access the DB via `mysql -ppassword -u redmine redmine_docker` 
- Update the `savaslabs` user's hashed password, salt, and status via `UPDATE users SET hashed_password='353e8061f2befecb6818ba0c034c632fb0bcae1b', status=1, salt='' WHERE login='savaslabs';`
- Quit and exit

You should now be able to log into your local Redmine instance using username `savasadmin` and password `password`. Then, obtain the API key by clicking on `My Account`, then click `Show` under `API access key`.

**BUILD THE DOCKER CONTAINER LOCALLY** so you pull in any updates. Run `docker build -t savaslabs/sumac:dev .`, and then use the `:dev` tag when you're running the container in testing (as in the example below).

You may also need to run `composer install` locally so that your `vendor` directory catches the dependencies.

Within the `sumac` directory, run `docker run --net redmine_default -it --rm -v $(pwd):/usr/src/sumac savaslabs/sumac:dev sync -u 20160915:20160916`. If you'd like to test Slack notifications, include the `--slack-notify` flag at the end of that command (and make sure the `debug-user` is set in `config.yml`).

Adjust the `--net redmine_default` parameter to match the network your Redmine instance is running on  (use `docker network ls` to find the correct value).

If you are developing, you'll want to run `composer install` on the host, to get grumphp and phpcs locally.

#### Local Debugging

In order to debug locally, you'll need to install some dependencies on your host.

- Install PHP 7 and Xdebug if they are not already installed (you can check your PHP version via `php --version`):
- Install pspell
- Update the redmine URL in `config.yml` from 'http://app:3000' to 'https://local.pm.savaslabs.com'
- In PhpStorm preferences, search for "Interpreter" and click on the preference option for "PHP" on the left. Add a new interpreter for PHP 7 and make sure the path is `/usr/local/bin/php` and not `/usr/bin/php`
- In PhpStorm go to Run -> Edit Configurations
    - Add a new configuration for a PHP Script
    - For "File", select `sumac.php`
    - Fill in the arguments. For example, to run slack notification enter the arguments `sync -u 20170110:20170111 --slack-notify`
- Set some breakpoints in your code
- In PhpStorm, select "Debug Sumac" from under "Run"

During debugging, it can take a while to fetch and cache all of the Redmine time entries and to fetch all Harvest time entries for the specified period. To speed up local development, you can specify certain projects to debug by listing their Harvest ids in your config.yml. When specified, Sumac will only fetch and cache time entries from Redmine for any projects associated with those Harvest ids, and Sumac will only fetch time entries from Harvest for those Harvest project ids. 

## Requirements

- Redmine 3
- Admin access to Harvest and Redmine
- PHP pspell package installed locally (for non-docker usage)
- Composer locally (for non-docker usage)

Please use the `--dry-run` option to confirm changes. Always back up your database before running. Things might break.

## Usage

### Standalone

You can also run the app on its own with a bit more granularity. For example, pass in an argument like `20150101:20151208` to sync all entries from Jan 1, 2015 to Dec 8, 2015.

#### Options

You may also use the `--dry-run` flag to not actually post any data to Redmine.

Use the `--update` option if you'd like to make updates to existing Redmine time entries. This is helpful if a user has gone back into Harvest and adjusted wording for Redmine issue descriptions, or time amounts, etc.

Use the `--slack-notify` flag if you'd like to send notifications to users about potential errors in their time entries.

### Redmine and Harvest Authorization

Fill in your credentials for Harvest and Redmine. The user account should have admin privileges to both systems.

### Redmine configuration

Sumac will look for a custom Redmine project field called `Harvest Project ID(s)`, and use the values of that field to populate the mapping between Redmine projects and Harvest projects. You'll need to create this field if it doesn't exist, and also populate it for each project which you want to sync properly.

Sumac will also look for a custom word dictionary wiki in Redmine to use when spellchecking time entries. Words in this Redmine wiki will be ignored during spellchecks. See `config.example.yml` for an example of how to set this up.

### Sync settings

#### Projects

You can use the `exclude` section to add the Harvest IDs for projects that you don't want to push time entries to. This is helpful for "Internal" or "Overhead" projects that you don't need to sync with Redmine projects.

### Remove duplicates

The `sync:find-duplicates` command will search through Redmine to locate time entries containing duplicate Harvest ID references. Recommended usage is to use the `-s` flag to reduce the amount of output you get. The command will output a JSON encoded string.

The `sync:remove-duplicates {data}` command will accept the output of `sync:find-duplicates` and remove the duplicate time entries.

