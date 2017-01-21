<?php

namespace Cheppers\Robo\Drupal\ProjectType\Incubator;

use Cheppers\AssetJar\AssetJar;
use Cheppers\LintReport\Reporter\BaseReporter;
use Cheppers\LintReport\Reporter\CheckstyleReporter;
use Cheppers\Robo\Drupal\Config\DatabaseServerConfig;
use Cheppers\Robo\Drupal\Config\DrupalExtensionConfig;
use Cheppers\Robo\Drupal\Config\PhpVariantConfig;
use Cheppers\Robo\Drupal\ProjectType\Base as Base;
use Cheppers\Robo\Drupal\Robo\ComposerTaskLoader;
use Cheppers\Robo\Drupal\Robo\DrupalCoreTestsTaskLoader;
use Cheppers\Robo\Drupal\Robo\DrupalTaskLoader;
use Cheppers\Robo\Drupal\Utils;
use Cheppers\Robo\Drush\DrushTaskLoader;
use Cheppers\Robo\ESLint\ESLintTaskLoader;
use Cheppers\Robo\Phpcs\PhpcsTaskLoader;
use Cheppers\Robo\ScssLint\ScssLintTaskLoader;
use Cheppers\Robo\TsLint\TsLintTaskLoader;
use League\Container\ContainerInterface;
use Robo\Collection\CollectionBuilder;
use Robo\Contract\TaskInterface;
use Robo\Task\Filesystem\loadShortcuts as FilesystemShortcuts;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Webmozart\PathUtil\Path;

/**
 * @todo Support for Drupal extensions where the "vendor" name isn't "drupal".
 */
class RoboFile extends Base\RoboFile
{
    use ComposerTaskLoader;
    use DrupalCoreTestsTaskLoader;
    use DrupalTaskLoader;
    use DrushTaskLoader;
    use ESLintTaskLoader;
    use PhpcsTaskLoader;
    use ScssLintTaskLoader;
    use TsLintTaskLoader;
    use FilesystemShortcuts;

    protected $areManagedDrupalExtensionsInitialized = false;

    /**
     * {@inheritdoc}
     */
    public function setContainer(ContainerInterface $container)
    {
        BaseReporter::lintReportConfigureContainer($container);
        parent::setContainer($container);

        return $this;
    }

    protected function getPhpExecutable(): string
    {
        return getenv($this->getEnvName('php_executable')) ?: PHP_BINARY;
    }

    protected function getPhpdbgExecutable(): string
    {
        return getenv($this->getEnvName('phpdbg_executable')) ?: Path::join(PHP_BINDIR, 'phpdbg');
    }

    //region Self

    //region Self - Git hooks.
    /**
     * Git "pre-commit" hook callback.
     */
    public function selfGithookPreCommit(): CollectionBuilder
    {
        $this->environment = 'git-hook';

        return $this
            ->collectionBuilder()
            ->addTaskList([
                'lint.composer.lock' => $this->taskComposerValidate(),
                'lint.phpcs.psr2' => $this->getTaskPhpcsLint([
                    'standard' => 'PSR2',
                    'files' => $this->selfPhpcsFiles,
                ]),
            ]);
    }

    /**
     * Git "post-checkout" hook callback.
     *
     * @param string $oldRef
     *   The ref of the previous HEAD.
     * @param string $newRef
     *   The ref of the new HEAD (which may or may not have changed).
     * @param string $isBranch
     *   A flag indicating whether the checkout was a branch checkout (changing
     *   branches, flag=1) or a file checkout (retrieving a file from the index,
     *   flag=0).
     *
     * @return CollectionBuilder
     */
    public function selfGithookPostCheckout(string $oldRef, string $newRef, string $isBranch): CollectionBuilder
    {
        $this->environment = 'git-hook';

        return $this->collectionBuilder()->addCode(function () use ($oldRef, $newRef) {
            // @todo Create dedicated Robo task. Maybe in the cheppers/robo-git package.
            $command = sprintf(
                '%s diff --exit-code --name-only %s..%s -- %s %s',
                escapeshellcmd($this->projectConfig->gitExecutable),
                escapeshellarg($oldRef),
                escapeshellarg($newRef),
                escapeshellarg('composer.json'),
                escapeshellarg('composer.lock')
            );

            $process = new Process($command);
            $process->run();
            if (!$process->isSuccessful()) {
                $this->yell('The "composer.{json|lock}" has changed. You have to run `composer install`', 40, 'yellow');
            }

            return 0;
        });
    }
    //endregion

