<?php

declare(strict_types=1);

namespace kuiper\skeleton;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Script\Event;
use Symfony\Component\Filesystem\Filesystem;

class Installer
{
    public const HTTP_SERVER = 1;
    public const JSONRPC_OVER_HTTP = 2;
    public const JSONRPC_OVER_TCP = 3;
    public const TARS_HTTP_SERVER = 4;
    public const TARS_TCP_SERVER = 5;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var string
     */
    private $projectRoot;

    /**
     * @var Filesystem
     */
    private $fileSystem;

    /**
     * @var RootPackageInterface
     */
    private $rootPackage;

    /**
     * @var JsonFile
     */
    private $composerJson;

    /**
     * @var array
     */
    private $composerDefinition;

    /**
     * @var Link[]
     */
    private $composerRequires;

    /**
     * @var Link[]
     */
    private $composerDevRequires;

    /**
     * @var array
     */
    private $stabilityFlags;

    /**
     * @var string
     */
    private $packageName;

    /**
     * @var int
     */
    private $sererType;

    /**
     * @var string
     */
    private $namespace;

    /**
     * @var string
     */
    private $appName;

    /**
     * @var string
     */
    private $serverName;
    /**
     * @var int
     */
    private $port;

    private static $REQUIRES = [
        self::HTTP_SERVER => [
            'kuiper/cache' => '^0.6',
            'kuiper/web' => '^0.6',
            'twig/twig' => '^3.0',
        ],
        self::JSONRPC_OVER_HTTP => [
            'kuiper/jsonrpc' => '^0.6',
        ],
        self::JSONRPC_OVER_TCP => [
            'kuiper/jsonrpc' => '^0.6',
        ],
        self::TARS_HTTP_SERVER => [
            'kuiper/tars' => '^0.6',
            'kuiper/cache' => '^0.6',
            'kuiper/web' => '^0.6',
            'twig/twig' => '^3.0',
        ],
        self::TARS_TCP_SERVER => [
            'kuiper/tars' => '^0.6',
        ],
    ];

    private static $REQUIRES_DEV = [
        self::TARS_TCP_SERVER => [
            'wenbinye/tars-gen' => '^0.4',
        ],
    ];

    private static $INSTALLER_DEPS = [
        'composer/composer',
    ];

    private static $PLACEHOLDER_FILES = [
        'env.example',
        '.env',
        'src/config.php',
        'config.conf.example',
        'config.conf',
        'tars/servant/hello.tars',
        'src/application/controller/IndexController.php',
        'src/application/HelloServantImpl.php',
        'src/servant/HelloServant.php',
        'src/service/HelloService.php',
        'src/service/HelloServiceImpl.php',
    ];

    public function __construct(IOInterface $io, Composer $composer, ?string $projectRoot = null)
    {
        $this->io = $io;

        $this->fileSystem = new Filesystem();

        // Get composer.json location
        $composerFile = Factory::getComposerFile();

        // Calculate project root from composer.json, if necessary
        $this->projectRoot = $projectRoot ?: realpath(dirname($composerFile));
        $this->projectRoot = rtrim($this->projectRoot, '/\\').'/';

        // Parse the composer.json
        $this->parseComposerDefinition($composer, $composerFile);
    }

    /**
     * Parses the composer file and populates internal data.
     */
    private function parseComposerDefinition(Composer $composer, string $composerFile): void
    {
        $this->composerJson = new JsonFile($composerFile);
        $this->composerDefinition = $this->composerJson->read();

        // Get root package or root alias package
        $this->rootPackage = $composer->getPackage();
        $this->composerRequires = $this->rootPackage->getRequires();
        $this->composerDevRequires = $this->rootPackage->getDevRequires();
        $this->stabilityFlags = $this->rootPackage->getStabilityFlags();
    }

