<?php namespace EvolutionCMS\Installer\Presets;

use EvolutionCMS\Installer\Utilities\Console;
use EvolutionCMS\Installer\Utilities\VersionResolver;
use EvolutionCMS\Installer\Validators\PhpValidator;
use EvolutionCMS\Installer\Concerns\ConfiguresDatabase;
use EvolutionCMS\Installer\Process\CreatesDatabaseConfig;
use EvolutionCMS\Installer\Process\DetectsInstallType;
use Symfony\Component\Process\Process;
use Symfony\Component\Filesystem\Filesystem;

class EvolutionPreset extends Preset
{
    use ConfiguresDatabase;

    /**
     * Install the preset.
     *
     * @param string $name
     * @param array $options
     * @return void
     */
    public function install(string $name, array $options): void
    {
        Console::info("Installing Evolution CMS preset...");

        // Step 0: Validate PHP version
        $phpValidator = new PhpValidator();
        if (!$phpValidator->validate()) {
            throw new \RuntimeException("PHP version requirement not met.");
        }

        // Step 1: Download/Clone Evolution CMS (with version resolution)
        $this->downloadEvolutionCMS($name, $options);

        // Step 2: Setup database
        $this->setupDatabase($name, $options);

        // Step 3: Configure project
        $this->configureProject($name, $options);

        // Step 4: Install dependencies
        $this->installDependencies($name);

        // Step 5: Initialize Git repository (if requested)
        if (!empty($options['git'])) {
            $this->initializeGitRepository($name);
        }

        Console::success("Evolution CMS preset installed successfully!");
    }

    /**
     * Get the preset name.
     *
     * @return string
     */
    public function name(): string
    {
        return 'evolution';
    }

    /**
     * Get the preset description.
     *
     * @return string
     */
    public function description(): string
    {
        return 'Standard Evolution CMS installation';
    }

    /**
     * Download Evolution CMS.
     *
     * @param string $name
     * @param array $options
     * @return void
     */
    protected function downloadEvolutionCMS(string $name, array $options = []): void
    {
        Console::info("Determining compatible Evolution CMS version...");
        
        $versionResolver = new VersionResolver();
        $phpVersion = PHP_VERSION;
        
        // Get latest compatible version for current PHP version
        $version = $versionResolver->getLatestCompatibleVersion($phpVersion);
        
        if (!$version) {
            throw new \RuntimeException(
                "Could not find a compatible Evolution CMS version for PHP {$phpVersion}. " .
                "Evolution CMS requires PHP 8.3 or higher."
            );
        }

        Console::info("Downloading Evolution CMS {$version}...");
        
        $downloadUrl = $versionResolver->getDownloadUrl($version);
        
        // TODO: Implement actual download and extraction
        // For now, show instructions
        Console::warning("Download functionality not yet fully implemented.");
        Console::info("Please download manually from: {$downloadUrl}");
        
        // Show appropriate git command based on whether installing in current dir
        $isCurrentDir = !empty($options['install_in_current_dir']);
        if ($isCurrentDir) {
            Console::info("Or use git in current directory: git clone --depth 1 --branch {$version} https://github.com/evolution-cms/evolution.git .");
        } else {
            Console::info("Or use git: git clone --depth 1 --branch {$version} https://github.com/evolution-cms/evolution.git {$name}");
        }
    }

    /**
     * Setup database.
     *
     * @param string $name
     * @param array $options
     * @return void
     */
    protected function setupDatabase(string $name, array $options): void
    {
        $dbConfig = $options['database'];

        // Test connection first (without database)
        $dbConfigWithoutDb = $dbConfig;
        unset($dbConfigWithoutDb['name']);
        
        if (!$this->testConnection($dbConfigWithoutDb)) {
            throw new \RuntimeException("Cannot connect to database server. Please check your credentials.");
        }

        // Create database if needed
        if (empty($dbConfig['name'])) {
            throw new \RuntimeException("Database name is required.");
        }

        // Resolve collation before creating database
        $dbh = $this->createConnection($dbConfigWithoutDb);
        $recommendedCollation = $this->getRecommendedCollation($dbConfig['type']);
        
        // Create database first (with recommended collation)
        $this->createDatabase($dbConfig, $recommendedCollation);

        // Now connect to the database and resolve actual collation
        $dbh = $this->createConnection($dbConfig);
        $collation = $this->resolveCollation($dbh, $recommendedCollation);

        Console::info("Using collation: {$collation}");

        // Store resolved collation and charset in options for later use
        $options['database']['collation'] = $collation;
        $options['database']['charset'] = $this->getCharsetFromCollation($collation);
        $options['database']['port'] = $options['database']['port'] ?? $this->getDefaultPort($dbConfig['type']);
        $options['database']['prefix'] = $options['database']['prefix'] ?? 'evo_';

        // Detect install type
        $detectInstallType = new DetectsInstallType();
        $installType = $detectInstallType($dbh, $options['database']['prefix'], $dbConfig['type']);
        $options['install_type'] = $installType;
    }

    /**
     * Configure project.
     *
     * @param string $name
     * @param array $options
     * @return void
     */
    protected function configureProject(string $name, array $options): void
    {
        Console::info("Configuring project...");
        
        // If installing in current directory, use it directly
        $projectPath = !empty($options['install_in_current_dir']) ? $name : (getcwd() . '/' . $name);
        
        // Create database configuration file
        $createDbConfig = new CreatesDatabaseConfig();
        if (!$createDbConfig($projectPath, $options)) {
            throw new \RuntimeException("Failed to create database configuration.");
        }

        // TODO: Create admin user, run migrations, seeders, etc.
        // This will be implemented next, integrating with existing install logic
        Console::warning("Admin user creation and migrations not yet implemented.");
    }