    //region Self - Lint.
    /**
     * @todo Move this settings to ProjectConfig.php.
     *
     * @var string[]
     */
    protected $selfPhpcsFiles = [
        'src/',
        'RoboFile.php',
    ];

    public function selfLint(): CollectionBuilder
    {
        return $this
            ->collectionBuilder()
            ->addTaskList([
                'lint:phpcs' => $this->getTaskPhpcsLint([
                    'standard' => 'PSR2',
                    'files' => $this->selfPhpcsFiles,
                ]),
            ]);
    }

    public function selfLintPhpcs(): CollectionBuilder
    {
        return $this
            ->collectionBuilder()
            ->addTaskList([
                'lint:phpcs' => $this->getTaskPhpcsLint([
                    'standard' => 'PSR2',
                    'files' => $this->selfPhpcsFiles,
                ]),
                'composer:validate' => $this->taskComposerValidate(),
            ]);
    }
    //endregion

    public function selfManagedExtensions()
    {
        // @todo Improve the output format.
        $managedExtensions = $this->getManagedDrupalExtensions();
        foreach ($managedExtensions as $e) {
            $this->say("{$e->packageVendor}/{$e->packageName} {$e->path}");
        }
    }
    //endregion

    //region Git hooks.
    public function githooksInstall(): ?CollectionBuilder
    {
        $extensions = Utils::filterDisabled(
            $this->getManagedDrupalExtensions(),
            'hasGit'
        );

        if (!$extensions) {
            $this->say('There is no managed extension under Git VCS.');

            return null;
        }

        $cb = $this->collectionBuilder();
        foreach ($extensions as $extension) {
            $cb->addCode($this->getTaskGitHookInstall($extension));
        }

        return $cb;
    }

    /**
     * @todo Implement.
     */
    public function githooksUninstall(): ?CollectionBuilder
    {
        $this->yell('@todo');

        return null;
    }

    public function githookPreCommit(string $extensionPath, string $extensionName): CollectionBuilder
    {
        $extensions = $this->getManagedDrupalExtensions();
        $extension = $extensions[$extensionName];

        $this->environment = 'git-hook';

        return $this
            ->collectionBuilder()
            ->addTask($this->getTaskPhpcsLintDrupalExtension($extension));
    }
    //endregion

    //region Site - CRUD.
    /**
     * @option string $profile Name of the install profile.
     * @option string $long    Long machine-name prefix. Example: "awesome"
     * @option string $short   Short machine-name prefix. Example: "aws"
     */
    public function siteCreate(
        string $sitesSubDir = '',
        array $options = [
            'profile|p' => 'standard',
            'long|l' => '',
            'short|s' => '',
        ]
    ): CollectionBuilder {
        if (!$sitesSubDir) {
            $defaultSettingsPhp = "{$this->projectConfig->drupalRootDir}/sites/default/settings.php";
            $sitesSubDir = (!file_exists($defaultSettingsPhp) ? 'default' : $options['profile']);
        }

        $o = array_filter([
            'siteBranch' => $sitesSubDir,
            'installProfile' => $options['profile'],
            'machineNameLong' => $options['long'],
            'machineNameShort' => $options['short'],
        ]);

        /** @var \Robo\Collection\CollectionBuilder $cb */
        $cb = $this->collectionBuilder();
        $cb->addTaskList([
            'create:site' => $this->getTaskDrupalSiteCreate($o),
            'rebuild:sites-php' => $this->getTaskDrupalRebuildSitesPhp(),
        ]);

        return $cb;
    }

    /**
     * @param string $siteId Directory name
     *
     * @return \Robo\Collection\CollectionBuilder
     */
    public function siteDelete(string $siteId = 'default'): CollectionBuilder
    {
        $this->validateArgSiteId($siteId);

        return $this
            ->collectionBuilder()
            ->addCode($this->getTaskDrupalSiteDelete($siteId))
            ->addTask($this->getTaskDrupalRebuildSitesPhp());
    }

    public function siteInstall(string $siteId = 'default'): CollectionBuilder
    {
        $this->validateArgSiteId($siteId);

        return $this
            ->collectionBuilder()
            ->addCode($this->getTaskUnlockSettingsPhp($siteId))
            ->addCode($this->getTaskPublicFilesClean($siteId))
            ->addCode($this->getTaskPrivateFilesClean($siteId))
            ->addTask($this->getTaskSiteInstall($siteId));
    }
    //endregion