    public static function install(Event $event): void
    {
        $installer = new self($event->getIO(), $event->getComposer());

        $installer->io->write('<info>Setting up optional packages</info>');
        $installer->packageName = $installer->askPackageName();
        $installer->sererType = $installer->askServerType();
        $installer->namespace = $installer->askNamespace();
        if ($installer->isTarsServer()) {
            $installer->appName = $installer->askAppName();
            $installer->serverName = $installer->askServerName();
        } else {
            $installer->port = $installer->askPort();
        }

        $installer->setupServer();
        $installer->replacePlaceHolder();
        $installer->fixComposerDefinition();
        $installer->addPackages();
        $installer->updateRootPackage();
        $installer->finalizePackage();
    }

    private function isTarsServer(): bool
    {
        return in_array($this->sererType, [self::TARS_TCP_SERVER, self::TARS_HTTP_SERVER], true);
    }

    private function isHttpServer(): bool
    {
        return in_array($this->sererType, [self::HTTP_SERVER, self::TARS_HTTP_SERVER], true);
    }

    private function isJsonRpcServer(): bool
    {
        return in_array($this->sererType, [self::JSONRPC_OVER_HTTP, self::JSONRPC_OVER_TCP], true);
    }

    /**
     * Update the root package based on current state.
     */
    private function updateRootPackage(): void
    {
        $this->rootPackage->setRequires($this->composerRequires);
        $this->rootPackage->setDevRequires($this->composerDevRequires);
        $this->rootPackage->setStabilityFlags($this->stabilityFlags);
        $this->rootPackage->setAutoload($this->composerDefinition['autoload']);
        $this->rootPackage->setDevAutoload($this->composerDefinition['autoload-dev']);
        $this->rootPackage->setExtra($this->composerDefinition['extra'] ?? []);
    }

    private function fixComposerDefinition(): void
    {
        $this->io->write('<info>Removing installer development dependencies</info>');
        foreach (self::$INSTALLER_DEPS as $devDependency) {
            unset($this->composerDefinition['require-dev'][$devDependency],
                $this->composerDevRequires[$devDependency],
                $this->stabilityFlags[$devDependency]);
        }

        $this->io->write('<info>Remove installer</info>');
        $this->composerDefinition['name'] = $this->packageName;
        $this->composerDefinition['autoload']['psr-4'][$this->namespace.'\\'] = 'src/';
        $this->composerDefinition['autoload-dev']['psr-4'][$this->namespace.'\\'] = 'tests/';

        // Remove installer script autoloading rules
        unset($this->composerDefinition['autoload']['psr-4'][__NAMESPACE__.'\\']);

        // Remove installer scripts
        unset($this->composerDefinition['scripts']['pre-update-cmd']);
        unset($this->composerDefinition['scripts']['pre-install-cmd']);
    }

    private function addPackages(): void
    {
        foreach (self::$REQUIRES[$this->sererType] ?? [] as $packageName => $packageVersion) {
            $this->io->write(sprintf(
                '  - Adding package <info>%s</info> (<comment>%s</comment>)',
                $packageName,
                $packageVersion
            ));

            // Get the version constraint
            $versionParser = new VersionParser();
            $constraint = $versionParser->parseConstraints($packageVersion);

            // Create package link
            $link = new Link('__root__', $packageName, $constraint, 'requires', $packageVersion);

            unset($this->composerDefinition['require-dev'][$packageName],
                $this->composerDevRequires[$packageName]);

            $this->composerDefinition['require'][$packageName] = $packageVersion;
            $this->composerRequires[$packageName] = $link;
            $this->setPackageStabilityFalg($packageName, $packageVersion);
        }
        foreach (self::$REQUIRES_DEV[$this->sererType] ?? [] as $packageName => $packageVersion) {
            $this->io->write(sprintf(
                '  - Adding dev package <info>%s</info> (<comment>%s</comment>)',
                $packageName,
                $packageVersion
            ));

            // Get the version constraint
            $versionParser = new VersionParser();
            $constraint = $versionParser->parseConstraints($packageVersion);

            // Create package link
            $link = new Link('__root__', $packageName, $constraint, 'requires', $packageVersion);

            unset($this->composerDefinition['require-dev'][$packageName],
                $this->composerDevRequires[$packageName]);

            $this->composerDefinition['require-dev'][$packageName] = $packageVersion;
            $this->composerDevRequires[$packageName] = $link;

            // Set package stability if needed
            $this->setPackageStabilityFalg($packageName, $packageVersion);
        }
    }

