<?php

namespace Platformsh\Cli\SiteAlias;

use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

abstract class DrushAlias implements SiteAliasTypeInterface
{
    const LOCAL_ALIAS_NAME = '_local';

    protected $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function createAliases(Project $project, $aliasGroup, array $apps, array $environments, $previousGroup = null)
    {
        if (!count($apps)) {
            return false;
        }

        // Prepare the Drush directory and file.
        $drushDir = $this->getDrushDir();
        if (!is_dir($drushDir) && !mkdir($drushDir, 0755)) {
            throw new \RuntimeException('Drush aliases directory not found: ' . $drushDir);
        }
        if (!is_writable($drushDir)) {
            throw new \RuntimeException('Drush aliases directory not writable: ' . $drushDir);
        }
        $filename = $this->getFilename($aliasGroup, $drushDir);
        if (file_exists($filename) && !is_writable($filename)) {
            throw new \RuntimeException("Drush alias file not writable: $filename");
        }

        // Gather existing aliases from this file and from the previous file.
        $filesToCheck = [$filename];
        if ($previousGroup !== null) {
            $filesToCheck[] = $this->getFilename($previousGroup, $drushDir);
        }
        $existingAliases = $this->getExistingAliases($filesToCheck);

        // Generate the new aliases.
        $newAliases = $this->generateNewAliases($apps, $environments);

        // Merge new aliases with existing ones.
        $newAliases = $this->mergeExisting($newAliases, $existingAliases);

        // Add any user-defined (pre-existing) aliases.
        $autoRemoveKey = $this->getAutoRemoveKey();
        $userDefinedAliases = [];
        foreach ($existingAliases as $name => $alias) {
            if (!empty($alias[$autoRemoveKey])) {
                // This is probably for a deleted environment.
                continue;
            }
            $userDefinedAliases[$name] = $alias;
        }

        $aliases = $userDefinedAliases + $newAliases;

        // Format the aliases as a string.
        $content = $this->getHeader($project) . $this->formatAliases($aliases);

        $this->writeAliasFile($filename, $content);

        return true;
    }

    /**
     * Merge new aliases with existing ones.
     *
     * @param array $new
     * @param array $existing
     *
     * @return array
     */
    protected function mergeExisting($new, $existing)
    {
        foreach ($new as $aliasName => &$newAlias) {
            // If the alias already exists, recursively replace existing
            // settings with new ones.
            if (isset($existing[$aliasName])) {
                $newAlias = array_replace_recursive($existing[$aliasName], $newAlias);
            }
        }

        return $new;
    }

    /**
     * Get the filename for the aliases.
     *
     * @param string $groupName
     * @param string $drushDir
     *
     * @return string
     */
    abstract protected function getFilename($groupName, $drushDir);

    /**
     * Get the header at the top of the file.
     *
     * @param Project $project
     *
     * @return string
     */
    abstract protected function getHeader(Project $project);

    /**
     * Find the existing defined aliases so they can be merged with new ones.
     *
     * @param string[] $filenames
     *
     * @return array
     */
    abstract protected function getExistingAliases(array $filenames);

    /**
     * Find the Drush directory, where site aliases should be stored.
     *
     * @return string
     */
    private function getDrushDir()
    {
        $homeDir = Filesystem::getHomeDirectory();
        $drushDir = $homeDir . '/.drush';
        if (file_exists($drushDir . '/site-aliases')) {
            $drushDir = $drushDir . '/site-aliases';
        }

        return $drushDir;
    }

    /**
     * Generate new aliases.
     *
     * @param array $apps
     * @param array $environments
     *
     * @return array
     */
    abstract protected function generateNewAliases(array $apps, array $environments);

    /**
     * Format a list of aliases as a string.
     *
     * @param array $aliases
     *   A list of aliases.
     *
     * @return string
     */
    abstract protected function formatAliases(array $aliases);

    /**
     * Generate an alias for the local environment.
     *
     * @param \Platformsh\Cli\Local\LocalApplication $app
     *
     * @return array
     */
    protected function generateLocalAlias(LocalApplication $app)
    {
        $appId = $app->getId();
        $webRoot = $app->getSourceDir() . '/' . $this->config->get('local.web_root');
        if (!$app->isSingle()) {
            $webRoot .= '/' . $appId;
        }

        return [
            'root' => $webRoot,
            $this->getAutoRemoveKey() => true,
        ];
    }

    /**
     * Generate a remote Drush alias.
     *
     * @param Environment $environment
     * @param LocalApplication $app
     * @param bool $multiApp
     *
     * @return array|false
     */
    protected function generateRemoteAlias($environment, $app, $multiApp = false)
    {
        if (!$environment->hasLink('ssh') || !$environment->hasLink('public-url')) {
            return false;
        }
        $sshUrl = parse_url($environment->getLink('ssh'));
        if (!$sshUrl) {
            return false;
        }
        $sshUser = $sshUrl['user'];
        if ($multiApp) {
            $sshUser .= '--' . $app->getName();
        }

        $uri = $environment->getLink('public-url');
        if ($multiApp) {
            $guess = str_replace('http://', 'http://' . $app->getName() . '---', $uri);
            if (in_array($guess, $environment->getRouteUrls())) {
                $uri = $guess;
            }
        }

        return [
            'uri' => $uri,
            'remote-host' => $sshUrl['host'],
            'remote-user' => $sshUser,
            'root' => '/app/' . $app->getDocumentRoot(),
            $this->getAutoRemoveKey() => true,
        ];
    }

    /**
     * @return string
     */
    private function getAutoRemoveKey()
    {
        return preg_replace(
                '/[^a-z-]+/',
                '-',
                str_replace('.', '', strtolower($this->config->get('application.name')))
            ) . '-auto-remove';
    }

    /**
     * Write a file and create a backup if the contents have changed.
     *
     * @param string $filename
     * @param string $contents
     */
    private function writeAliasFile($filename, $contents)
    {
        $fs = new SymfonyFilesystem();
        if (is_readable($filename) && $contents !== file_get_contents($filename)) {
            $backupName = dirname($filename) . '/' . basename($filename) . '.bak';
            $fs->rename($filename, $backupName, true);
        }
        $fs->dumpFile($filename, $contents);
    }
}