    //region Lint.
    public function lint(array $extensionNames): CollectionBuilder
    {
        $extensionNames = $this->validateArgExtensionNames($extensionNames);
        $managedDrupalExtensions = $this->getManagedDrupalExtensions();

        $cb = $this->collectionBuilder();
        foreach ($extensionNames as $extensionName) {
            $extension = $managedDrupalExtensions[$extensionName];
            $cb->addTask($this->getTaskPhpcsLintDrupalExtension($extension));

            if ($extension->hasSCSS) {
                $cb->addTask($this->getTaskScssLintDrupalExtension($extension));
            }

            if ($extension->hasTypeScript) {
                $cb->addTask($this->getTaskTsLintDrupalExtension($extension));
            } else {
                $cb->addTask($this->getTaskESLintDrupalExtension($extension));
            }
        }

        return $cb;
    }

    public function lintPhpcs(array $extensionNames): CollectionBuilder
    {
        $extensionNames = $this->validateArgExtensionNames($extensionNames);
        $managedDrupalExtensions = $this->getManagedDrupalExtensions();

        $cb = $this->collectionBuilder();
        foreach ($extensionNames as $extensionName) {
            $extension = $managedDrupalExtensions[$extensionName];
            $cb->addTask($this->getTaskPhpcsLintDrupalExtension($extension));
        }

        return $cb;
    }

    public function lintScss(array $extensionNames): CollectionBuilder
    {
        // @todo Configurable directory for "css".
        $extensionNames = $this->validateArgExtensionNames($extensionNames);
        $managedDrupalExtensions = $this->getManagedDrupalExtensions();

        $cb = $this->collectionBuilder();
        foreach ($extensionNames as $extensionName) {
            $extension = $managedDrupalExtensions[$extensionName];
            if ($extension->hasSCSS) {
                $cb->addTask($this->getTaskScssLintDrupalExtension($extension));
            }
        }

        return $cb;
    }

    public function lintTs(array $extensionNames): CollectionBuilder
    {
        // @todo Configurable directory for "js".
        $extensionNames = $this->validateArgExtensionNames($extensionNames);
        $managedDrupalExtensions = $this->getManagedDrupalExtensions();

        $cb = $this->collectionBuilder();
        foreach ($extensionNames as $extensionName) {
            $extension = $managedDrupalExtensions[$extensionName];
            if ($extension->hasTypeScript) {
                $cb->addTask($this->getTaskTsLintDrupalExtension($extension));
            }
        }

        return $cb;
    }

    public function lintEs(array $extensionNames): CollectionBuilder
    {
        // @todo Configurable directory for "js".
        $extensionNames = $this->validateArgExtensionNames($extensionNames);
        $managedDrupalExtensions = $this->getManagedDrupalExtensions();

        $cb = $this->collectionBuilder();
        foreach ($extensionNames as $extensionName) {
            $extension = $managedDrupalExtensions[$extensionName];
            if (!$extension->hasTypeScript) {
                $cb->addTask($this->getTaskESLintDrupalExtension($extension));
            }
        }

        return $cb;
    }
    //endregion

    //region Test - Drupal.
    public function testDrupal(
        array $args,
        array $options = [
            'site' => '',
            'php' => '',
            'db' => '',
        ]
    ): CollectionBuilder {
        $siteId = $this->validateInputSiteId($options['site']);
        $phpVariants = $this->validateInputPhpVariantIds($options['php']);
        $databaseServers = $this->validateInputDatabaseServerIds($options['db']);

        $subjects = [];
        foreach ($args as $arg) {
            $subjects = array_merge($subjects, explode(',', $arg));
        }

        $cb = $this->collectionBuilder();

        if (!$subjects) {
            $cb->addCode(function () {
                $this->yell('@todo Better error message. Subject is mandatory.', 40, 'red');

                return 1;
            });

            return $cb;
        }

        $placeholders = [
            '{php}' => '',
            '{db}' => '',
            '{siteBranch}' => $siteId,
        ];
        foreach ($databaseServers as $databaseServer) {
            $tasks = [];
            $placeholders['{db}'] = $databaseServer->id;
            foreach ($phpVariants as $phpVariant) {
                $placeholders['{php}'] = $phpVariant->id;
                $url = $this->projectConfig->getSiteVariantUrl($placeholders);

                if (!$tasks) {
                    $tasks['enable.simpletest'] = $this->getTaskDrushPmEnable($url, ['simpletest']);
                }

                $taskId = "run-tests.{$phpVariant->id}.{$databaseServer->id}";
                $tasks[$taskId] = $this->getTaskDrupalCoreTestsRun($subjects, $siteId, $phpVariant, $databaseServer);
            }

            $cb->addTaskList($tasks);
        }

        return $cb;
    }