    private function finalizePackage(): void
    {
        // Update composer definition
        $this->composerJson->write($this->composerDefinition);
        $this->fileSystem->remove($this->projectRoot.'/skeleton');
    }

    private function askPackageName(): string
    {
        $defaultNs = get_current_user().'/'.basename(getcwd());
        $query = ["<question>Package name (<vendor>/<name>)</question><comment>($defaultNs)</comment>: "];

        while (true) {
            $answer = $this->io->ask(implode($query), $defaultNs);
            if ($this->isValidPackage($answer)) {
                return $answer;
            }
            $this->io->write('<error>Invalid package name</error>');
        }
    }

    private function askServerType(): int
    {
        $query = [
            "<question>Choose server type</question>: \n",
            "[<comment>1</comment>] Http Web Server\n",
            "[<comment>2</comment>] JsonRPC Web Server\n",
            "[<comment>3</comment>] JsonRPC TCP Server\n",
            "[<comment>4</comment>] Tars HTTP Web Server\n",
            "[<comment>5</comment>] Tars TCP RPC Server\n",
            'Make your selection <comment>(1)</comment>: ',
        ];

        while (true) {
            $answer = (int) $this->io->ask(implode($query), '1');

            if ($answer >= self::HTTP_SERVER && $answer <= self::TARS_TCP_SERVER) {
                return $answer;
            }

            $this->io->write('<error>Invalid answer</error>');
        }
    }

    private function askNamespace(): string
    {
        $defaultNs = basename(getcwd());
        $query = ["<question>PHP namespace</question><comment>($defaultNs)</comment>: "];

        while (true) {
            $answer = $this->io->ask(implode($query), $defaultNs);
            if ($this->isValidNamespace($answer)) {
                return $answer;
            }
            $this->io->write('<error>Invalid namespace</error>');
        }
    }

    private function askAppName(): string
    {
        while (true) {
            $answer = $this->io->ask('<question>Tars application name</question>: ');
            if ($this->isValidName($answer)) {
                return $answer;
            }
            $this->io->write('<error>Invalid app name, it should be matching \w+</error>');
        }
    }

    private function askServerName(): string
    {
        while (true) {
            $answer = $this->io->ask('<question>Tars server name</question>: ');
            if ($this->isValidName($answer)) {
                return $answer;
            }
            $this->io->write('<error>Invalid server name, it should be matching \w+</error>');
        }
    }

    private function askPort(): int
    {
        while (true) {
            $answer = trim($this->io->ask('<question>Which port to listen</question>: '));
            if (preg_match('/^\d+$/', $answer)) {
                return (int) $answer;
            }
            $this->io->write('<error>Invalid port, it should be an integer</error>');
        }
    }