    /**
     * Get default port for database type.
     *
     * @param string $type
     * @return int
     */
    protected function getDefaultPort(string $type): int
    {
        return match($type) {
            'pgsql' => 5432,
            'mysql' => 3306,
            default => 3306,
        };
    }

    /**
     * Install dependencies.
     *
     * @param string $name
     * @return void
     */
    protected function installDependencies(string $name): void
    {
        Console::info("Installing dependencies...");

        $projectPath = $name;
        $composerJson = $projectPath . '/composer.json';

        if (!file_exists($composerJson)) {
            Console::warning("composer.json not found. Skipping dependency installation.");
            return;
        }

        $process = new Process(['composer', 'install', '--no-interaction'], $projectPath);
        $process->setTimeout(300);
        
        $process->run(function ($type, $line) {
            echo $line;
        });

        if ($process->isSuccessful()) {
            Console::success("Dependencies installed successfully!");
        } else {
            Console::error("Failed to install dependencies. Please run 'composer install' manually.");
        }
    }

    /**
     * Initialize Git repository.
     *
     * @param string $name
     * @return void
     */
    protected function initializeGitRepository(string $name): void
    {
        // Use the name directly (it's already the full path if installing in current dir)
        $projectPath = $name;

        if (!is_dir($projectPath)) {
            Console::warning("Project directory not found. Skipping Git initialization.");
            return;
        }

        // Check if git is available
        $checkGit = new Process(['git', '--version'], $projectPath);
        $checkGit->run();
        
        if (!$checkGit->isSuccessful()) {
            Console::warning("Git is not installed or not available. Skipping Git initialization.");
            return;
        }

        // Check if already a git repository
        if (is_dir($projectPath . '/.git')) {
            Console::info("Git repository already initialized.");
            return;
        }

        Console::info("Initializing Git repository...");

        try {
            // Initialize git repository
            $init = new Process(['git', 'init'], $projectPath);
            $init->run();
            
            if (!$init->isSuccessful()) {
                throw new \RuntimeException("Failed to initialize Git repository.");
            }

            // Create initial .gitignore if it doesn't exist
            $gitignorePath = $projectPath . '/.gitignore';
            if (!file_exists($gitignorePath)) {
                $this->createGitignore($gitignorePath);
            }

            // Make initial commit
            $this->makeInitialCommit($projectPath);

            Console::success("Git repository initialized successfully!");
        } catch (\Exception $e) {
            Console::warning("Failed to initialize Git repository: " . $e->getMessage());
        }
    }

    /**
     * Create .gitignore file with common Evolution CMS ignores.
     *
     * @param string $gitignorePath
     * @return void
     */
    protected function createGitignore(string $gitignorePath): void
    {
        $gitignore = <<<'GITIGNORE'
# Evolution CMS
/assets/cache/*
/assets/export/*
/assets/files/*
/assets/images/*
!assets/cache/.htaccess
!assets/files/.htaccess
!assets/images/.htaccess
!assets/cache/index.html
!assets/files/index.html
!assets/images/index.html

# Core
/core/storage/logs/*
/core/storage/cache/*
/core/storage/sessions/*
/core/storage/*.php
!core/storage/.htaccess
!core/storage/index.html

# Composer
/vendor/
composer.lock

# Configuration (uncomment if you want to ignore config files)
# /core/config/database/connections/default.php
# /core/custom/.env

# IDE
.idea/
.vscode/
*.swp
*.swo
*~
.DS_Store

# Logs
*.log

# Temporary files
*.tmp
*.temp
GITIGNORE;

        file_put_contents($gitignorePath, $gitignore);
    }

    /**
     * Make initial Git commit.
     *
     * @param string $projectPath
     * @return void
     */
    protected function makeInitialCommit(string $projectPath): void
    {
        // Configure git user if not already configured
        $userName = $this->getGitConfig('user.name', $projectPath);
        $userEmail = $this->getGitConfig('user.email', $projectPath);

        if (!$userName) {
            $this->setGitConfig('user.name', 'Evolution CMF Installer', $projectPath);
        }
        if (!$userEmail) {
            $this->setGitConfig('user.email', 'installer@evolution-cms.com', $projectPath);
        }

        // Add all files
        $add = new Process(['git', 'add', '.'], $projectPath);
        $add->run();

        if (!$add->isSuccessful()) {
            return; // Skip commit if add failed
        }

        // Make initial commit
        $commit = new Process([
            'git', 
            'commit', 
            '-m', 
            'Initial commit: Evolution CMS installation'
        ], $projectPath);
        $commit->run();

        if ($commit->isSuccessful()) {
            Console::info("Initial commit created.");
        }
    }

    /**
     * Get Git configuration value.
     *
     * @param string $key
     * @param string $projectPath
     * @return string|null
     */
    protected function getGitConfig(string $key, string $projectPath): ?string
    {
        $process = new Process(['git', 'config', '--get', $key], $projectPath);
        $process->run();
        
        if ($process->isSuccessful()) {
            return trim($process->getOutput());
        }

        return null;
    }

    /**
     * Set Git configuration value.
     *
     * @param string $key
     * @param string $value
     * @param string $projectPath
     * @return void
     */
    protected function setGitConfig(string $key, string $value, string $projectPath): void
    {
        $process = new Process(['git', 'config', $key, $value], $projectPath);
        $process->run();
    }
}