    public function testDrupalClean(): CollectionBuilder
    {
        return $this->getTaskDrupalCoreTestsClean();
    }

    public function testDrupalList(): CollectionBuilder
    {
        return $this->getTaskDrupalCoreTestsList();
    }
    //endregion

    //region Argument validators.
    /**
     * @return $this
     */
    protected function validateArgSiteId(string $siteId)
    {
        if ($siteId && !array_key_exists($siteId, $this->projectConfig->sites)) {
            throw new \InvalidArgumentException("Unknown site ID: '$siteId'", 1);
        }

        return $this;
    }

    protected function validateArgExtensionNames(array $extensions): array
    {
        // @todo Show a an error message in case of duplicated items.
        $managedDrupalExtensions = $this->getManagedDrupalExtensions();

        $nonExistsExtensions = array_diff_key(array_flip($extensions), $managedDrupalExtensions);
        if ($nonExistsExtensions) {
            throw new \InvalidArgumentException(
                'Unknown managed Drupal extensions: ' . implode(', ', array_keys($nonExistsExtensions))
            );
        }

        if (!$extensions) {
            $extensions = array_keys($managedDrupalExtensions);
        }

        return $extensions;
    }

    protected function validateInputSiteId(string $input): string
    {
        if ($input) {
            if (!isset($this->projectConfig->sites[$input])) {
                throw new \InvalidArgumentException('@todo');
            }

            return $input;
        }

        return $this->projectConfig->getDefaultSiteId();
    }

    /**
     * @param string $input
     *
     * @return PhpVariantConfig[]
     */
    protected function validateInputPhpVariantIds(string $input): array
    {
        return $this->validateInputIdList(
            $input,
            $this->projectConfig->phpVariants,
            'Unknown PHP variant identifiers: "%s"'
        );
    }

    /**
     * @param string $input
     *
     * @return DatabaseServerConfig[]
     */
    protected function validateInputDatabaseServerIds(string $input): array
    {
        return $this->validateInputIdList(
            $input,
            $this->projectConfig->databaseServers,
            'Unknown Database Server identifiers: "%s"'
        );
    }

    /**
     * @param string $input
     *
     * @return array
     */
    protected function validateInputIdList(string $input, array $available, string $errorMsgTpl): array
    {
        if (!$input) {
            return $available;
        }

        $ids = explode(',', $input);
        $missingIds = array_diff($ids, array_keys($available));
        if ($missingIds) {
            throw new \InvalidArgumentException(sprintf($errorMsgTpl, implode(', ', $missingIds)));
        }

        return array_intersect_key($available, array_flip($ids));
    }
    //endregion

    /**
     * Rebuild DRUPAL_ROOT/sites/sites.php.
     */
    public function rebuildSitesPhp(): TaskInterface
    {
        return $this->getTaskDrupalRebuildSitesPhp();
    }

    protected function getTaskPhpcsLintDrupalExtension(DrupalExtensionConfig $extension): TaskInterface
    {
        $options = [
            'workingDirectory' => $extension->path,
            'files' => [
                '.' => true,
            ],
        ];

        $options['files']['**/*.css'] = !$extension->hasSCSS;
        $options['ignore']['*.css'] = $extension->hasSCSS;

        $options['files']['**/*.js'] = !$extension->hasTypeScript;
        $options['ignore']['*.js'] = $extension->hasTypeScript;

        return $this->getTaskPhpcsLint($options);
    }