    private function isValidNamespace(string $answer): bool
    {
        return (bool) preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\\\\]*[a-zA-Z0-9_\x7f-\xff]$/', $answer);
    }

    private function isValidName(string $answer): bool
    {
        return (bool) preg_match('/^\w+$/', $answer);
    }

    private function isValidPackage(string $packageName): bool
    {
        return (bool) preg_match('#[a-z0-9_.-]+/[a-z0-9_.-]+#', $packageName);
    }

    private function replacePlaceHolder(): void
    {
        foreach (self::$PLACEHOLDER_FILES as $file) {
            if (!file_exists($file)) {
                continue;
            }
            $content = file_get_contents($file);
            $replace = strtr($content, [
                '{namespace}' => $this->namespace,
                '{AppName}' => $this->appName,
                '{ServerName}' => $this->serverName,
                '{protocol}' => self::TARS_TCP_SERVER === $this->sererType ? 'tars' : 'not_tars',
                '{port}' => $this->port,
                '{ServerType}' => in_array($this->sererType, [
                    self::HTTP_SERVER, self::JSONRPC_OVER_HTTP, self::TARS_HTTP_SERVER,
                ], true) ? 'http' : 'tcp',
            ]);
            file_put_contents($file, $replace);
        }
    }

    private function setupServer(): void
    {
        $this->fileSystem->copy(__DIR__.'/templates/env.example', 'env.example');
        $this->fileSystem->copy(__DIR__.'/templates/env.example', '.env');
        $this->composerDefinition['scripts']['serve'] = '@php src/index.php';
        if ($this->isTarsServer()) {
            if (self::TARS_HTTP_SERVER === $this->sererType) {
                $this->fileSystem->copy(__DIR__.'/templates/config.tars-http.php', 'src/config.php');
            } else {
                $this->fileSystem->copy(__DIR__.'/templates/config.tars.php', 'src/config.php');
                $this->fileSystem->copy(__DIR__.'/templates/tars/servant/hello.tars', 'tars/servant/hello.tars');
                $this->fileSystem->copy(__DIR__.'/templates/src/application/HelloServantImpl.php',
                    'src/application/HelloServantImpl.php');
                $this->fileSystem->copy(__DIR__.'/templates/src/servant/HelloServant.php',
                    'src/servant/HelloServant.php');
            }
            $this->composerDefinition['extra']['tars'] = [
                'manifest' => ['console'],
                'server_name' => $this->serverName,
            ];
            $this->composerDefinition['extra']['kuiper']['configuration'][] = 'kuiper\\tars\\config\\TarsServerConfiguration';
            $this->fileSystem->copy(__DIR__.'/templates/index.tars.php', 'src/index.php');
            $this->fileSystem->copy(__DIR__.'/templates/config.conf.example', 'config.conf.example');
            $this->fileSystem->copy(__DIR__.'/templates/config.conf.example', 'config.conf');
            $this->composerDefinition['scripts']['serve'] = '@php src/index.php --config config.conf';
            $this->composerDefinition['scripts']['package'] = 'kuiper\\tars\\server\\PackageBuilder::run';
            if (self::TARS_TCP_SERVER === $this->sererType) {
                $this->composerDefinition['scripts']['gen'] = './vendor/bin/tars-gen && ./vendor/bin/php-cs-fixer fix src';
            }
        }
        $this->fileSystem->copy(__DIR__.'/templates/index.php', 'src/index.php');
        if ($this->isHttpServer()) {
            if (self::HTTP_SERVER === $this->sererType) {
                $this->fileSystem->copy(__DIR__.'/templates/config.http.php', 'src/config.php');
            }
            $this->fileSystem->mkdir('resources/views');
            $this->fileSystem->touch('resources/views/.gitkeep');
            $this->fileSystem->copy(__DIR__.'/templates/src/application/controller/IndexController.php',
                'src/application/controller/IndexController.php');
            $this->composerDefinition['extra']['kuiper']['configuration'][] = 'kuiper\\web\\WebConfiguration';
        }
        if ($this->isJsonRpcServer()) {
            $this->fileSystem->copy(__DIR__.'/templates/config.server.php', 'src/config.php');
            $this->composerDefinition['extra']['kuiper']['configuration'][] = 'kuiper\\jsonrpc\\config\\JsonRpcServerConfiguration';
            $this->fileSystem->copy(__DIR__.'/templates/src/service/HelloService.php',
                'src/service/HelloService.php');
            $this->fileSystem->copy(__DIR__.'/templates/src/service/HelloServiceImpl.php',
                'src/service/HelloServiceImpl.php');
        }
        chmod('resources/serve.sh', 0755);
    }

    private function setPackageStabilityFalg(string $packageName, string $packageVersion): void
    {
        // Set package stability if needed
        switch (VersionParser::parseStability($packageVersion)) {
            case 'dev':
                $this->stabilityFlags[$packageName] = BasePackage::STABILITY_DEV;
                break;
            case 'alpha':
                $this->stabilityFlags[$packageName] = BasePackage::STABILITY_ALPHA;
                break;
            case 'beta':
                $this->stabilityFlags[$packageName] = BasePackage::STABILITY_BETA;
                break;
            case 'RC':
                $this->stabilityFlags[$packageName] = BasePackage::STABILITY_RC;
                break;
        }
    }
}
