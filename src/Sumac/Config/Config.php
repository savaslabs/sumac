<?php

namespace Sumac\Config;

use Symfony\Component\Yaml\Yaml;

class Config
{

    private $config = [];

    const CONFIG_PATH_DEFAULT = 'config.yml';

    public function __construct($config_path)
    {
        $config_path = $config_path ?? self::CONFIG_PATH_DEFAULT;
        if (!file_exists($config_path)) {
            throw new \Exception(sprintf('Could not find the config.yml file at %s', $config_path));
        }
        // Load the configuration.
        $this->config = Yaml::parseFile($config_path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
    }

    public function getDictionaryProjectAndPage()
    {
        if (isset($this->config['spellcheck']['project_name'])
            && isset($this->config['spellcheck']['wiki_page_name'])
        ) {
            return [
                $this->config['spellcheck']['project_name'],
                $this->config['spellcheck']['wiki_page_name']
            ];
        }
        throw new \Exception(
            'Redmine dictionary wiki location not properly set in config.yml (see config.example.yml).'
        );
    }

    public function getHarvestMail()
    {
        return $this->config['auth']['harvest']['mail'];
    }

    public function getHarvestPassword()
    {
        return $this->config['auth']['harvest']['pass'];
    }

    public function getHarvestAccount()
    {
        return $this->config['auth']['harvest']['account'];
    }

    public function getRedmineUrl()
    {
        return $this->config['auth']['redmine']['url'];
    }

    public function getRedmineApiKey()
    {
        return $this->config['auth']['redmine']['apikey'];
    }

    public function getSlackDebugUser()
    {
        return $this->config['auth']['slack']['debug-user'] ?? null;
    }

    public function getSlackWebhookUrl()
    {
        return $this->config['auth']['slack']['webhook_url'] ?? null
            ;
    }

    public function getDebugProjectsList()
    {
        return $this->config['sync']['projects']['debug_projects'] ?? [];
    }

    public function getSpellCheckOnlyProjectsList()
    {
        return $this->config['sync']['projects']['spell_check_only'] ?? [];
    }

    public function getSkipSpellcheckClientsList()
    {
        return $this->config['sync']['clients']['dont_spell_check'] ?? [];
    }
}