    protected function getTaskPhpcsLint(array $options = []): TaskInterface
    {
        $environment = $this->getEnvironment();

        $options += [
            'workingDirectory' => '',
            'standard' => 'Drupal',
            'failOn' => 'warning',
            'lintReporters' => [
                'lintVerboseReporter' => null,
            ],
            'ignore' => [],
            'extensions' => [],
            'files' => [
              '.',
            ],
        ];

        $standardLower = strtolower($options['standard']);

        $options['ignore'] += [
            'node_modules/' => true,
            '.nvmrc' => true,
            '.gitignore' => true,
            '*.json' => true,
            '*.scss' => true,
        ];
        $options['extensions'] += [
            'php/PHP' => true,
            'inc/PHP' => true,
        ];

        if ($options['standard'] === 'Drupal') {
            $options['extensions'] += [
                'engine/PHP' => true,
                'install/PHP' => true,
                'module/PHP' => true,
                'profile/PHP' => true,
                'theme/PHP' => true,
                'js/JS' => true,
                'css/CSS' => true,
            ];
        }

        if (!empty($options['workingDirectory'])) {
            $options['phpcsExecutable'] = Path::makeAbsolute("{$this->binDir}/phpcs", getcwd());
        }

        if ($environment === 'jenkins') {
            $options['failOn'] = 'never';

            $options['lintReporters']['lintCheckstyleReporter'] = (new CheckstyleReporter())
                ->setDestination("reports/checkstyle/phpcs.{$standardLower}.xml");
        }

        if ($environment !== 'git-hook') {
            return $this->taskPhpcsLintFiles($options);
        }

        $files = $options['files'];
        unset($options['files']);

        $options['ignore'] += [
            '*.ts' => true,
            '*.rb' => true,
        ];

        $assetJar = new AssetJar();

        return $this
            ->collectionBuilder()
            ->addTaskList([
                'git.readStagedFiles' => $this
                    ->taskGitReadStagedFiles()
                    ->setWorkingDirectory($options['workingDirectory'])
                    ->setCommandOnly(true)
                    ->setAssetJar($assetJar)
                    ->setAssetJarMap('files', ['files'])
                    ->setPaths($files),
                "lint.phpcs.{$standardLower}" => $this
                    ->taskPhpcsLintInput($options)
                    ->setAssetJar($assetJar)
                    ->setAssetJarMap('files', ['files']),
            ]);
    }

    protected function getTaskScssLintDrupalExtension(DrupalExtensionConfig $extension): TaskInterface
    {
        $task = $this
            ->taskScssLintRunFiles()
            ->setFailOn('warning')
            ->setWorkingDirectory($extension->path)
            ->addLintReporter('lintVerboseReporter')
            ->setExclude('*.css')
            ->setPaths([
                'css/',
            ]);

        $gemFile = $this->getFallbackFileName('Gemfile', $extension->path);
        if ($gemFile) {
            $task->setBundleGemFile($gemFile);
        }

        return $task;
    }

    protected function getTaskTsLintDrupalExtension(DrupalExtensionConfig $extension): TaskInterface
    {
        $task = $this
            ->taskTsLintRun()
            ->setWorkingDirectory($extension->path)
            ->setFailOn('warning')
            ->addLintReporter('verbose:StdOutput', 'lintVerboseReporter')
            ->setPaths([
                'js/**/*.ts',
            ]);

        $configFile = $this->getFallbackFileName('tslint.json', $extension->path);
        if ($configFile) {
            $task->setConfigFile($configFile);
        }

        return $task;
    }

    protected function getTaskESLintDrupalExtension(DrupalExtensionConfig $extension): TaskInterface
    {
        $eslintExecutable = $this->getFallbackFileName('node_modules/.bin/eslint', $extension->path);
        $configFile = $this->getFallbackFileName('.eslintrc', $extension->path);
        $task = $this
            ->taskESLintRunFiles()
            ->setWorkingDirectory(Path::makeRelative($extension->path, getcwd()))
            ->setEslintExecutable(Path::makeRelative($eslintExecutable, $extension->path))
            ->setFailOn('warning')
            ->addLintReporter('verbose:StdOutput', 'lintVerboseReporter')
            ->setFiles([
                'js/**/*.js',
            ]);

        if ($configFile) {
            $task->setConfigFile(Path::makeRelative($configFile, $extension->path));
        }

        return $task;
    }

    protected function getTaskDrupalRebuildSitesPhp(): CollectionBuilder
    {
        return $this->taskDrupalRebuildSitesPhp([
            'projectConfig' => $this->projectConfig,
        ]);
    }

    protected function getTaskDrupalSiteCreate(array $options): CollectionBuilder
    {
        $options['projectConfig'] = $this->projectConfig;

        return $this->taskDrupalSiteCreate($options);
    }

