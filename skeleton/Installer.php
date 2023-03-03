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
    private $serverType;

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

    private static array $REQUIRES = [
        self::HTTP_SERVER => [
            'kuiper/cache' => '^0.8',
            'kuiper/web' => '^0.8',
            'twig/twig' => '^3.0',
        ],
        self::JSONRPC_OVER_HTTP => [
            'kuiper/jsonrpc' => '^0.8',
        ],
        self::JSONRPC_OVER_TCP => [
            'kuiper/jsonrpc' => '^0.8',
        ],
        self::TARS_HTTP_SERVER => [
            'kuiper/tars' => '^0.8',
            'kuiper/cache' => '^0.8',
            'kuiper/web' => '^0.8',
            'twig/twig' => '^3.0',
        ],
        self::TARS_TCP_SERVER => [
            'kuiper/tars' => '^0.8',
        ],
    ];

    private static array $REQUIRES_DEV = [
        self::TARS_TCP_SERVER => [
            'wenbinye/tars-gen' => '^0.6',
        ],
        self::TARS_HTTP_SERVER => [
            'wenbinye/tars-gen' => '^0.6',
        ],
    ];

    private static array $INSTALLER_DEPS = [
        'composer/composer',
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
        $installer->serverType = $installer->askServerType();
        $installer->packageName = $installer->askPackageName();
        $installer->namespace = $installer->askNamespace();
        if ($installer->isTarsServer()) {
            $installer->appName = $installer->askAppName();
            $installer->serverName = $installer->askServerName();
        }
        $installer->port = $installer->askPort();

        $installer->replacePlaceHolder();
        $installer->setupServer();
        $installer->fixComposerDefinition();
        $installer->addPackages();
        $installer->updateRootPackage();
        $installer->finalizePackage();
    }

    private function isTarsServer(): bool
    {
        return in_array($this->serverType, [self::TARS_TCP_SERVER, self::TARS_HTTP_SERVER], true);
    }

    private function isHttpServer(): bool
    {
        return in_array($this->serverType, [self::HTTP_SERVER, self::TARS_HTTP_SERVER], true);
    }

    private function isJsonRpcServer(): bool
    {
        return in_array($this->serverType, [self::JSONRPC_OVER_HTTP, self::JSONRPC_OVER_TCP], true);
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
        foreach (self::$REQUIRES[$this->serverType] ?? [] as $packageName => $packageVersion) {
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
            $this->setPackageStabilityFlag($packageName, $packageVersion);
        }
        foreach (self::$REQUIRES_DEV[$this->serverType] ?? [] as $packageName => $packageVersion) {
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
            $this->setPackageStabilityFlag($packageName, $packageVersion);
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
        $query = "<question>Which project package name to use (<vendor>/<name>)</question><comment>($defaultNs)</comment>: ";

        while (true) {
            $answer = $this->io->ask($query, $defaultNs);
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
        $defaultNs = $this->getDefaultNamespace();
        $query = "<question>Which php namespace to use </question><comment>($defaultNs)</comment>: ";

        while (true) {
            $answer = trim($this->io->ask($query, $defaultNs));
            if ($this->isValidNamespace($answer)) {
                return $answer;
            }
            $this->io->write('<error>Invalid namespace</error>');
        }
    }

    private function askAppName(): string
    {
        $defaultApp = explode('\\', $this->getDefaultNamespace())[0];
        $query = "<question>Which Tars application name to use <comment>($defaultApp)</comment></question>: ";
        while (true) {
            $answer = trim($this->io->ask($query, $defaultApp));
            if ($this->isValidName($answer)) {
                return $answer;
            }
            $this->io->write('<error>Invalid app name, it should be matching \w+</error>');
        }
    }

    private function askServerName(): string
    {
        $defaultServer = explode('\\', $this->getDefaultNamespace())[1];
        $query = "<question>Which Tars server name to use <comment>($defaultServer)</comment></question>: ";
        while (true) {
            $answer = trim($this->io->ask($query, $defaultServer));
            if ($this->isValidName($answer)) {
                return $answer;
            }
            $this->io->write('<error>Invalid server name, it should be matching \w+</error>');
        }
    }

    private function askPort(): int
    {
        $defaultPort = $this->isHttpServer() ? '8000' : '7000';
        $query = "<question>Which port to listen <comment>($defaultPort)</comment></question>: ";
        while (true) {
            $answer = (int) trim($this->io->ask($query, $defaultPort));
            if ($answer > 0) {
                return $answer;
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
        $dir_it = new \RecursiveDirectoryIterator($this->projectRoot."/skeleton/templates");
        foreach (new \RecursiveIteratorIterator($dir_it) as $file => $fileinfo) {
            if (!is_file($file)) {
                continue;
            }
            $content = file_get_contents($file);
            $serverType = in_array($this->serverType, [
                self::HTTP_SERVER, self::JSONRPC_OVER_HTTP, self::TARS_HTTP_SERVER,
            ], true) ? 'http' : 'tcp';
            $replace = strtr($content, [
                '{PackageName}' => $this->packageName,
                '{namespace}' => $this->namespace,
                '{AppName}' => $this->appName,
                '{ServerName}' => $this->serverName ?? 'app',
                '{AdapterName}' => self::TARS_TCP_SERVER === $this->serverType ? 'HelloObj' : 'obj',
                '{protocol}' => self::TARS_TCP_SERVER === $this->serverType ? 'tars' : 'not_tars',
                '{port}' => $this->port,
                '{ServerType}' => $serverType,
                '{JsonRpcListener}' => $serverType === 'http' ? 'jsonRpcHttpRequestListener' : 'jsonRpcTcpReceiveEventListener'
            ]);
            file_put_contents($file, $replace);
        }
    }

    private function copyFile(string $from, string $to = null): void
    {
        if (!isset($to)) {
            $to = $from;
            $parts = explode('.', basename($from));
            if (count($parts) === 3 && in_array($parts[1], ['http', 'jsonrpc', 'tars', 'tars-http'], true)) {
                $to = dirname($from) . '/' . ($parts[0] . '.' . $parts[2]);
            }
        }
        $this->fileSystem->copy(__DIR__.'/templates/' . $from, $to, true);
    }

    private function setupServer(): void
    {
        $this->composerDefinition['scripts']['serve'] = '@php src/index.php';
        $this->copyFile("README.md");
        $this->copyFile("Dockerfile");
        $this->copyFile('.dockerignore');
        $this->copyFile('console');
        $this->copyFile('env.example');
        $this->copyFile('helm-values.yaml');
        $this->copyFile('src/index.php');
        $this->copyFile('src/config.php');
        if ($this->isTarsServer()) {
            $this->setupTarsServer();
        }
        if ($this->isHttpServer()) {
            $this->setupHttpServer();
        }
        if ($this->isJsonRpcServer()) {
            $this->setupJsonRpcServer();
        }
        chmod('resources/serve.sh', 0755);
        chmod('console', 0755);
        $this->fileSystem->copy('env.example', '.env.local');
    }

    /**
     * @return void
     */
    private function setupTarsServer(): void
    {
        if (self::TARS_HTTP_SERVER === $this->serverType) {
            $this->copyFile('helm-values.tars-http.yaml');
        } else {
            $this->copyFile('helm-values.tars.yaml');
            $this->copyFile( 'tars/servant/hello.tars');
            $this->copyFile('src/application/HelloServantImpl.php');
            $this->copyFile('src/servant/HelloServant.php');
        }
        $this->copyFile('src/index.tars.php');
        $this->copyFile('config.conf.example');
        $this->copyFile('console.tars', 'console');
        $this->fileSystem->copy('config.conf.example', 'config.conf');

        $this->composerDefinition['extra']['tars'] = [
            'manifest' => ['console'],
            'server_name' => $this->serverName,
        ];
        $this->composerDefinition['extra']['kuiper']['configuration'][] = 'kuiper\\tars\\config\\TarsServerConfiguration';
        $this->composerDefinition['scripts']['serve'] = '@php src/index.php --config config.conf';
        $this->composerDefinition['scripts']['package'] = 'kuiper\\tars\\server\\PackageBuilder::run';
        $this->composerDefinition['scripts']['gen'] = './vendor/bin/tars-gen && ./vendor/bin/php-cs-fixer fix src';
    }

    private function setupHttpServer(): void
    {
        if (self::HTTP_SERVER === $this->serverType) {
            $this->copyFile('src/config.http.php');
            $this->copyFile('env.http.example');
        }
        $this->copyFile('resources/views/index.html');
        $this->copyFile('src/application/controller/IndexController.php');
        $this->composerDefinition['extra']['kuiper']['configuration'][] = 'kuiper\\web\\WebConfiguration';
    }

    private function setupJsonRpcServer(): void
    {
        $this->copyFile("env.jsonrpc.example");
        $this->copyFile("tars/servant/hello.tars");
        $this->copyFile("tars/config.json");
        $this->copyFile('src/config.jsonrpc.php');
        $this->copyFile('src/servant/HelloServant.jsonrpc.php');
        $this->copyFile('src/application/HelloServantImpl.php');
        $this->composerDefinition['extra']['kuiper']['configuration'][] = 'kuiper\\jsonrpc\\config\\JsonRpcServerConfiguration';
        $this->composerDefinition['scripts']['gen'] = './vendor/bin/tars-gen && ./vendor/bin/php-cs-fixer fix src';
    }

    private function setPackageStabilityFlag(string $packageName, string $packageVersion): void
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

    private function getDefaultNamespace(): string
    {
        return str_replace('/', '\\', preg_replace('#[^/\w]#', '', $this->packageName));
    }

}