    protected function getTaskDrupalSiteDelete(string $siteId): \Closure
    {
        // @todo Create a native Task.
        // @todo Separate Tasks, dir delete, ProjectConfig manipulation.
        // @todo Delete other resources: databases.
        // @todo Delete other resources: Solr, Elastic.
        // @todo Delete other resources: Nginx, Apache.
        return function () use ($siteId) {
            $filesToDelete = [];
            $dirsToDelete = [
                $rootSiteDir = "{$this->projectConfig->outerSitesSubDir}/$siteId",
            ];

            if ($siteId === 'default') {
                $finder = Finder::create()
                    ->in("{$this->projectConfig->drupalRootDir}/$rootSiteDir")
                    ->depth('== 0')
                    ->notName('default.services.yml')
                    ->notName('default.settings.php');

                foreach ($finder as $file) {
                    if ($file->isDir()) {
                        $dirsToDelete[] = $file->getPathname();
                    } else {
                        $filesToDelete[] = $file->getPathname();
                    }
                }
            } else {
                $dirsToDelete[] = "{$this->projectConfig->drupalRootDir}/$rootSiteDir";
            }

            $this->_deleteDir(array_filter($dirsToDelete, 'is_dir'));
            $this->_remove($filesToDelete);

            $projectConfigFileName = 'ProjectConfig.php';
            if (file_exists($projectConfigFileName)) {
                $lines = file($projectConfigFileName);
                $lineIndex = 0;

                $siteIdSafe = var_export($siteId, true);
                $first = "  \$projectConfig->sites[$siteIdSafe] = new SiteConfig();\n";
                while ($lineIndex < count($lines) && $lines[$lineIndex] !== $first) {
                    $lineIndex++;
                }

                if ($lineIndex < count($lines) && $lines[$lineIndex] === $first) {
                    if (isset($lines[$lineIndex - 1]) && $lines[$lineIndex - 1] === "\n") {
                        // Previous empty line.
                        unset($lines[$lineIndex - 1]);
                    }

                    do {
                        unset($lines[$lineIndex++]);
                    } while (isset($lines[$lineIndex]) && $lines[$lineIndex] !== "\n");
                }

                // @todo Error handling.
                file_put_contents($projectConfigFileName, implode('', $lines));
            }

            unset($this->projectConfig->sites[$siteId]);

            return 0;
        };
    }

    /**
     * Build a pre-configured DrushSiteInstall task.
     *
     * @todo Support advanced config management tools.
     */
    protected function getTaskSiteInstall(string $siteId): TaskInterface
    {
        $backToRootDir = $this->backToRootDir($this->projectConfig->drupalRootDir);
        $site = $this->projectConfig->sites[$siteId];
        $cmdPattern = '%s --yes --sites-subdir=%s';
        $cmdArgs = [
            escapeshellcmd("$backToRootDir/{$this->binDir}/drush"),
            escapeshellarg($site->id),
        ];

        $configDir = "{$this->projectConfig->outerSitesSubDir}/{$site->id}/config/sync";
        if (file_exists($configDir) && glob("$configDir/*.yml")) {
            $cmdPattern .= ' --config-dir=%s';
            $cmdArgs[] = escapeshellarg("$backToRootDir/$configDir");
        }

        $cmdPattern .= ' site-install %s';
        $cmdArgs[] = escapeshellarg($site->installProfileName);

        return $this
            ->taskExec(vsprintf($cmdPattern, $cmdArgs))
            ->dir($this->projectConfig->drupalRootDir);
    }

    protected function getTaskPublicFilesClean(string $siteId): \Closure
    {
        return $this->getTaskDirectoryClean("{$this->projectConfig->drupalRootDir}/sites/{$siteId}/files");
    }

    protected function getTaskPrivateFilesClean($siteId): \Closure
    {
        return $this->getTaskDirectoryClean("{$this->projectConfig->outerSitesSubDir}/{$siteId}/private");
    }

    protected function getTaskDirectoryClean(string $dir): \Closure
    {
        return function () use ($dir) {
            $this->_mkdir($dir);

            $entry = new \DirectoryIterator($dir);
            while ($entry->valid()) {
                if (!$entry->isDot() && $entry->isDir()) {
                    $this->_deleteDir($entry->getRealPath());
                } elseif ($entry->isFile() || $entry->isLink()) {
                    $this->_remove($entry->getRealPath());
                }

                $entry->next();
            }

            return 0;
        };
    }

    protected function getTaskDrushPmEnable(string $uri, array $extensions): CollectionBuilder
    {
        $options = [
            'root' => $this->projectConfig->drupalRootDir,
            'uri' => $uri,
        ];

        return $this->taskDrush('pm-enable', $options, $extensions);
    }

    protected function getTaskGitHookInstall(DrupalExtensionConfig $extension): \Closure
    {
        return function () use ($extension) {
            /** @var \Psr\Log\LoggerInterface $logger */
            $logger = $this->getContainer()->get('logger');
            $logger->notice(
                'Install Git hooks for "<info>{extension}</info>"',
                [
                    'extension' => $extension->packageName,
                ]
            );

            $mask = umask();
            $fs = new Filesystem();
            $hostDir = getcwd();
            $srcDirUpstream = $this->getPackagePath('cheppers/git-hooks') . '/git-hooks';
            $srcDirCustom = "{$this->roboDrupalRoot}/src/GitHooks";
            // @todo Support .git pointers.
            $dstDir = "{$extension->path}/.git/hooks";

            $fs->mirror($srcDirUpstream, $dstDir, null, ['override' => true]);
            $fs->copy("$srcDirCustom/_common", "$dstDir/_common", true);

            $file = new \DirectoryIterator($srcDirUpstream);
            while ($file->valid()) {
                if ($file->isFile() && is_executable($file->getPathname())) {
                    $fs->chmod("$dstDir/" . $file->getBasename(), 0777, $mask);
                }

                $file->next();
            }

            $configFileName = '_config';
            $configContentPattern = implode("\n", [
                '#!/usr/bin/env bash',
                '',
                'roboDrupalTask="githook:${roboDrupalHookName}"',
                'roboDrupalHostDir=%s',
                'roboDrupalExtensionName=%s',
                '',
            ]);
            $configContentArgs = [
                escapeshellarg($hostDir),
                escapeshellarg($extension->packageName)
            ];
            $configContent = vsprintf($configContentPattern, $configContentArgs);
            $result = file_put_contents("$dstDir/$configFileName", $configContent);
            if ($result === false) {
                throw new \Exception("Failed to install git hooks for '{$extension->packageName}'.");
            }

            return 0;
        };
    }

    /**
     * @param string[] $subjects
     *
     * @return \Cheppers\Robo\Drupal\Robo\Task\CoreTests\RunTask|\Robo\Collection\CollectionBuilder
     */
    protected function getTaskDrupalCoreTestsRun(
        array $subjects,
        string $siteId,
        PhpVariantConfig $phpVariant,
        DatabaseServerConfig $databaseServer
    ): CollectionBuilder {
        $url = $this->projectConfig->getSiteVariantUrl([
            '{siteBranch}' => $siteId,
            '{php}' => $phpVariant->id,
            '{db}' => $databaseServer->id,
        ]);

        $backToRootDir = $this->backToRootDir($this->projectConfig->drupalRootDir);

        // @todo Configurable protocol. HTTP vs HTTPS.
        return $this
            ->taskDrupalCoreTestsRun()
            ->setDrupalRoot($this->projectConfig->drupalRootDir)
            ->setUrl("http://$url")
            ->setXml(Path::join($backToRootDir, $this->projectConfig->reportsDir, 'tests'))
            ->setColorized(true)
            ->setNonHtml(true)
            ->setPhpExecutable(PHP_BINARY)
            ->setPhp($phpVariant->getPhpExecutable())
            ->setArguments($subjects);
    }

    /**
     * @return \Cheppers\Robo\Drupal\Robo\Task\CoreTests\CleanTask|\Robo\Collection\CollectionBuilder
     */
    protected function getTaskDrupalCoreTestsClean(): CollectionBuilder
    {
        return $this
            ->taskDrupalCoreTestsClean()
            ->setDrupalRoot($this->projectConfig->drupalRootDir);
    }

    /**
     * @return \Cheppers\Robo\Drupal\Robo\Task\CoreTests\ListTask|\Robo\Collection\CollectionBuilder
     */
    protected function getTaskDrupalCoreTestsList(): CollectionBuilder
    {
        return $this
            ->taskDrupalCoreTestsList()
            ->setOutput($this->output())
            ->setDrupalRoot($this->projectConfig->drupalRootDir);
    }

    protected function getFallbackFileName(string $fileName, string $path): string
    {
        if (file_exists("$path/$fileName")) {
            return '';
        }

        $paths = [
            getcwd(),
            $this->roboDrupalRoot,
        ];

        $root = [
            'Gemfile',
        ];
        foreach ($paths as $path) {
            if (strpos($fileName, 'node_modules/.bin/') === 0) {
                if (file_exists("$path/$fileName")) {
                    return "$path/$fileName";
                }
            } elseif (in_array($fileName, $root)) {
                if (file_exists("$path/$fileName")) {
                    return "$path/$fileName";
                }
            } elseif (file_exists("$path/src/$fileName")) {
                return "$path/src/$fileName";
            }
        }

        throw new \InvalidArgumentException("Has no fallback for file: '$fileName'");
    }

    /**
     * @var null|array
     */
    protected $packagePaths = null;

    /**
     * @return string[]
     */
    protected function getPackagePaths(): array
    {
        if ($this->packagePaths === null) {
            $ppResult = $this
                ->taskComposerPackagePaths([
                    'composerExecutable' => $this->projectConfig->composerExecutable,
                ])
                ->run()
                ->stopOnFail();

            $this->packagePaths = $ppResult['packagePaths'];
        }

        return $this->packagePaths;
    }

    protected function getPackagePath(string $packageId): string
    {
        $pp = $this->getPackagePaths();

        return $pp[$packageId] ?? '';
    }

    /**
     * @return \Cheppers\Robo\Drupal\Config\DrupalExtensionConfig[]
     */
    protected function getManagedDrupalExtensions(): array
    {
        $this->initManagedDrupalExtensions();

        return Utils::filterDisabled($this->projectConfig->managedDrupalExtensions);
    }

    /**
     * @return $this
     */
    protected function initManagedDrupalExtensions()
    {
        if (!$this->projectConfig->autodetectManagedDrupalExtensions
            || $this->areManagedDrupalExtensionsInitialized
        ) {
            return $this;
        }

        $namesAndPaths = $this->collectManagedDrupalExtensions();
        foreach ($namesAndPaths as $packageName => $path) {
            list($vendor, $name) = explode('/', $packageName);
            if (!isset($this->projectConfig->managedDrupalExtensions[$name])) {
                $this->projectConfig->managedDrupalExtensions[$name] = new DrupalExtensionConfig();
            }

            $ec = $this->projectConfig->managedDrupalExtensions[$name];
            $ec->name = $packageName;
            $ec->path = $path;
            $ec->packageVendor = $vendor;
            $ec->packageName = $name;
            $ec->hasGit = file_exists("$path/.git");
            $ec->hasTypeScript = $this->hasDrupalExtensionTypeScript($path);
            $ec->hasSCSS = $this->hasDrupalExtensionScss($path);

            if (!$ec->phpcs->paths) {
                 $ec->phpcs->paths = ['.'];
            }
        }

        $this->areManagedDrupalExtensionsInitialized = true;

        return  $this;
    }

    /**
     * Collect those Drupal extensions which are managed by this RoboFile.
     *
     * Composer uses symlinks on *nix systems to install local packages,
     * Usually those packages are outside the project root and the
     * `composer show -P` command resolves their real absolute path.
     *
     * @todo Cache.
     *
     * @return string[]
     */
    protected function collectManagedDrupalExtensions(): array
    {
        $this->initComposerLock();
        $managedExtensions = [];
        $packagePaths = $this->getPackagePaths();

        $currentDir = getcwd();
        foreach ($packagePaths as $packageName => $packagePath) {
            foreach (['packages', 'packages-dev'] as $lockKey) {
                // @todo Do we need the packages without ".git" dir?
                if (isset($this->composerLock[$lockKey][$packageName])
                    && Utils::isDrupalPackage($this->composerLock[$lockKey][$packageName])
                    && strpos($packagePath, $currentDir) !== 0
                ) {
                    $managedExtensions[$packageName] = $packagePath;
                }
            }
        }

        return $managedExtensions;
    }

    protected function hasDrupalExtensionTypeScript(string $path): bool
    {
        // @todo Better detection.
        return file_exists("$path/tsconfig.json");
    }

    protected function hasDrupalExtensionScss(string $path): bool
    {
        // @todo Better detection.
        return file_exists("$path/config.rb");
    }
}