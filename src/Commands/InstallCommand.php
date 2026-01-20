<?php namespace EvolutionCMS\Installer\Commands;

use AllowDynamicProperties;
use EvolutionCMS\Installer\Concerns\ConfiguresDatabase;
use EvolutionCMS\Installer\Presets\Preset;
use EvolutionCMS\Installer\Process\CreatesDatabaseConfig;
use EvolutionCMS\Installer\Utilities\Console;
use EvolutionCMS\Installer\Utilities\SystemInfo;
use EvolutionCMS\Installer\Utilities\TuiRenderer;
use EvolutionCMS\Installer\Utilities\VersionResolver;
use EvolutionCMS\Installer\Validators\PhpValidator;
use GuzzleHttp\Client;
use PDOException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AllowDynamicProperties]
class InstallCommand extends Command
{
    use ConfiguresDatabase;

    protected ?OutputInterface $logSection = null;
    protected ?TuiRenderer $tui = null;
    protected ?string $lastDatabaseConnectionError = null;
    protected array $composerCommandCache = [];
    protected array $composerVersionCache = [];
    protected array $steps = [
        'php' => ['label' => 'Step 1: Validate PHP version', 'completed' => false],
        'database' => ['label' => 'Step 2: Check database connection', 'completed' => false],
        'download' => ['label' => 'Step 3: Download Evolution CMS', 'completed' => false],
        'install' => ['label' => 'Step 4: Install Evolution CMS', 'completed' => false],
        'presets' => ['label' => 'Step 5: Install presets', 'completed' => false],
        'dependencies' => ['label' => 'Step 6: Install dependencies', 'completed' => false],
        'finalize' => ['label' => 'Step 7: Finalize installation', 'completed' => false],
    ];

    /**
     * Configure the command options.
     */
    protected function configure(): void
    {
        $this
            ->setName('install')
            ->setDescription('Install Evolution CMS')
            ->addArgument('name', InputArgument::OPTIONAL, 'The name of the application (use "." to install in current directory)', '.')
            ->addOption('preset', null, InputOption::VALUE_OPTIONAL, 'The preset to use')
            ->addOption('db-type', null, InputOption::VALUE_OPTIONAL, 'The database type (mysql, pgsql, sqlite, sqlsrv)')
            ->addOption('db-host', null, InputOption::VALUE_OPTIONAL, 'The database host (localhost)')
            ->addOption('db-port', null, InputOption::VALUE_OPTIONAL, 'The database port')
            ->addOption('db-name', null, InputOption::VALUE_OPTIONAL, 'The database name')
            ->addOption('db-user', null, InputOption::VALUE_OPTIONAL, 'The database user')
            ->addOption('db-password', null, InputOption::VALUE_OPTIONAL, 'The database password')
            ->addOption('db-prefix', null, InputOption::VALUE_OPTIONAL, 'The database table prefix', 'evo_')
            ->addOption('admin-username', null, InputOption::VALUE_OPTIONAL, 'The admin username')
            ->addOption('admin-email', null, InputOption::VALUE_OPTIONAL, 'The admin email')
            ->addOption('admin-password', null, InputOption::VALUE_OPTIONAL, 'The admin password')
            ->addOption('admin-directory', null, InputOption::VALUE_OPTIONAL, 'The admin directory')
            ->addOption('github-pat', null, InputOption::VALUE_OPTIONAL, 'GitHub PAT token for API requests')
            ->addOption('github_pat', null, InputOption::VALUE_OPTIONAL, 'GitHub PAT token for API requests')
            ->addOption('branch', null, InputOption::VALUE_OPTIONAL, 'Install from specific Git branch (e.g., develop, nightly, main) instead of latest release')
            ->addOption('language', null, InputOption::VALUE_OPTIONAL, 'The installation language')
            ->addOption('composer-clear-cache', null, InputOption::VALUE_NONE, 'Clear Composer cache before install')
            ->addOption('composer-update', null, InputOption::VALUE_NONE, 'Use composer update instead of install during setup')
            ->addOption('git', null, InputOption::VALUE_NONE, 'Initialize a Git repository')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force install even if directory exists');
    }

    /**
     * Execute the command.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Starting system installation...');

        $this->tui = new TuiRenderer($output);
        $this->tui->setSystemStatus($this->checkSystemStatus());

        $name = $input->getArgument('name') ?? '.';

        // Normalize "." to current directory
        $installInCurrentDir = ($name === '.');
        if ($installInCurrentDir) {
            $name = getcwd();
        }

        // Prevent re-installing over an existing instance unless --force / -f is provided.
        if ($installInCurrentDir && !$input->getOption('force') && $this->isExistingInstall($name)) {
            $this->tui->addLog('Existing Evolution CMS installation detected in current directory. Re-run with -f/--force to install anyway.', 'error');
            return Command::FAILURE;
        }

        if (!$input->getOption('force') && !$installInCurrentDir) {
            $this->verifyApplicationDoesntExist($name);
        }

        // Pre-validate PHP version (after TUI is rendered)
        $phpValidator = new PhpValidator();
        $phpVersion = SystemInfo::getPhpVersion();
        if (!$phpValidator->isSupported()) {
            Console::error("PHP version {$phpVersion} is not supported.");
            return Command::FAILURE;
        }
        $phpOk = version_compare($phpVersion, '8.3.0', '>=');
        $this->steps['php']['completed'] = $phpOk;
        $this->tui->setQuestTrack($this->steps);
        $this->tui->addLog("PHP version {$phpVersion} is supported.", 'success');

        $options = $this->gatherInputs($input, $output);
        $options['git'] = $input->getOption('git');
        $options['install_in_current_dir'] = $installInCurrentDir;
        $options['composer_clear_cache'] = (bool) $input->getOption('composer-clear-cache');
        $options['composer_update'] = (bool) $input->getOption('composer-update');

        // Step 3: Download Evolution CMS
        $branch = $input->getOption('branch');
        $this->downloadEvolutionCMS($name, $options, $branch);

        // Step 4: Install Evolution CMS (clean installation)
        $this->installEvolutionCMS($name, $options);

        // Step 5: Install presets
        $this->installPreset($input->getOption('preset'));

        // Step 6: Install dependencies
        $this->installDependencies($name, $options);

        // Step 7: Finalize installation
        $this->finalizeInstallation($name, $options);

        $this->tui->addLog("<fg=green>Evolution CMS application ready! Build something amazing.</>", 'success');

        // Show admin panel access information
        $adminDirectory = $options['admin']['directory'] ?? 'manager';
        $adminUsername = $options['admin']['username'] ?? 'admin';
        $adminPassword = $options['admin']['password'] ?? '';
        $baseUrl = $this->detectBaseUrl($name, $installInCurrentDir);
        $adminUrl = "{$baseUrl}/{$adminDirectory}";

        $this->tui->addLog("Admin panel: <fg=cyan>{$adminUrl}</>");
        $this->tui->addLog("Username: <fg=yellow>{$adminUsername}</>");
        if (!empty($adminPassword)) {
            $this->tui->addLog("Password: <fg=yellow>{$adminPassword}</>");
        }

        return Command::SUCCESS;
    }

    protected function isExistingInstall(string $path): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if ($path === '') {
            return false;
        }

        if (is_file($path . '/core/.install')) {
            return true;
        }

        // Heuristic: typical Evolution CMS layout.
        return is_dir($path . '/core') && is_dir($path . '/manager') && is_file($path . '/index.php');
    }

    /**
     * Verify that the application does not already exist.
     */
    protected function verifyApplicationDoesntExist(string $name): void
    {
        if (is_dir($name)) {
            Console::error("Directory [{$name}] already exists!");
            exit(1);
        }
    }

    /**
     * Check system status information.
     */
    protected function checkSystemStatus(): array
    {
        $json = SystemInfo::systemStatusJson();
        $out = [];
        foreach ($json['items'] as $it) {
            $level = $it['level'] ?? 'ok';
            $out[] = [
                'label' => $it['label'] ?? '',
                'status' => $level !== 'error',
                'warning' => $level === 'warn',
            ];
        }
        return $out;
    }

    /**
     * Gather inputs from user.
     */
    protected function gatherInputs(InputInterface $input, OutputInterface $output): array
    {
        $helper = $this->getHelper('question');

        $inputs = [
            'database' => [],
            'admin' => [],
        ];

        // Database configuration with connection retry loop
        $databaseConnected = false;
        $firstAttempt = true;
        while (!$databaseConnected) {
            if ($firstAttempt && $this->hasAllDatabaseOptions($input)) {
                // First attempt with command-line options
                $inputs['database']['type'] = $input->getOption('db-type');
                $inputs['database']['host'] = $input->getOption('db-host');
                if ($input->getOption('db-port')) {
                    $inputs['database']['port'] = $input->getOption('db-port');
                }
                $inputs['database']['name'] = $input->getOption('db-name');
                $inputs['database']['user'] = $input->getOption('db-user');
                $inputs['database']['password'] = $input->getOption('db-password');
            } else {
                // Ask for database credentials interactively (ignore command-line options on retry)
                $inputs['database'] = $this->gatherDatabaseInputs($helper, $input, $output, $firstAttempt);
            }

            // Test database connection
            if ($this->testDatabaseConnection($inputs['database'])) {
                $databaseConnected = true;
            } else {
                if (!$input->isInteractive()) {
                    $msg = is_string($this->lastDatabaseConnectionError) ? trim($this->lastDatabaseConnectionError) : '';
                    if ($msg === '') {
                        $msg = 'Unknown database connection error.';
                    }
                    throw new \RuntimeException('Database connection failed: ' . $msg);
                }
                // Connection failed, ask user what to do
                if (!$this->askRetryDatabaseConnection()) {
                    throw new \RuntimeException('Installation cancelled by user.');
                }
                $firstAttempt = false;
            }
        }

        // Admin configuration (only after successful DB connection)
        $inputs['admin']['username'] = ($input->getOption('admin-username') !== null) ? $input->getOption('admin-username') : $this->askAdminUsername();
        $inputs['admin']['email'] = ($input->getOption('admin-email') !== null) ? $input->getOption('admin-email') : $this->askAdminEmail();
        $inputs['admin']['password'] = ($input->getOption('admin-password') !== null) ? $input->getOption('admin-password') : $this->askAdminPassword();
        $adminDirectoryInput = ($input->getOption('admin-directory') !== null) ? $input->getOption('admin-directory') : $this->askAdminDirectory();
        $inputs['admin']['directory'] = $this->sanitizeAdminDirectory($adminDirectoryInput);
        $inputs['language'] = ($input->getOption('language') !== null) ? $input->getOption('language') : $this->askLanguage();
        $githubPat = $input->getOption('github-pat');
        if ($githubPat === null) {
            $githubPat = $input->getOption('github_pat');
        }
        $inputs['github_pat'] = $githubPat !== null ? $githubPat : null;

        // Mark Step 2 (database connection) as completed
        $this->steps['database']['completed'] = true;
        $this->tui->setQuestTrack($this->steps);

        return $inputs;
    }

    /**
     * Check if all database options are provided via command line.
     */
    protected function hasAllDatabaseOptions(InputInterface $input): bool
    {
        return $input->getOption('db-type') !== null
            && $input->getOption('db-host') !== null
            && $input->getOption('db-name') !== null
            && $input->getOption('db-user') !== null
            && $input->getOption('db-password') !== null;
    }

    /**
     * Gather database configuration inputs from user.
     */
    protected function gatherDatabaseInputs($helper, InputInterface $input, OutputInterface $output, bool $useOptions = true): array
    {
        $database = [];

        // Use command-line options if available, otherwise ask interactively
        $database['type'] = ($useOptions && $input->getOption('db-type')) ? $input->getOption('db-type') : $this->askDatabaseType();

        // SQLite doesn't need host, port, user, password
        if ($database['type'] === 'sqlite') {
            $database['name'] = ($useOptions && $input->getOption('db-name'))
                ? $input->getOption('db-name')
                : $this->askDatabaseName('sqlite');
            // SQLite doesn't need these fields, but set defaults for compatibility
            $database['host'] = '';
            $database['user'] = '';
            $database['password'] = '';
        } else {
            $database['host'] = ($useOptions && $input->getOption('db-host'))
                ? $input->getOption('db-host')
                : $this->askDatabaseHost();

            if ($input->getOption('db-port')) {
                $database['port'] = $input->getOption('db-port');
            }

            $database['name'] = ($useOptions && $input->getOption('db-name'))
                ? $input->getOption('db-name')
                : $this->askDatabaseName();
            $database['user'] = ($useOptions && $input->getOption('db-user'))
                ? $input->getOption('db-user')
                : $this->askDatabaseUser();
            $database['password'] = ($useOptions && $input->getOption('db-password'))
                ? $input->getOption('db-password')
                : $this->askDatabasePassword();
        }

        // Set prefix (from option or default)
        $database['prefix'] = ($useOptions && $input->getOption('db-prefix') !== null)
            ? $input->getOption('db-prefix')
            : 'evo_';

        return $database;
    }

    /**
     * Ask for database type with interactive radio button selection.
     */
    protected function askDatabaseType(): string
    {
        $driverNames = [
            'mysql' => 'MySQL or MariaDB',
            'pgsql' => 'PostgreSQL',
            'sqlite' => 'SQLite',
            'sqlsrv' => 'SQL Server'
        ];

        $options = array_keys($driverNames);
        $labels = array_values($driverNames);
        $active = 0;

        // Display question in cyan without icon
        $this->tui->addLog('Which database driver do you want to use?', 'ask');

        // Display initial radio options (force render to avoid throttle)
        $radioText = $this->tui->renderRadio($options, $active, $labels);
        $this->tui->addLog($radioText);

        // raw input
        $this->setSttyMode('-icanon -echo');
        try {
            while (true) {
                $key = fread(STDIN, 3);
                if ($key === '' || $key === false) {
                    usleep(20000);
                    continue;
                }

                switch ($key) {
                    case "\033[D": // ←
                    case "\033[A": // ↑
                        $active = max(0, $active - 1);
                        break;

                    case "\033[C": // →
                    case "\033[B": // ↓
                        $active = min(count($options) - 1, $active + 1);
                        break;

                    case "\n": // Enter
                        $this->tui->replaceLastLogs('<fg=green>✔</> Selected database driver: ' . $labels[$active] . '.', 2);
                        return $options[$active];
                }

                $this->tui->replaceLastLog($this->tui->renderRadio($options, $active, $labels));
            }
        } finally {
            $this->setSttyMode('sane');
        }
    }

    protected function askDatabaseHost(): string
    {
        $answer = $this->tui->ask(
            'Where is your database server located?',
            'localhost'
        );

        $this->tui->replaceLastLogs('<fg=green>✔</> Selected database host: ' . $answer . '.', 2);

        return $answer ?: 'localhost';
    }

    /**
     * Ask for database name.
     *
     * @param string|null $type Database type (for SQLite, asks for file path)
     */
    protected function askDatabaseName(?string $type = null): string
    {
        if ($type === 'sqlite') {
            $answer = $this->tui->ask(
                'What is the path to your SQLite database file?',
                'database.sqlite'
            );

            $this->tui->replaceLastLogs('<fg=green>✔</> Selected database path: ' . $answer . '.', 2);

            return $answer ?: 'database.sqlite';
        }

        $answer = $this->tui->ask(
            'What is your database name?',
            'evo_db'
        );

        $this->tui->replaceLastLogs('<fg=green>✔</> Selected database name: ' . $answer . '.', 2);

        return $answer ?: 'evo_db';
    }

    /**
     * Ask for database user.
     */
    protected function askDatabaseUser(): string
    {
        $answer = $this->tui->ask(
            'What is your database username?',
            'root'
        );

        $this->tui->replaceLastLogs('<fg=green>✔</> Selected database user: ' . $answer . '.', 2);

        return $answer ?: 'root';
    }

    /**
     * Ask for database password.
     */
    protected function askDatabasePassword(): string
    {
        $answer = $this->tui->ask(
            'What is your database password?',
            '',
            true // hidden input
        );

        $this->tui->replaceLastLogs('<fg=green>✔</> Selected database password: ' . (mb_strlen($answer) > 0 ? '••••••••' : '(empty)') . '.', 2);

        return $answer;
    }

    /**
     * Test database connection and display result in TUI.
     *
     * @return bool True if connection successful, false otherwise
     */
    protected function testDatabaseConnection(array $config): bool
    {
        $this->lastDatabaseConnectionError = null;
        $this->tui->addLog('Testing database connection...');

        try {
            $type = $config['type'] ?? 'mysql';

            // Check if PDO driver is available
            $availableDrivers = \PDO::getAvailableDrivers();
            $driverMap = [
                'mysql' => 'mysql',
                'pgsql' => 'pgsql',
                'sqlite' => 'sqlite',
                'sqlsrv' => 'sqlsrv'
            ];

            $requiredDriver = $driverMap[$type] ?? null;
            if ($requiredDriver && !in_array($requiredDriver, $availableDrivers)) {
                $driverName = match($type) {
                    'mysql' => 'MySQL/MariaDB',
                    'pgsql' => 'PostgreSQL',
                    'sqlite' => 'SQLite',
                    'sqlsrv' => 'SQL Server',
                    default => $type
                };
                $this->tui->replaceLastLogs(
                    '<fg=red>✗</> Database connection failed: PDO driver for ' . $driverName . ' is not available. ' .
                    'Please install PHP extension: ' . ($type === 'sqlite' ? 'pdo_sqlite' : 'pdo_' . $type) . '.',
                    2
                );
                $this->lastDatabaseConnectionError = 'PDO driver for ' . $driverName . ' is not available.';
                return false;
            }

            if ($type === 'sqlite') {
                // SQLite: Connect directly to the database file
                if (empty($config['name'])) {
                    $this->tui->replaceLastLogs('<fg=red>✗</> Database connection failed: SQLite database path is required.', 2);
                    $this->lastDatabaseConnectionError = 'SQLite database path is required.';
                    return false;
                }
                $this->createConnection($config);
            } elseif ($type === 'pgsql') {
                // PostgreSQL requires a database name; otherwise it defaults to using the username.
                // To support environments where `postgres` is missing/restricted, accept either:
                // - successful connect to a maintenance DB (postgres/template1), or
                // - successful connect to the target DB (if only that one is accessible).
                $maintenanceOk = false;
                $maintenanceErr = null;

                $configForOps = $config;
                unset($configForOps['name']);
                $dbh = $this->createPostgresMaintenanceConnection($configForOps, $maintenanceErr);
                $maintenanceOk = $dbh !== null;

                $targetOk = false;
                $targetErr = null;
                if (!empty($config['name'])) {
                    try {
                        $this->createConnection($config, true);
                        $targetOk = true;
                    } catch (PDOException $e) {
                        $targetErr = $e;
                    }
                }

                if (!$maintenanceOk && !$targetOk) {
                    throw $targetErr ?? $maintenanceErr ?? new PDOException('PostgreSQL connection failed.');
                }
            } else {
                // For server-based databases, try to connect without database name first
                $configWithoutDb = $config;
                unset($configWithoutDb['name']);
                $this->createConnection($configWithoutDb);

                // If database name is provided, try to connect to it
                if (!empty($config['name'])) {
                    $this->createConnection($config);
                }
            }

            // Display success message
            $this->tui->replaceLastLogs('<fg=green>✔</> Database connection successful!', 2);
            return true;
        } catch (PDOException $e) {
            $errorMessage = $e->getMessage();

            // Improve error message for missing driver
            if (str_contains($errorMessage, 'could not find driver') || str_contains($errorMessage, 'driver not found')) {
                $driverName = match($config['type'] ?? 'mysql') {
                    'mysql' => 'MySQL/MariaDB',
                    'pgsql' => 'PostgreSQL',
                    'sqlite' => 'SQLite',
                    'sqlsrv' => 'SQL Server',
                    default => $config['type'] ?? 'database'
                };
                $extensionName = match($config['type'] ?? 'mysql') {
                    'sqlite' => 'pdo_sqlite',
                    'sqlsrv' => 'pdo_sqlsrv',
                    default => 'pdo_' . ($config['type'] ?? 'mysql')
                };
                $errorMessage = "PDO driver for {$driverName} is not installed. Please install PHP extension: {$extensionName}";
            }

            $this->lastDatabaseConnectionError = $errorMessage;
            $this->tui->replaceLastLogs('<fg=red>✗</> Database connection failed: ' . $errorMessage, 2);
            return false;
        }
    }

    /**
     * Ask user if they want to retry database connection or exit.
     *
     * @return bool True if user wants to retry, false if they want to exit
     */
    protected function askRetryDatabaseConnection(): bool
    {
        $options = ['Exit installation', 'Try again'];
        $active = 0;
        $buffer = '';

        // Display question in cyan without icon
        $this->tui->addLog('Would you like to try again or exit installation?', 'ask');

        // Display initial radio options (force render to avoid throttle)
        $radioText = $this->tui->renderRadio($options, $active);
        $this->tui->addLog($radioText);

        // raw input
        $this->setSttyMode('-icanon -echo');
        try {
            while (true) {
                $chunk = fread(STDIN, 3);
                if ($chunk === '' || $chunk === false) {
                    usleep(20000);
                    continue;
                }

                $buffer .= $chunk;
                if (strlen($buffer) > 3) {
                    $buffer = substr($buffer, -3);
                }

                $prevActive = $active;
                switch ($buffer) {
                    case "\033[D": // ←
                    case "\033[A": // ↑
                        $active = max(0, $active - 1);
                        $buffer = '';
                        break;

                    case "\033[C": // →
                    case "\033[B": // ↓
                        $active = min(count($options) - 1, $active + 1);
                        $buffer = '';
                        break;

                    case "\n": // Enter
                    case "\r": // Enter (some terminals)
                        $active
                            ? $this->tui->replaceLastLogs('Reinput DB connections.', 2)
                            : $this->tui->replaceLastLogs('Exit ...', 3);
                        return $active;
                }

                if ($active !== $prevActive) {
                    $this->tui->replaceLastLog($this->tui->renderRadio($options, $active));
                }
            }
        } finally {
            $this->setSttyMode('sane');
        }

        return false;
    }

    /**
     * Ask for admin username.
     */
    protected function askAdminUsername(): string
    {
        $answer = $this->tui->ask('Enter your Admin username:', 'admin');

        $this->tui->replaceLastLogs('<fg=green>✔</> Your Admin username: ' . $answer . '.', 2);
        return $answer ?: 'admin';
    }

    /**
     * Ask for admin email.
     */
    protected function askAdminEmail(): string
    {
        while (true) {
            $answer = $this->tui->ask('Enter your Admin email:');

            if (empty($answer)) {
                $this->tui->replaceLastLogs('<fg=yellow>⚠</> Email address cannot be empty. Please try again.', 3);
                continue;
            }

            if (!filter_var($answer, FILTER_VALIDATE_EMAIL)) {
                $this->tui->replaceLastLogs('<fg=yellow>⚠</> Please enter a valid email address. Try again.', 3);
                continue;
            }

            // Replace question, answer, and any warning messages (up to 3 lines)
            $this->tui->replaceLastLogs('<fg=green>✔</> Your Admin email: ' . $answer . '.', 2);
            return $answer;
        }
    }

    /**
     * Ask for admin password.
     */
    protected function askAdminPassword(): string
    {
        while (true) {
            $answer = $this->tui->ask('Enter your Admin password:');

            if (empty($answer)) {
                $this->tui->replaceLastLogs('<fg=yellow>⚠</> Password cannot be empty. Please try again.', 3);
                continue;
            }

            if (strlen($answer) < 6) {
                $this->tui->replaceLastLogs('<fg=yellow>⚠</> Password must be at least 6 characters long. Try again.', 3);
                continue;
            }

            $this->tui->replaceLastLogs('<fg=green>✔</> Your Admin password: ' . $answer . '.', 2);
            return $answer;
        }
    }

    /**
     * Ask for admin directory.
     */
    protected function askAdminDirectory(): string
    {
        $answer = $this->tui->ask('Enter your Admin directory:', 'manager');

        $sanitized = $this->sanitizeAdminDirectory($answer);

        $this->tui->replaceLastLogs('<fg=green>✔</> Your Admin directory: ' . $sanitized . '.', 2);
        return $sanitized;
    }

    protected function sanitizeAdminDirectory(?string $value): string
    {
        $value = trim((string) $value);

        // Only alphanumeric, hyphen, underscore allowed.
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $value);
        if (!is_string($sanitized) || $sanitized === '') {
            return 'manager';
        }
        return $sanitized;
    }

    /**
     * Ask for installation language with interactive radio button selection.
     */
    protected function askLanguage(): string
    {
        $languageNames = [
            'en' => 'English',
            'uk' => 'Ukrainian',
            'az' => 'Azerbaijani',
            'be' => 'Belarusian',
            'bg' => 'Bulgarian',
            'cs' => 'Czech',
            'da' => 'Danish',
            'de' => 'German',
            'es' => 'Spanish',
            'fa' => 'Persian',
            'fi' => 'Finnish',
            'fr' => 'French',
            'he' => 'Hebrew',
            'it' => 'Italian',
            'ja' => 'Japanese',
            'nl' => 'Dutch',
            'nn' => 'Norwegian',
            'pl' => 'Polish',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
            'sv' => 'Swedish',
            'zh' => 'Chinese',
        ];

        $options = array_keys($languageNames);
        $labels = array_values($languageNames);

        // Find default 'en' index
        $defaultIndex = array_search('en', $options);
        $active = $defaultIndex !== false ? $defaultIndex : 0;

        // Display question
        $this->tui->addLog('Which language do you want to use for installation?', 'ask');

        // Display initial radio options (vertical list)
        $radioLines = $this->renderRadioVertical($options, $active, $labels);
        foreach ($radioLines as $line) {
            $this->tui->addLog($line);
        }

        // Store initial number of radio lines for updating
        $radioLinesCount = count($radioLines);

        // Raw input for arrow keys
        $this->setSttyMode('-icanon -echo');
        try {
            while (true) {
                $key = fread(STDIN, 3);
                if ($key === '' || $key === false) {
                    usleep(20000);
                    continue;
                }

                switch ($key) {
                    case "\033[A": // ↑
                        $active = max(0, $active - 1);
                        break;

                    case "\033[B": // ↓
                        $active = min(count($options) - 1, $active + 1);
                        break;

                    case "\n": // Enter
                        // Remove all radio button lines and show result
                        $this->tui->replaceLastLogs('<fg=green>✔</> Selected language: ' . $labels[$active] . '.', $radioLinesCount + 1);
                        return $options[$active];
                }

                // Update radio buttons display
                $radioLines = $this->renderRadioVertical($options, $active, $labels);
                // Replace all radio lines - remove old ones and add new ones
                $this->tui->replaceLastLogsMultiple($radioLines, $radioLinesCount);
            }
        } finally {
            $this->setSttyMode('sane');
        }
    }

    /**
     * Render radio buttons vertically (one per line).
     */
    protected function renderRadioVertical(array $options, int $active, array $labels): array
    {
        $lines = [];
        foreach ($options as $i => $value) {
            $label = $labels[$i] ?? $value;

            if ($i === $active) {
                $lines[] = '  <fg=green>●</> <fg=white;options=bold>' . $label . '</> <fg=gray>(' . $value . ')</>';
            } else {
                $lines[] = '  <fg=gray>○</> ' . $label . ' <fg=gray>(' . $value . ')</>';
            }
        }

        return $lines;
    }

    /**
     * Download Evolution CMS with compatible version check or from branch.
     */
    protected function downloadEvolutionCMS(string $name, array $options, ?string $branch = null): void
    {
        $this->tui->clearLogs();
        $versionResolver = new VersionResolver();
        $isCurrentDir = !empty($options['install_in_current_dir']);
        $targetPath = $isCurrentDir ? $name : (getcwd() . '/' . $name);

        $sourceLabel = null;
        $composerConstraint = null;
        $composerRepository = null;
        $preferSource = false;

        if ($branch !== null && trim($branch) !== '') {
            $branch = trim($branch);
            $this->tui->addLog("Preparing Evolution CMS from branch: {$branch}...");

            // Packagist for evolution-cms/evolution currently publishes only tagged releases (no dev branches).
            // For explicit branches, use the upstream Git repository through Composer.
            $composerRepository = [
                // Use explicit "git" to avoid GitHub API auth/rate-limit issues from the GitHub VCS driver.
                'type' => 'git',
                'url' => 'https://github.com/evolution-cms/evolution.git',
            ];

            $preferSource = true;

            // Accept branch aliases like `3.5.x-dev`, otherwise treat as a branch name (`dev-branchName`).
            if (str_starts_with($branch, 'dev-') || str_ends_with($branch, '-dev')) {
                $composerConstraint = $branch;
            } elseif (preg_match('/^\\d+\\.\\d+\\.x$/', $branch) === 1) {
                $composerConstraint = $branch . '-dev';
            } else {
                $composerConstraint = 'dev-' . $branch;
            }
            $sourceLabel = "branch {$branch}";
        } else {
            $this->tui->addLog('Finding compatible Evolution CMS version...');
            $phpVersion = PHP_VERSION;
            $version = $versionResolver->getLatestCompatibleVersion($phpVersion, true);

            if (!$version) {
                $this->tui->replaceLastLogs('<fg=red>✗</> Could not find a compatible Evolution CMS version for PHP ' . $phpVersion . '.', 2);
                throw new \RuntimeException("Could not find a compatible Evolution CMS version for PHP {$phpVersion}.");
            }

            $versionTag = ltrim($version, 'v');
            $this->tui->replaceLastLogs('<fg=green>✔</> Found compatible version: ' . $version . '.', 2);
            $this->tui->addLog("Downloading Evolution CMS {$versionTag} via Composer...");

            // Use the normalized version without `v` prefix (Packagist versions are `X.Y.Z`).
            $composerConstraint = $versionTag;
            $sourceLabel = "version {$versionTag}";
        }

        $tempDir = rtrim(sys_get_temp_dir(), "/\\") . '/evo-installer-' . uniqid('', true);

        try {
            $composerWorkDir = rtrim(sys_get_temp_dir(), "/\\");
            $composerCommand = $this->resolveComposerCommand($composerWorkDir);

            $args = [
                'create-project',
                $preferSource ? '--prefer-source' : '--prefer-dist',
                '--no-install',
                'evolution-cms/evolution',
                $tempDir,
            ];
            if (is_array($composerRepository)) {
                $args[] = '--stability=dev';
                $args[] = '--repository=' . json_encode($composerRepository, JSON_UNESCAPED_SLASHES);
                $args[] = '--remove-vcs';
            }
            if (is_string($composerConstraint) && trim($composerConstraint) !== '') {
                $args[] = trim($composerConstraint);
            }

            $process = $this->runComposer($composerCommand, $args, $composerWorkDir);
            if (!$process->isSuccessful()) {
                $out = $this->sanitizeComposerOutput($process->getOutput() . "\n" . $process->getErrorOutput());
                throw new \RuntimeException(trim($out) !== '' ? trim($out) : 'Composer create-project failed.');
            }

            $this->tui->replaceLastLogs('<fg=green>✔</> Download completed.');

            $this->tui->addLog('Extracting files...');
            $this->copyDirectoryWithProgress($tempDir, $targetPath);
            $this->removeDirectory($tempDir);

            $this->tui->addLog("Evolution CMS from {$sourceLabel} downloaded and extracted successfully!", 'success');

            // Mark Step 3 (Download Evolution CMS) as completed
            $this->steps['download']['completed'] = true;
            $this->tui->setQuestTrack($this->steps);
        } catch (\Exception $e) {
            // Clean up on error
            if (is_dir($tempDir)) {
                $this->removeDirectory($tempDir);
            }

            $this->tui->addLog('Failed to download Evolution CMS: ' . $e->getMessage(), 'error');
            throw $e;
        }
    }

    /**
     * Copy package files into the destination directory.
     */
    protected function copyDirectoryWithProgress(string $source, string $destination): void
    {
        $source = rtrim($source, "/\\");
        $destination = rtrim($destination, "/\\");

        if (!is_dir($source)) {
            throw new \RuntimeException("Temporary download directory not found: {$source}");
        }

        if (!is_dir($destination)) {
            if (!@mkdir($destination, 0755, true) && !is_dir($destination)) {
                throw new \RuntimeException("Failed to create destination directory: {$destination}");
            }
        }

        $totalFiles = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $rel = substr($item->getPathname(), strlen($source) + 1);
            if ($rel === false || $rel === '') {
                continue;
            }
            if ($this->shouldSkipCopiedPath($rel)) {
                continue;
            }
            if ($item->isDir()) {
                continue;
            }
            $totalFiles++;
        }

        $copiedFiles = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $srcPath = $item->getPathname();
            $rel = substr($srcPath, strlen($source) + 1);
            if ($rel === false || $rel === '') {
                continue;
            }
            if ($this->shouldSkipCopiedPath($rel)) {
                continue;
            }

            $dstPath = $destination . '/' . str_replace('\\', '/', $rel);

            if ($item->isDir()) {
                if (!is_dir($dstPath)) {
                    @mkdir($dstPath, 0755, true);
                }
                continue;
            }

            $dstDir = dirname($dstPath);
            if (!is_dir($dstDir) && !@mkdir($dstDir, 0755, true) && !is_dir($dstDir)) {
                throw new \RuntimeException("Failed to create directory: {$dstDir}");
            }

            if ($item->isLink()) {
                $target = @readlink($srcPath);
                if ($target === false) {
                    throw new \RuntimeException("Failed to read symlink: {$srcPath}");
                }
                if (is_link($dstPath) || file_exists($dstPath)) {
                    @unlink($dstPath);
                }
                if (!@symlink($target, $dstPath)) {
                    // Fallback: copy the resolved target content if symlinks are not permitted.
                    if (!@copy($srcPath, $dstPath)) {
                        throw new \RuntimeException("Failed to copy symlink target: {$srcPath}");
                    }
                }
            } else {
                if (!@copy($srcPath, $dstPath)) {
                    throw new \RuntimeException("Failed to copy file: {$rel}");
                }
                @chmod($dstPath, $item->getPerms() & 0777);
            }

            $copiedFiles++;
            if ($totalFiles > 0 && ($copiedFiles % 25 === 0 || $copiedFiles === $totalFiles)) {
                $this->tui->updateProgress('Extracting', $copiedFiles, $totalFiles, 'files');
            }
        }

        if ($totalFiles > 0 && $copiedFiles === $totalFiles) {
            $this->tui->updateProgress('Extracting', $totalFiles, $totalFiles, 'files');
        }

        $this->tui->replaceLastLogs('<fg=green>✔</> Extracted ' . $copiedFiles . ' files.');
    }

    protected function shouldSkipCopiedPath(string $relativePath): bool
    {
        $parts = preg_split('#[\\\\/]+#', $relativePath) ?: [];
        foreach ($parts as $part) {
            if ($part === '.git' || $part === '.hg' || $part === '.svn') {
                return true;
            }
        }
        return false;
    }

    /**
     * Install Evolution CMS (clean installation).
     *
     * @param string $name
     * @param array $options
     * @return void
     */
    protected function installEvolutionCMS(string $name, array $options): void
    {
        $this->tui->clearLogs();
        $isCurrentDir = !empty($options['install_in_current_dir']);
        $projectPath = $isCurrentDir ? $name : (getcwd() . '/' . $name);

        // Step 4.1: Setup database (create if needed, configure)
        $this->setupDatabase($projectPath, $options);

        // Persist DB variables for the core bootstrap (core/custom/.env).
        // Admin credentials must not be written to disk; they are passed to subprocesses via env.
        $this->writeCoreCustomEnv($projectPath, $options);

        // Step 4.2: Install dependencies via Composer
        $this->setupComposer($projectPath, $options);

        // Step 4.3: Run database migrations
        $this->runMigrations($projectPath, $options);

        // Step 4.4: Run database seeders (roles/permissions/templates)
        $this->runSeeders($projectPath, $options);

        // Apply selected language to manager settings (system_settings.manager_language).
        $this->setManagerLanguage($projectPath, $options);

        // Mark installation steps as completed
        $this->steps['install']['completed'] = true;
        $this->tui->setQuestTrack($this->steps);
    }

    /**
     * Setup database: create if needed and configure.
     *
     * @param string $projectPath
     * @param array $options
     * @return void
     */
    protected function setupDatabase(string $projectPath, array $options): void
    {
        $this->tui->addLog('Setting up database...');

        $dbConfig = $options['database'];

        // Ensure prefix is set (from option or default)
        if (empty($dbConfig['prefix'])) {
            $dbConfig['prefix'] = 'evo_';
            $options['database']['prefix'] = 'evo_';
        }

        // Create database if needed
        if (empty($dbConfig['name'])) {
            throw new \RuntimeException("Database name is required.");
        }

        // Get charset and recommended collation (like Laravel does - simple and straightforward)
        $charset = $this->getDefaultCharset($dbConfig['type']);
        $serverVersion = null;
        if ($dbConfig['type'] === 'mysql') {
            try {
                $dbConfigWithoutDb = $dbConfig;
                unset($dbConfigWithoutDb['name']);
                $dbh = $this->createConnection($dbConfigWithoutDb);
                $serverVersion = $dbh->getAttribute(\PDO::ATTR_SERVER_VERSION);
            } catch (\Throwable $e) {
                // Best-effort: fall back to safe defaults when server version is unavailable
            }
        }
        $recommendedCollation = $this->getRecommendedCollation($dbConfig['type'], $charset, $serverVersion);

        // Create database with recommended collation
        $dbConfigForOps = $this->getDatabaseConfigForOperations($projectPath, $dbConfig);
        if ($this->createDatabase($dbConfigForOps, $recommendedCollation)) {
            $this->tui->replaceLastLogs('<fg=green>✔</> Database created/verified successfully.', 2);
        }

        // Use recommended collation directly (like Laravel does)
        // Collation can be specified in migrations if needed
        $collation = $recommendedCollation;

        // Store collation and charset in options for later use
        $options['database']['collation'] = $collation;
        // Persist the charset chosen for the DB driver (PostgreSQL must not receive `utf8mb4`).
        $options['database']['charset'] = $charset;
        $options['database']['port'] = $options['database']['port'] ?? $this->getDefaultPort($dbConfig['type']);
        // Prefix is already set above

        // Create database configuration file
        $this->tui->addLog('Creating database configuration...');
        $createDbConfig = new CreatesDatabaseConfig();

        if ($createDbConfig($projectPath, $options)) {
            $this->tui->replaceLastLogs('<fg=green>✔</> Database configuration created.', 2);
        } else {
            throw new \RuntimeException("Failed to create database configuration.");
        }
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
            'sqlsrv' => 1433,
            default => 3306,
        };
    }

    /**
     * Get default charset for database type.
     *
     * @param string $type
     * @return string
     */
    protected function getDefaultCharset(string $type): string
    {
        return match($type) {
            'pgsql' => 'utf8',
            'mysql' => 'utf8mb4',
            'sqlite' => 'utf8',
            'sqlsrv' => 'utf8',
            default => 'utf8mb4',
        };
    }

    /**
     * Run database seeders.
     *
     * @param string $projectPath
     * @return void
     */
    protected function runSeeders(string $projectPath, array $options): void
    {
        $this->tui->addLog('Running database seeders...');

        $this->ensureArtisanDependencies($projectPath, $options);

        $artisanScript = null;
        foreach (['core/artisan', 'artisan'] as $candidate) {
            if (file_exists($projectPath . '/' . $candidate)) {
                $artisanScript = $candidate;
                break;
            }
        }

        if ($artisanScript === null) {
            $this->tui->addLog('No artisan found. Skipping seeders (may need manual seeding).', 'warning');
            return;
        }

        // Seeders are always in core/database/seeders
        $seederDir = $projectPath . '/core/database/seeders';

        if (!is_dir($seederDir)) {
            $this->tui->addLog('No seeders directory found. Skipping seeders.', 'warning');
            return;
        }

        // Define seeders in execution order
        $seeders = [
            'SystemSettingsTableSeeder',
            'SystemEventnamesTableSeeder',
            'SiteTemplatesTableSeeder',
            'UserRolesTableSeeder',
            'UserPermissionsTableSeeder',
            'AdminUserTableSeeder',
            'SiteContentTableSeeder',
        ];

        $sessionDir = $projectPath . '/core/storage/sessions';
        if (!str_starts_with($artisanScript, 'core/')) {
            $sessionDir = $projectPath . '/storage/sessions';
        }
        @mkdir($sessionDir, 0755, true);

        $seederNamespace = 'Database\\Seeders\\';

        foreach ($seeders as $seeder) {
            $seederFile = $seederDir . '/' . $seeder . '.php';
            if (!file_exists($seederFile)) {
                $this->tui->addLog("Seeder {$seeder} not found. Skipping.", 'warning');
                continue;
            }

            $this->tui->addLog("Running seeder: {$seeder}...");

            $seederClass = $seederNamespace . $seeder;
            // Use absolute path for seeder file
            $seederFileAbsolute = realpath($seederFile) ?: $seederFile;
            $bootstrapPath = $projectPath . '/core/bootstrap.php';
            if (!file_exists($bootstrapPath)) {
                $bootstrapPath = $projectPath . '/bootstrap.php';
            }

            // Use artisan to properly initialize the environment
            $argv = ['artisan', 'db:seed', '--class', $seederClass, '--force'];
            $phpCode =
                'define("IN_INSTALL_MODE", true);' .
                'define("EVO_CLI", true);' .
                // Make process env variables visible to env() even if Dotenv doesn't import them.
                // IMPORTANT: do not write admin credentials to disk.
                '$__keys=["EVO_ADMIN_USERNAME","EVO_ADMIN_EMAIL","EVO_ADMIN_PASSWORD","EVO_MANAGER_LANGUAGE"];' .
                'foreach($__keys as $__k){$__v=getenv($__k); if($__v!==false){$_ENV[$__k]=$__v; $_SERVER[$__k]=$__v; putenv($__k."=".$__v);} }' .
                'chdir(' . var_export($projectPath, true) . ');' .
                'require_once ' . var_export($bootstrapPath, true) . ';' .
                'require_once ' . var_export($seederFileAbsolute, true) . ';' .
                'if (!class_exists(' . var_export($seederClass, true) . ')) {' .
                '    throw new RuntimeException("Seeder class not found: " . ' . var_export($seederClass, true) . ');' .
                '}' .
                '$_SERVER["argv"]=' . var_export($argv, true) . ';' .
                '$_SERVER["argc"]=count($_SERVER["argv"]);' .
                'require ' . var_export($artisanScript, true) . ';';

            $process = new Process([
                'php',
                '-d',
                'session.save_path=' . $sessionDir,
                '-r',
                $phpCode,
            ], $projectPath);
            $process->setTimeout(120);
            $process->setEnv([
                ...$this->buildProcessEnv(),
                ...$this->buildDatabaseEnv($projectPath, $options['database'] ?? []),
                ...$this->buildAdminEnv($options['admin'] ?? []),
                ...$this->buildLanguageEnv($options['language'] ?? null),
            ]);

            try {
                $buffers = [
                    Process::OUT => '',
                    Process::ERR => '',
                ];

                $process->run(function ($type, $buffer) use (&$buffers) {
                    $buffers[$type] .= $buffer;

                    while (($pos = strpos($buffers[$type], "\n")) !== false) {
                        $line = rtrim(substr($buffers[$type], 0, $pos), "\r");
                        $buffers[$type] = substr($buffers[$type], $pos + 1);

                        if ($line === '') {
                            continue;
                        }

                        $lower = strtolower($line);
                        $isErrorLine =
                            str_contains($lower, 'fatal error') ||
                            str_contains($lower, 'uncaught exception') ||
                            str_contains($lower, 'error:') ||
                            str_contains($lower, 'exception') ||
                            str_contains($lower, 'failed') ||
                            str_contains($lower, 'warning:');

                        // Show all output for debugging
                        $this->tui->addLog($line, $isErrorLine ? 'error' : 'info');
                    }
                });

                // Process remaining buffer
                foreach ($buffers as $type => $tail) {
                    $tail = trim($tail);
                    if ($tail !== '') {
                        $lower = strtolower($tail);
                        $isErrorLine =
                            str_contains($lower, 'fatal error') ||
                            str_contains($lower, 'uncaught exception') ||
                            str_contains($lower, 'error:') ||
                            str_contains($lower, 'exception') ||
                            str_contains($lower, 'failed') ||
                            str_contains($lower, 'warning:');

                        $this->tui->addLog($tail, $isErrorLine ? 'error' : 'info');
                    }
                }

                if ($process->isSuccessful()) {
                    $this->tui->replaceLastLogs("<fg=green>✔</> Seeder {$seeder} completed.", 2);
                } else {
                    $errorOutput = trim($buffers[Process::ERR] ?? $process->getErrorOutput());
                    $stdOutput = trim($buffers[Process::OUT] ?? $process->getOutput());
                    $fullError = !empty($errorOutput) ? $errorOutput : $stdOutput;
                    if (empty($fullError)) {
                        $fullError = "Process exited with code " . $process->getExitCode();
                    }
                    $this->tui->addLog("<fg=red>✗</> Seeder {$seeder} failed: {$fullError}", 'error');
                    throw new \RuntimeException("Seeder {$seeder} failed: {$fullError}");
                }
            } catch (\Exception $e) {
                $this->tui->replaceLastLogs("<fg=red>✗</> Seeder {$seeder} failed: " . $e->getMessage(), 2);
                throw $e;
            }
        }

        $this->tui->addLog('All seeders completed successfully.', 'success');
    }

    /**
     * Setup Composer dependencies.
     *
     * @param string $projectPath
     * @return void
     */
    protected function setupComposer(string $projectPath, array $options): void
    {
        $this->tui->addLog('Installing dependencies with Composer...');

        $composerWorkDir = is_file($projectPath . '/core/composer.json') ? ($projectPath . '/core') : $projectPath;
        $composerJson = $composerWorkDir . '/composer.json';

        if (!file_exists($composerJson)) {
            $this->tui->replaceLastLogs('<fg=yellow>⚠</> composer.json not found. Skipping dependency installation.', 2);
            return;
        }

        $this->tui->addLog('Composer scripts disabled: --no-scripts');

        // Some releases may omit these directories; Composer and Evolution CMS expect them.
        @mkdir($composerWorkDir . '/storage/bootstrap', 0755, true);
        @mkdir($composerWorkDir . '/storage/cache', 0755, true);
        @mkdir($composerWorkDir . '/storage/logs', 0755, true);
        @mkdir($composerWorkDir . '/storage/sessions', 0755, true);

        $this->tui->addLog('Composer working directory: ' . basename($composerWorkDir));

        $composerCommand = $this->resolveComposerCommand($composerWorkDir);
        $clearCache = !empty($options['composer_clear_cache']);
        $useUpdate = !empty($options['composer_update']);
        $modeLabel = $useUpdate ? 'update' : 'install';

        try {
            $vendorDir = $composerWorkDir . '/vendor';
            if (is_dir($vendorDir)) {
                $this->tui->addLog('Existing vendor directory detected. Removing for a clean install...', 'warning');
                $this->removeVendorDirectory($vendorDir, 'clean install');
            } else {
                $this->tui->addLog('No existing vendor directory detected. Proceeding with clean install.');
            }

            if ($clearCache) {
                $this->tui->addLog('Clearing Composer cache (--composer-clear-cache)...', 'warning');
                $clear = $this->runComposer($composerCommand, ['clear-cache'], $composerWorkDir);
                if (!$clear->isSuccessful()) {
                    $out = $this->sanitizeComposerOutput($clear->getOutput() . "\n" . $clear->getErrorOutput());
                    $this->tui->addLog('Composer cache clear failed: ' . trim($out), 'warning');
                } else {
                    $this->tui->addLog('Composer cache cleared.', 'success');
                }
            }

            $installArgs = [$useUpdate ? 'update' : 'install', '--no-dev', '--prefer-dist', '--no-scripts'];
            $this->tui->addLog('Composer mode: ' . $modeLabel);
            $process = $this->runComposer($composerCommand, $installArgs, $composerWorkDir);
            $fullOutput = $this->sanitizeComposerOutput($process->getOutput() . "\n" . $process->getErrorOutput());
            if ($process->isSuccessful()) {
                $this->tui->addLog('Dependencies installed successfully.', 'success');
                return;
            }

            throw new \RuntimeException(trim($fullOutput));
        } catch (\Exception $e) {
            $this->tui->addLog('Failed to ' . $modeLabel . ' dependencies: ' . $e->getMessage(), 'error');
            $manual = $useUpdate ? 'composer update' : 'composer install';
            throw new \RuntimeException("Failed to {$modeLabel} dependencies. Please run '{$manual}' manually.");
        }
    }

    protected function isComposerVendorHealthy(string $composerWorkDir): bool
    {
        return $this->composerVendorIssues($composerWorkDir) === [];
    }

    protected function verifyComposerVendor(string $composerWorkDir, int $attempts = 3, int $sleepMs = 400): bool
    {
        $this->tui->addLog('Verifying Composer dependencies...');
        for ($i = 0; $i < $attempts; $i++) {
            $report = [];
            $issues = $this->composerVendorIssues($composerWorkDir, $report);
            if ($issues === []) {
                return true;
            }
            if ($i === 0) {
                $this->logComposerVendorIssues($composerWorkDir, $report, 'Composer vendor incomplete.');
            }
            if ($i < $attempts - 1) {
                usleep($sleepMs * 1000);
            }
        }
        return false;
    }

    protected function composerVendorIssues(string $composerWorkDir, ?array &$report = null): array
    {
        $issues = [];
        $report = [
            'installed_path' => null,
            'installed_format' => null,
            'package_count' => 0,
            'missing_artifacts' => [],
            'missing_packages' => [],
            'missing_classes' => [],
            'notes' => [],
        ];

        $autoload = $composerWorkDir . '/vendor/autoload.php';
        if (!is_file($autoload) || !$this->isNonEmptyFile($autoload)) {
            $issues[] = 'vendor/autoload.php';
            $report['missing_artifacts'][] = 'vendor/autoload.php';
        }

        $autoloadFiles = [
            'vendor/composer/autoload_real.php',
            'vendor/composer/autoload_psr4.php',
            'vendor/composer/autoload_classmap.php',
        ];
        foreach ($autoloadFiles as $relative) {
            $path = $composerWorkDir . '/' . $relative;
            if (!is_file($path) || !$this->isNonEmptyFile($path)) {
                $issues[] = $relative;
                $report['missing_artifacts'][] = $relative;
            }
        }

        $packages = $this->composerInstalledPackages($composerWorkDir, $report);
        $report['package_count'] = count($packages);
        if ($report['installed_path'] === null) {
            $issues[] = 'vendor/composer/installed.php (or installed.json)';
            $report['missing_artifacts'][] = 'vendor/composer/installed.php';
        } elseif ($packages === []) {
            $issues[] = 'composer installed metadata is empty';
        }

        // Migrations/seeders use Artisan (Illuminate Console) which requires Symfony Console.
        $requiredPackages = [
            'symfony/console',
            'symfony/http-kernel',
            'symfony/routing',
            'illuminate/support',
            'illuminate/console',
        ];
        if ($packages !== []) {
            foreach ($requiredPackages as $pkg) {
                if (!in_array($pkg, $packages, true)) {
                    $issues[] = 'missing package: ' . $pkg;
                    $report['missing_packages'][] = $pkg;
                }
            }
        }

        $requiredFiles = [
            ['vendor/symfony/console/Application.php', 'symfony/console'],
            ['vendor/symfony/http-kernel/Kernel.php', 'symfony/http-kernel'],
            ['vendor/symfony/routing/RouteCollection.php', 'symfony/routing'],
            ['vendor/illuminate/console/Application.php', 'illuminate/console'],
        ];
        foreach ($requiredFiles as $entry) {
            [$relative, $label] = $entry;
            $path = $composerWorkDir . '/' . $relative;
            if (!is_file($path) || !$this->isNonEmptyFile($path)) {
                $issues[] = 'missing file: ' . $label;
                $report['missing_artifacts'][] = $relative;
            }
        }

        $macroableCandidates = [
            'vendor/illuminate/macroable/Traits/Macroable.php',
            'vendor/illuminate/support/Traits/Macroable.php',
        ];
        $macroableFound = false;
        foreach ($macroableCandidates as $relative) {
            $path = $composerWorkDir . '/' . $relative;
            if (is_file($path) && $this->isNonEmptyFile($path)) {
                $macroableFound = true;
                break;
            }
        }
        if (!$macroableFound) {
            $issues[] = 'missing file: Macroable trait';
            foreach ($macroableCandidates as $relative) {
                $report['missing_artifacts'][] = $relative;
            }
        }

        $requiredClasses = [
            'Symfony\\Component\\Console\\Application',
            'Symfony\\Component\\HttpKernel\\Kernel',
            'Symfony\\Component\\Routing\\RouteCollection',
            'Illuminate\\Console\\Application',
        ];
        $missingClasses = $this->checkComposerAutoloadClasses($composerWorkDir, $requiredClasses, $report);
        foreach ($missingClasses as $className) {
            $issues[] = 'missing class: ' . $className;
            $report['missing_classes'][] = $className;
        }

        $macroableClasses = [
            'Illuminate\\Macroable\\Traits\\Macroable',
            'Illuminate\\Support\\Traits\\Macroable',
        ];
        $macroableMissing = $this->checkComposerAutoloadClasses($composerWorkDir, $macroableClasses, $report);
        if (count($macroableMissing) === count($macroableClasses)) {
            foreach ($macroableMissing as $className) {
                $issues[] = 'missing class: ' . $className;
                $report['missing_classes'][] = $className;
            }
        }

        return $issues;
    }

    protected function isNonEmptyFile(string $path): bool
    {
        $size = @filesize($path);
        return is_int($size) && $size > 0;
    }

    protected function ensureArtisanDependencies(string $projectPath, array $options): void
    {
        return;
    }

    protected function hasGitExecutable(): bool
    {
        try {
            $p = new Process(['git', '--version'], null, $this->buildProcessEnv());
            $p->setTimeout(5);
            $p->run();
            return $p->isSuccessful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function runComposer(array $composerCommand, array $args, string $workingDir): Process
    {
        $fullCommand = [
            ...$composerCommand,
            ...$args,
            '--no-interaction',
            '--no-ansi',
        ];
        $process = new Process($fullCommand, $workingDir);
        $timeout = $this->composerTimeoutSeconds();
        $idleTimeout = $this->composerIdleTimeoutSeconds();
        if ($timeout > 0) {
            $process->setTimeout($timeout);
        }
        if ($idleTimeout !== null) {
            $process->setIdleTimeout($idleTimeout);
        }

        $env = $this->buildProcessEnv();
        if (!isset($env['COMPOSER_PROCESS_TIMEOUT']) && $timeout > 0) {
            $env['COMPOSER_PROCESS_TIMEOUT'] = (string) $timeout;
        }
        $process->setEnv($env);

        $this->tui->addLog('Composer executable: ' . $this->describeComposerExecutable($composerCommand));
        $version = $this->getComposerVersion($composerCommand, $workingDir);
        if ($version !== null) {
            $this->tui->addLog('Composer version: ' . $version);
        } else {
            $this->tui->addLog('Composer version: unknown', 'warning');
        }
        $this->tui->addLog('Composer command: ' . $this->formatComposerCommand($fullCommand));
        $this->tui->addLog('Composer working dir: ' . $workingDir);
        $this->tui->addLog('Composer timeout: ' . $this->formatComposerTimeout($timeout, $idleTimeout));
        $this->tui->addLog('Composer env: ' . $this->formatComposerEnvSummary($env));

        $buffers = [
            Process::OUT => '',
            Process::ERR => '',
        ];

        $start = microtime(true);
        try {
            $process->run(function ($type, $buffer) use (&$buffers) {
                $buffers[$type] .= $buffer;

                while (($pos = strpos($buffers[$type], "\n")) !== false) {
                    $line = rtrim(substr($buffers[$type], 0, $pos), "\r");
                    $buffers[$type] = substr($buffers[$type], $pos + 1);

                    if ($line === '') {
                        continue;
                    }

                    $lower = strtolower($line);

                    // Suppress noisy PHP deprecation notices coming from system Composer/Symfony packages.
                    if (str_starts_with($lower, 'deprecation notice:') || str_starts_with($lower, 'php deprecated:')) {
                        continue;
                    }

                    $isErrorLine =
                        str_contains($lower, 'fatal error') ||
                        str_contains($lower, 'uncaught exception') ||
                        str_contains($lower, 'error:') ||
                        str_contains($lower, 'exception');

                    $this->tui->addLog($line, $isErrorLine ? 'error' : 'info');
                }
            });
        } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
            $duration = microtime(true) - $start;
            $timeoutType = $e->isGeneralTimeout() ? 'overall timeout' : 'idle timeout';
            $this->tui->addLog(
                sprintf(
                    'Composer process killed by %s after %.1fs (timeout=%ss, idle=%s). Working dir: %s',
                    $timeoutType,
                    $duration,
                    $timeout > 0 ? (string) $timeout : 'disabled',
                    $idleTimeout !== null ? (string) $idleTimeout : 'disabled',
                    $workingDir
                ),
                'error'
            );
            throw $e;
        }

        foreach ($buffers as $type => $tail) {
            $tail = trim($tail);
            if ($tail === '') {
                continue;
            }

            $lower = strtolower($tail);
            if (str_starts_with($lower, 'deprecation notice:') || str_starts_with($lower, 'php deprecated:')) {
                continue;
            }

            $isErrorLine =
                str_contains($lower, 'fatal error') ||
                str_contains($lower, 'uncaught exception') ||
                str_contains($lower, 'error:') ||
                str_contains($lower, 'exception');

            $this->tui->addLog($tail, $isErrorLine ? 'error' : 'info');
        }

        $duration = microtime(true) - $start;
        $exit = $process->getExitCode();
        $signal = method_exists($process, 'getSignal') ? $process->getSignal() : null;
        $suffix = $signal ? sprintf(' (signal %s)', (string) $signal) : '';
        $level = $process->isSuccessful() ? 'info' : 'warning';
        $this->tui->addLog(sprintf('Composer finished in %.1fs with exit code %s%s.', $duration, (string) ($exit ?? 0), $suffix), $level);

        $fullOutput = $this->sanitizeComposerOutput($process->getOutput() . "\n" . $process->getErrorOutput());
        $diag = $this->analyzeComposerOutput($fullOutput);
        $this->logComposerDiagnostics($diag);

        return $process;
    }

    protected function composerTimeoutSeconds(): int
    {
        $raw = getenv('EVO_COMPOSER_TIMEOUT');
        if ($raw !== false && is_numeric($raw)) {
            $val = (int) $raw;
            if ($val > 0) {
                return $val;
            }
        }
        return 1800;
    }

    protected function composerIdleTimeoutSeconds(): ?int
    {
        $raw = getenv('EVO_COMPOSER_IDLE_TIMEOUT');
        if ($raw === false || trim((string) $raw) === '') {
            return null;
        }
        if (is_numeric($raw)) {
            $val = (int) $raw;
            if ($val > 0) {
                return $val;
            }
        }
        return null;
    }

    protected function formatComposerTimeout(int $timeout, ?int $idleTimeout): string
    {
        if ($timeout <= 0 && $idleTimeout === null) {
            return 'disabled';
        }
        $parts = [];
        if ($timeout > 0) {
            $parts[] = $timeout . 's';
        } else {
            $parts[] = 'disabled';
        }
        if ($idleTimeout !== null) {
            $parts[] = 'idle ' . $idleTimeout . 's';
        }
        return implode(', ', $parts);
    }

    protected function formatComposerCommand(array $command): string
    {
        $parts = [];
        foreach ($command as $part) {
            $part = (string) $part;
            if ($part === '') {
                continue;
            }
            if (preg_match('/\\s/', $part) === 1) {
                $parts[] = '"' . $part . '"';
            } else {
                $parts[] = $part;
            }
        }
        return implode(' ', $parts);
    }

    protected function describeComposerExecutable(array $command): string
    {
        if ($command === []) {
            return 'unknown';
        }
        $first = (string) $command[0];
        if (strtolower($first) === 'php' && isset($command[1])) {
            return 'php ' . $command[1];
        }
        return $first;
    }

    protected function getComposerVersion(array $command, string $workingDir): ?string
    {
        $key = implode("\0", $command);
        if (array_key_exists($key, $this->composerVersionCache)) {
            $cached = $this->composerVersionCache[$key];
            return $cached !== '' ? $cached : null;
        }

        $process = new Process([...$command, '--version'], $workingDir, $this->buildProcessEnv());
        $process->setTimeout(10);
        $process->run();

        $output = trim((string) $process->getOutput());
        $err = trim((string) $process->getErrorOutput());
        $line = $output !== '' ? $output : $err;
        if ($line !== '') {
            $line = strtok($line, "\n") ?: $line;
            $line = preg_replace('/\\s+/', ' ', $line) ?? $line;
        }

        if ($line === '') {
            $this->composerVersionCache[$key] = '';
            return null;
        }

        $this->composerVersionCache[$key] = $line;
        return $line;
    }

    protected function formatComposerEnvSummary(array $env): string
    {
        $keys = [
            'COMPOSER_HOME',
            'COMPOSER_CACHE_DIR',
            'COMPOSER_PROCESS_TIMEOUT',
        ];
        $summary = [];
        foreach ($keys as $key) {
            if (isset($env[$key]) && trim((string) $env[$key]) !== '') {
                $summary[] = $key . '=' . $env[$key];
            }
        }
        $summary[] = 'GITHUB_TOKEN=' . (getenv('GITHUB_TOKEN') ? 'present' : 'absent');
        $summary[] = 'COMPOSER_AUTH=' . (getenv('COMPOSER_AUTH') ? 'present' : 'absent');
        return implode(', ', $summary);
    }

    protected function analyzeComposerOutput(string $output): array
    {
        $lower = strtolower($output);
        $rateLimit = str_contains($lower, 'api rate limit')
            || str_contains($lower, 'github api rate limit')
            || str_contains($lower, 'rate limit exceeded')
            || str_contains($lower, '429 too many requests')
            || (str_contains($lower, '403') && str_contains($lower, 'github'));
        $distFailure = str_contains($lower, 'failed to download')
            || str_contains($lower, 'could not download')
            || str_contains($lower, 'curl error 22')
            || str_contains($lower, 'the requested url returned error: 404');
        $networkFailure = str_contains($lower, 'could not resolve host')
            || str_contains($lower, 'connection timed out')
            || str_contains($lower, 'operation timed out');

        $messages = [];
        if ($rateLimit) {
            $messages[] = 'Composer hit a GitHub rate limit.';
            if (!getenv('GITHUB_TOKEN')) {
                $messages[] = 'Tip: set GITHUB_TOKEN to increase GitHub API limits.';
            }
        }
        if ($distFailure) {
            $messages[] = 'Composer failed to download dist packages (consider retrying).';
        }
        if ($networkFailure) {
            $messages[] = 'Network timeout detected while running Composer.';
        }

        return [
            'messages' => $messages,
            'rate_limit' => $rateLimit,
            'prefer_source' => $rateLimit || $distFailure,
        ];
    }

    protected function logComposerDiagnostics(array $diag): void
    {
        if (!isset($diag['messages']) || $diag['messages'] === []) {
            return;
        }
        foreach ($diag['messages'] as $msg) {
            $this->tui->addLog($msg, 'warning');
        }
    }

    protected function logComposerVendorIssues(string $composerWorkDir, array &$report, string $prefix): void
    {
        if ($report === []) {
            $this->composerVendorIssues($composerWorkDir, $report);
        }
        $this->tui->addLog($prefix, 'warning');

        $installedPath = $report['installed_path'] ?? null;
        if ($installedPath) {
            $pkgCount = (int) ($report['package_count'] ?? 0);
            $this->tui->addLog("Composer installed metadata: {$installedPath} (packages: {$pkgCount}).", 'warning');
        } else {
            $this->tui->addLog("Composer installed metadata not found in {$composerWorkDir}/vendor/composer.", 'warning');
        }

        $missingArtifacts = $report['missing_artifacts'] ?? [];
        if ($missingArtifacts !== []) {
            $this->tui->addLog('Missing artifacts: ' . implode(', ', $missingArtifacts), 'warning');
            foreach ($missingArtifacts as $artifact) {
                if (is_string($artifact) && str_starts_with($artifact, 'vendor/')) {
                    $this->tui->addLog('Missing file: ' . $composerWorkDir . '/' . $artifact, 'warning');
                }
            }
        }

        $missingPackages = $report['missing_packages'] ?? [];
        if ($missingPackages !== []) {
            $this->tui->addLog('Missing packages: ' . implode(', ', $missingPackages), 'warning');
        }

        $missingClasses = $report['missing_classes'] ?? [];
        if ($missingClasses !== []) {
            $this->tui->addLog('Missing classes: ' . implode(', ', $missingClasses), 'warning');
        }

        $notes = $report['notes'] ?? [];
        if ($notes !== []) {
            $this->tui->addLog('Notes: ' . implode(' | ', $notes), 'warning');
        }
    }

    protected function logComposerVendorFileStatus(string $composerWorkDir): void
    {
        $checks = [
            'vendor/autoload.php',
            'vendor/symfony/console/Application.php',
        ];

        $installedPhp = $composerWorkDir . '/vendor/composer/installed.php';
        $installedJson = $composerWorkDir . '/vendor/composer/installed.json';
        $installedOk = is_file($installedPhp) || is_file($installedJson);
        $checks[] = 'vendor/composer/installed.(php|json)';

        $statusParts = [];
        foreach ($checks as $relative) {
            if ($relative === 'vendor/composer/installed.(php|json)') {
                $statusParts[] = $relative . '=' . ($installedOk ? 'ok' : 'missing');
                if (!$installedOk) {
                    $this->tui->addLog('Missing file: ' . $composerWorkDir . '/vendor/composer/installed.php', 'warning');
                }
                continue;
            }
            $path = $composerWorkDir . '/' . $relative;
            $ok = is_file($path) && $this->isNonEmptyFile($path);
            $statusParts[] = $relative . '=' . ($ok ? 'ok' : 'missing');
            if (!$ok) {
                $this->tui->addLog('Missing file: ' . $composerWorkDir . '/' . $relative, 'warning');
            }
        }

        $this->tui->addLog('Vendor file check: ' . implode(', ', $statusParts), 'warning');
    }

    protected function composerInstalledPackages(string $composerWorkDir, array &$report): array
    {
        $packages = [];

        $installedPhp = $composerWorkDir . '/vendor/composer/installed.php';
        if (is_file($installedPhp)) {
            $report['installed_path'] = $installedPhp;
            $data = @include $installedPhp;
            if (is_array($data)) {
                $report['installed_format'] = 'php';
                $packages = $this->extractComposerPackageNames($data);
            } else {
                $report['missing_artifacts'][] = 'vendor/composer/installed.php (invalid)';
            }
        }

        if ($packages === []) {
            $installedJson = $composerWorkDir . '/vendor/composer/installed.json';
            if (is_file($installedJson)) {
                $report['installed_path'] = $installedJson;
                $data = json_decode((string) @file_get_contents($installedJson), true);
                if (is_array($data)) {
                    $report['installed_format'] = 'json';
                    $packages = $this->extractComposerPackageNames($data);
                } else {
                    $report['missing_artifacts'][] = 'vendor/composer/installed.json (invalid)';
                }
            }
        }

        return array_values(array_unique($packages));
    }

    protected function extractComposerPackageNames(mixed $data): array
    {
        $names = [];
        $push = static function ($name) use (&$names) {
            if (!is_string($name) || $name === '' || $name === 'root') {
                return;
            }
            $names[$name] = true;
        };

        if (is_array($data)) {
            if (isset($data['versions']) && is_array($data['versions'])) {
                foreach (array_keys($data['versions']) as $name) {
                    $push($name);
                }
                return array_keys($names);
            }
            if (isset($data['packages']) && is_array($data['packages'])) {
                foreach ($data['packages'] as $pkg) {
                    if (is_array($pkg) && isset($pkg['name'])) {
                        $push($pkg['name']);
                    }
                }
            }
            if (isset($data['packages-dev']) && is_array($data['packages-dev'])) {
                foreach ($data['packages-dev'] as $pkg) {
                    if (is_array($pkg) && isset($pkg['name'])) {
                        $push($pkg['name']);
                    }
                }
            }
            if (isset($data['installed']) && is_array($data['installed'])) {
                foreach ($data['installed'] as $installed) {
                    foreach ($this->extractComposerPackageNames($installed) as $name) {
                        $push($name);
                    }
                }
            }
            if (isset($data[0]) && is_array($data[0]) && isset($data[0]['name'])) {
                foreach ($data as $pkg) {
                    if (is_array($pkg) && isset($pkg['name'])) {
                        $push($pkg['name']);
                    }
                }
            }
        }

        return array_keys($names);
    }

    protected function checkComposerAutoloadClasses(string $composerWorkDir, array $classes, array &$report): array
    {
        $autoload = $composerWorkDir . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            return $classes;
        }

        $classes = array_values(array_filter(array_unique($classes), 'is_string'));
        if ($classes === []) {
            return [];
        }

        $script = '$autoload=$argv[1]??"";$classes=json_decode($argv[2]??"[]",true);'
            . 'if(!is_array($classes)){$classes=[];}'
            . 'if(!is_file($autoload)){fwrite(STDERR,"autoload missing\n");exit(2);}require $autoload;'
            . '$missing=[];foreach($classes as $class){if(!class_exists($class)){ $missing[]=$class; }}'
            . 'echo json_encode($missing);';

        $process = new Process([
            PHP_BINARY ?: 'php',
            '-r',
            $script,
            $autoload,
            json_encode($classes),
        ], $composerWorkDir, $this->buildProcessEnv());
        $process->setTimeout(20);
        $process->run();

        if (!$process->isSuccessful()) {
            $err = trim($process->getErrorOutput());
            if ($err === '') {
                $err = trim($process->getOutput());
            }
            if ($err === '') {
                $err = 'autoload class check failed';
            }
            $err = preg_replace('/\\s+/', ' ', $err) ?? $err;
            $report['notes'][] = 'autoload_check_failed: ' . $err;
            return $classes;
        }

        $decoded = json_decode(trim($process->getOutput()), true);
        if (!is_array($decoded)) {
            $report['notes'][] = 'autoload_check_failed: invalid json';
            return $classes;
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    protected function removeVendorDirectory(string $vendorDir, string $reason): void
    {
        $this->tui->addLog("Removing vendor directory ({$reason}): {$vendorDir}", 'warning');
        $this->removeDirectory($vendorDir);
    }

    protected function buildProcessEnv(): array
    {
        $env = $_ENV ?? [];

        $path = getenv('PATH');
        if (!$path && isset($_SERVER['PATH'])) {
            $path = $_SERVER['PATH'];
        }
        if (!$path) {
            $path = '/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin';
        }

        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '/root');
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $userInfo = @posix_getpwuid(posix_geteuid());
            if ($userInfo && isset($userInfo['dir'])) {
                $home = $userInfo['dir'];
            }
        }

        $env['PATH'] = $path;
        $env['HOME'] = $home;
        $env['COMPOSER_ALLOW_SUPERUSER'] ??= '1';

        return $env;
    }

    protected function buildDatabaseEnv(string $projectPath, array $dbConfig): array
    {
        $dbConfigForOps = $this->getDatabaseConfigForOperations($projectPath, $dbConfig);

        return array_filter([
            'DB_TYPE' => isset($dbConfigForOps['type']) ? (string) $dbConfigForOps['type'] : null,
            'DB_HOST' => (string) ($dbConfigForOps['host'] ?? ''),
            'DB_PORT' => isset($dbConfigForOps['port']) ? (string) $dbConfigForOps['port'] : null,
            'DB_DATABASE' => isset($dbConfigForOps['name']) ? (string) $dbConfigForOps['name'] : null,
            'DB_USERNAME' => (string) ($dbConfigForOps['user'] ?? ''),
            'DB_PASSWORD' => (string) ($dbConfigForOps['password'] ?? ''),
            'DB_PREFIX' => (string) ($dbConfigForOps['prefix'] ?? 'evo_'),
            'DB_CHARSET' => isset($dbConfigForOps['charset']) ? (string) $dbConfigForOps['charset'] : null,
            'DB_COLLATION' => isset($dbConfigForOps['collation']) ? (string) $dbConfigForOps['collation'] : null,
        ], static fn($v) => $v !== null);
    }

    protected function buildAdminEnv(array $adminConfig): array
    {
        return array_filter([
            'EVO_ADMIN_USERNAME' => isset($adminConfig['username']) ? (string) $adminConfig['username'] : null,
            'EVO_ADMIN_EMAIL' => isset($adminConfig['email']) ? (string) $adminConfig['email'] : null,
            'EVO_ADMIN_PASSWORD' => isset($adminConfig['password']) ? (string) $adminConfig['password'] : null,
        ], static fn($v) => $v !== null);
    }

    protected function buildLanguageEnv(mixed $language): array
    {
        $lang = is_string($language) ? trim($language) : '';
        if ($lang === '') {
            return [];
        }
        return [
            'EVO_MANAGER_LANGUAGE' => $lang,
        ];
    }

    protected function setManagerLanguage(string $projectPath, array $options): void
    {
        $language = isset($options['language']) ? (string) $options['language'] : '';
        $language = trim($language);
        if ($language === '') {
            return;
        }

        $dbConfig = $options['database'] ?? [];
        if (!is_array($dbConfig) || $dbConfig === []) {
            $this->tui->addLog('Unable to set manager language: missing database configuration.', 'warning');
            return;
        }

        $dbConfigForOps = $this->getDatabaseConfigForOperations($projectPath, $dbConfig);
        $type = (string) ($dbConfigForOps['type'] ?? '');
        $prefix = (string) ($dbConfigForOps['prefix'] ?? ($dbConfig['prefix'] ?? 'evo_'));
        $table = $prefix . 'system_settings';
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            $this->tui->addLog('Unable to set manager language: invalid table name.', 'warning');
            return;
        }

        try {
            $dbh = $this->createConnection($dbConfigForOps);

            $tableSql = $this->quoteIdentifier($type, $table);
            $stmt = $dbh->prepare("UPDATE {$tableSql} SET setting_value = :v WHERE setting_name = 'manager_language'");
            $stmt->execute([':v' => $language]);

            if ((int) $stmt->rowCount() === 0) {
                // Best-effort: try insert if the row doesn't exist.
                try {
                    $ins = $dbh->prepare("INSERT INTO {$tableSql} (setting_name, setting_value) VALUES ('manager_language', :v)");
                    $ins->execute([':v' => $language]);
                } catch (\Throwable $e) {
                    // Ignore insert failures; schema might differ.
                }
            }

            $this->tui->addLog("Manager language set: {$language}.", 'success');
        } catch (\Throwable $e) {
            $this->tui->addLog('Unable to set manager language: ' . $e->getMessage(), 'warning');
        }
    }

    protected function quoteIdentifier(string $type, string $identifier): string
    {
        // Identifier is validated to /^[A-Za-z0-9_]+$/.
        return match ($type) {
            'pgsql', 'sqlite' => '"' . $identifier . '"',
            'sqlsrv' => '[' . $identifier . ']',
            default => '`' . $identifier . '`',
        };
    }

    protected function writeCoreCustomEnv(string $projectPath, array $options): void
    {
        $customDir = $projectPath . '/core/custom';
        if (!is_dir($customDir) && !@mkdir($customDir, 0755, true) && !is_dir($customDir)) {
            $this->tui?->addLog("Could not create directory: {$customDir}", 'warning');
            return;
        }

        $envFile = $customDir . '/.env';

        $vars = [
            ...$this->buildDatabaseEnv($projectPath, $options['database'] ?? []),
        ];

        $githubPat = $options['github_pat'] ?? null;
        if (is_string($githubPat) && trim($githubPat) !== '') {
            $vars['GITHUB_PAT'] = trim($githubPat);
        }

        $adminDir = $this->sanitizeAdminDirectory($options['admin']['directory'] ?? null);
        if ($adminDir !== 'manager') {
            $vars['MGR_DIR'] = $adminDir;
        }

        if ($vars === []) {
            return;
        }

        $existing = '';
        if (is_file($envFile) && is_readable($envFile)) {
            $existing = (string) file_get_contents($envFile);
        }

        $lines = $existing !== '' ? preg_split('/\\R/', $existing) : [];
        if (!is_array($lines)) {
            $lines = [];
        }

        $linesUpdated = [];
        $replaced = [];
        foreach ($lines as $line) {
            $newLine = $line;
            foreach ($vars as $key => $value) {
                if (isset($replaced[$key])) {
                    continue;
                }
                if (preg_match('/^\\s*' . preg_quote($key, '/') . '\\s*=/', $line) === 1) {
                    $newLine = $key . '=' . $this->envQuote($value);
                    $replaced[$key] = true;
                    break;
                }
            }
            $linesUpdated[] = $newLine;
        }

        if ($linesUpdated !== [] && trim((string) end($linesUpdated)) !== '') {
            $linesUpdated[] = '';
        }

        foreach ($vars as $key => $value) {
            if (isset($replaced[$key])) {
                continue;
            }
            $linesUpdated[] = $key . '=' . $this->envQuote($value);
        }

        $content = implode("\n", $linesUpdated);
        if ($content !== '' && !str_ends_with($content, "\n")) {
            $content .= "\n";
        }

        if (@file_put_contents($envFile, $content) === false) {
            $this->tui?->addLog("Could not write env file: {$envFile}", 'warning');
        }
    }

    protected function envQuote(string $value): string
    {
        $escaped = str_replace(["\\", "\""], ["\\\\", "\\\""], $value);
        return "\"{$escaped}\"";
    }

    /**
     * Resolve Composer command to run.
     *
     * Prefers system Composer if available; otherwise bootstraps a local
     * `composer.phar` inside the project to avoid requiring server-wide setup.
     */
    protected function resolveComposerCommand(string $composerWorkDir): array
    {
        if (isset($this->composerCommandCache[$composerWorkDir])) {
            return $this->composerCommandCache[$composerWorkDir];
        }

        $composerExecutable = SystemInfo::getComposerExecutable();
        if ($composerExecutable !== null) {
            return $this->composerCommandCache[$composerWorkDir] = [$composerExecutable];
        }

        $localComposer = $this->bootstrapLocalComposer($composerWorkDir);
        if ($localComposer !== null) {
            return $this->composerCommandCache[$composerWorkDir] = $localComposer;
        }

        throw new \RuntimeException(
            "Composer executable not found. Install Composer on the server or place `composer.phar` in {$composerWorkDir}/.evo-installer/ and re-run."
        );
    }

    /**
     * Download and install a local Composer PHAR under the project directory.
     *
     * @return array{0:string,1:string}|null
     */
    protected function bootstrapLocalComposer(string $composerWorkDir): ?array
    {
        $installerDir = rtrim($composerWorkDir, '/') . '/.evo-installer';
        @mkdir($installerDir, 0755, true);

        $composerPhar = $installerDir . '/composer.phar';
        if (is_file($composerPhar)) {
            return [PHP_BINARY ?: 'php', $composerPhar];
        }

        $this->tui->addLog('Composer not found. Trying to download a local composer.phar...', 'warning');

        try {
            $client = new Client(['timeout' => 60, 'allow_redirects' => true]);
            $expectedSig = trim((string) $client->get('https://composer.github.io/installer.sig')->getBody());
            $installerBody = (string) $client->get('https://getcomposer.org/installer')->getBody();
        } catch (\Exception $e) {
            $this->tui->addLog('Failed to download Composer installer: ' . $e->getMessage(), 'warning');
            return null;
        }

        if ($expectedSig === '' || $installerBody === '') {
            $this->tui->addLog('Composer installer download returned empty content.', 'warning');
            return null;
        }

        $setupFile = $installerDir . '/composer-setup.php';
        if (@file_put_contents($setupFile, $installerBody) === false) {
            $this->tui->addLog('Failed to write Composer installer to disk.', 'warning');
            return null;
        }

        $actualSig = @hash_file('sha384', $setupFile);
        if (!$actualSig || !hash_equals($expectedSig, $actualSig)) {
            @unlink($setupFile);
            $this->tui->addLog('Composer installer signature mismatch. Aborting download.', 'error');
            return null;
        }

        $process = new Process([
            PHP_BINARY ?: 'php',
            $setupFile,
            '--no-ansi',
            '--install-dir=' . $installerDir,
            '--filename=composer.phar',
        ], $composerWorkDir, $this->buildProcessEnv());
        $process->setTimeout(120);
        $process->run();

        @unlink($setupFile);

        if (!$process->isSuccessful() || !is_file($composerPhar)) {
            $output = $this->sanitizeComposerOutput($process->getOutput() . "\n" . $process->getErrorOutput());
            $this->tui->addLog('Local Composer install failed: ' . trim($output), 'warning');
            return null;
        }

        $this->tui->addLog('Local composer.phar installed: ' . $composerPhar, 'success');
        return [PHP_BINARY ?: 'php', $composerPhar];
    }

    protected function sanitizeComposerOutput(string $output): string
    {
        $lines = preg_split('/\\R/', $output) ?: [];
        $filtered = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $lower = strtolower($trimmed);
            if (str_starts_with($lower, 'deprecation notice:') || str_starts_with($lower, 'php deprecated:')) {
                continue;
            }
            $filtered[] = $trimmed;
        }
        return implode("\n", $filtered);
    }

    /**
     * Run database migrations.
     *
     * @param string $projectPath
     * @return void
     */
    protected function runMigrations(string $projectPath, array $options): void
    {
        $this->tui->addLog('Running database migrations...');

        $this->ensureArtisanDependencies($projectPath, $options);

        if (($options['database']['type'] ?? null) === 'pgsql') {
            $this->patchPostgresInetDefaultsInMigrations($projectPath);
        }

        $artisanScript = null;
        foreach (['core/artisan', 'artisan'] as $candidate) {
            if (file_exists($projectPath . '/' . $candidate)) {
                $artisanScript = $candidate;
                break;
            }
        }

        if ($artisanScript === null) {
            $this->tui->addLog('No artisan found. Skipping migrations (may need manual installation).', 'warning');
            return;
        }

        $isCoreArtisan = str_starts_with($artisanScript, 'core/');
        $migrationSources = $isCoreArtisan
            ? [
                ['dir' => $projectPath . '/core/database/migrations', 'path' => 'database/migrations'],
                ['dir' => $projectPath . '/install/stubs/migrations', 'path' => '../install/stubs/migrations'],
            ]
            : [
                ['dir' => $projectPath . '/core/database/migrations', 'path' => 'core/database/migrations'],
                ['dir' => $projectPath . '/install/stubs/migrations', 'path' => 'install/stubs/migrations'],
            ];

        $migrationPath = null;
        foreach ($migrationSources as $source) {
            if (!is_dir($source['dir'])) {
                continue;
            }
            if (glob($source['dir'] . '/*.php') === []) {
                continue;
            }
            $migrationPath = $source['path'];
            break;
        }

        if ($migrationPath === null) {
            $this->tui->addLog('No migrations found. Skipping migrations.', 'warning');
            return;
        }

        $this->tui->addLog("Using migrations from: {$migrationPath}");

        $sessionDir = $projectPath . '/core/storage/sessions';
        if (!str_starts_with($artisanScript, 'core/')) {
            $sessionDir = $projectPath . '/storage/sessions';
        }
        @mkdir($sessionDir, 0755, true);

        $argv = ['artisan', 'migrate', '--force', '--path=' . $migrationPath];
        $phpCode =
            'define("IN_INSTALL_MODE", true);' .
            'define("EVO_CLI", true);' .
            '$_SERVER["argv"]=' . var_export($argv, true) . ';' .
            '$_SERVER["argc"]=count($_SERVER["argv"]);' .
            'require ' . var_export($artisanScript, true) . ';';

        $process = new Process([
            'php',
            '-d',
            'session.save_path=' . $sessionDir,
            '-r',
            $phpCode,
        ], $projectPath);
        $process->setTimeout(300);
        $process->setEnv($this->buildProcessEnv());
        $process->setEnv([
            ...$this->buildProcessEnv(),
            ...$this->buildDatabaseEnv($projectPath, $options['database'] ?? []),
        ]);

        try {
            $buffers = [
                Process::OUT => '',
                Process::ERR => '',
            ];

            $process->run(function ($type, $buffer) use (&$buffers) {
                $buffers[$type] .= $buffer;

                while (($pos = strpos($buffers[$type], "\n")) !== false) {
                    $line = rtrim(substr($buffers[$type], 0, $pos), "\r");
                    $buffers[$type] = substr($buffers[$type], $pos + 1);

                    if ($line === '') {
                        continue;
                    }

                    $lower = strtolower($line);
                    $isErrorLine =
                        str_contains($lower, 'fatal error') ||
                        str_contains($lower, 'uncaught exception') ||
                        str_contains($lower, 'error:') ||
                        str_contains($lower, 'exception');

                    $this->tui->addLog($line, $isErrorLine ? 'error' : 'info');
                }
            });

            foreach ($buffers as $type => $tail) {
                $tail = trim($tail);
                if ($tail !== '') {
                    $lower = strtolower($tail);
                    $isErrorLine =
                        str_contains($lower, 'fatal error') ||
                        str_contains($lower, 'uncaught exception') ||
                        str_contains($lower, 'error:') ||
                        str_contains($lower, 'exception');

                    $this->tui->addLog($tail, $isErrorLine ? 'error' : 'info');
                }
            }

            if ($process->isSuccessful()) {
                $this->tui->addLog('Database migrations completed.', 'success');
                $this->assertDatabaseReadyForPackageDiscovery($projectPath, $options);
                $this->runPackageDiscovery($projectPath, $artisanScript);
            } else {
                $errorOutput = $process->getErrorOutput();
                throw new \RuntimeException("Migration failed: " . $errorOutput);
            }
        } catch (\Exception $e) {
            $this->tui->addLog('Migration/package discovery failed: ' . $e->getMessage(), 'error');
            throw new \RuntimeException("Failed to complete migrations or package discovery. Please run 'php core/artisan migrate' and 'php core/artisan package:discover' manually.");
        }
    }

    protected function patchPostgresInetDefaultsInMigrations(string $projectPath): void
    {
        $dirs = [
            $projectPath . '/core/database/migrations',
            $projectPath . '/install/stubs/migrations',
        ];

        $totalReplacements = 0;
        $touchedFiles = 0;

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = glob($dir . '/*.php') ?: [];
            foreach ($files as $file) {
                $original = @file_get_contents($file);
                if (!is_string($original) || $original === '') {
                    continue;
                }

                $patched = $original;
                $count = 0;

                // PostgreSQL uses the `inet` type for Laravel's `ipAddress()` / `inet()`.
                // An empty-string default is invalid for `inet` and breaks migrations.
                $patched = preg_replace(
                    '/(->(?:ipAddress|inet)\\s*\\([^;]*?)->default\\(\\s*(?:\'\'|"")\\s*\\)/s',
                    '$1',
                    $patched,
                    -1,
                    $count
                );

                if (!is_string($patched) || $count <= 0 || $patched === $original) {
                    continue;
                }

                if (is_file($file) && !is_writable($file)) {
                    @chmod($file, 0644);
                }

                if (@file_put_contents($file, $patched) !== false) {
                    $totalReplacements += $count;
                    $touchedFiles++;
                }
            }
        }

        if ($totalReplacements > 0) {
            $this->tui->addLog(
                "PostgreSQL compatibility: removed {$totalReplacements} empty inet default(s) in {$touchedFiles} migration file(s).",
                'warning'
            );
        }
    }

    protected function runPackageDiscovery(string $projectPath, string $artisanScript): void
    {
        $this->tui->addLog('Running artisan package:discover...');

        $sessionDir = $projectPath . '/core/storage/sessions';
        if (!str_starts_with($artisanScript, 'core/')) {
            $sessionDir = $projectPath . '/storage/sessions';
        }
        @mkdir($sessionDir, 0755, true);

        $argv = ['artisan', 'package:discover', '--no-interaction', '--no-ansi'];
        $phpCode =
            'define("IN_INSTALL_MODE", true);' .
            'define("EVO_CLI", true);' .
            '$_SERVER["argv"]=' . var_export($argv, true) . ';' .
            '$_SERVER["argc"]=count($_SERVER["argv"]);' .
            'require ' . var_export($artisanScript, true) . ';';

        $process = new Process([
            'php',
            '-d',
            'session.save_path=' . $sessionDir,
            '-r',
            $phpCode,
        ], $projectPath);
        $process->setTimeout(300);

        $buffers = [
            Process::OUT => '',
            Process::ERR => '',
        ];

        $process->run(function ($type, $buffer) use (&$buffers) {
            $buffers[$type] .= $buffer;

            while (($pos = strpos($buffers[$type], "\n")) !== false) {
                $line = rtrim(substr($buffers[$type], 0, $pos), "\r");
                $buffers[$type] = substr($buffers[$type], $pos + 1);

                if ($line === '') {
                    continue;
                }

                $lower = strtolower($line);
                $isErrorLine =
                    str_contains($lower, 'fatal error') ||
                    str_contains($lower, 'uncaught exception') ||
                    str_contains($lower, 'error:') ||
                    str_contains($lower, 'exception');

                $this->tui->addLog($line, $isErrorLine ? 'error' : 'info');
            }
        });

        foreach ($buffers as $tail) {
            $tail = trim($tail);
            if ($tail !== '') {
                $this->tui->addLog($tail);
            }
        }

        if ($process->isSuccessful()) {
            $this->tui->replaceLastLogs('<fg=green>✔</> Package discovery completed.');
        } else {
            $fullOutput = trim($process->getOutput() . "\n" . $process->getErrorOutput());
            $this->tui->replaceLastLogs('<fg=red>✗</> Package discovery failed.');
            if ($fullOutput !== '') {
                $this->tui->addLog($fullOutput, 'error');
            }
            throw new \RuntimeException('Package discovery failed.');
        }
    }

    protected function assertDatabaseReadyForPackageDiscovery(string $projectPath, array $options): void
    {
        $this->tui->addLog('Database ready check...');

        $dbConfig = $options['database'] ?? [];
        if (!is_array($dbConfig) || $dbConfig === []) {
            throw new \RuntimeException('Database configuration missing for readiness check.');
        }

        $dbConfigForOps = $this->getDatabaseConfigForOperations($projectPath, $dbConfig);
        $type = (string) ($dbConfigForOps['type'] ?? '');
        $dbName = (string) ($dbConfigForOps['name'] ?? '');

        if ($type === 'sqlite') {
            if ($dbName === '') {
                throw new \RuntimeException('SQLite database path is empty.');
            }
            if ($dbName !== ':memory:' && !str_starts_with($dbName, 'file:') && !is_file($dbName)) {
                throw new \RuntimeException("SQLite database file not found: {$dbName}");
            }
        }

        $dbh = $this->createConnection($dbConfigForOps);
        $prefix = (string) ($dbConfigForOps['prefix'] ?? ($dbConfig['prefix'] ?? 'evo_'));
        $table = $prefix . 'system_settings';

        $tables = $this->listDatabaseTables($dbh);
        $tablesLower = array_map('strtolower', $tables);
        if (!in_array(strtolower($table), $tablesLower, true)) {
            throw new \RuntimeException("Database ready check failed: missing table {$table}");
        }

        $this->tui->addLog('Database ready check: OK', 'success');
    }

    /**
     * Create admin user.
     *
     * @param string $projectPath
     * @param array $options
     * @return void
     */
    protected function createAdminUser(string $projectPath, array $options): void
    {
        $this->tui->addLog('Creating admin user...');

        $adminConfig = $options['admin'];
        $username = $adminConfig['username'] ?? 'admin';
        $email = $adminConfig['email'] ?? 'admin@example.com';
        $password = $adminConfig['password'] ?? '';
        $adminDirectory = $adminConfig['directory'] ?? 'manager';

        // Check if artisan exists
        $artisanScript = null;
        foreach (['core/artisan', 'artisan'] as $candidate) {
            if (file_exists($projectPath . '/' . $candidate)) {
                $artisanScript = $candidate;
                break;
            }
        }

        if ($artisanScript !== null) {
            $sessionDir = $projectPath . '/core/storage/sessions';
            if (!str_starts_with($artisanScript, 'core/')) {
                $sessionDir = $projectPath . '/storage/sessions';
            }
            @mkdir($sessionDir, 0755, true);

            // Use artisan command to create admin user
            // Try common Evolution CMS artisan commands
            $commands = [
                [PHP_BINARY ?: 'php', '-d', 'session.save_path=' . $sessionDir, $artisanScript, 'evo:install', '--username', $username, '--email', $email, '--password', $password, '--admin-dir', $adminDirectory],
                [PHP_BINARY ?: 'php', '-d', 'session.save_path=' . $sessionDir, $artisanScript, 'evolution:install', '--username', $username, '--email', $email, '--password', $password, '--admin-dir', $adminDirectory],
            ];

            $success = false;
            $lastError = '';
            foreach ($commands as $cmd) {
                $process = new Process($cmd, $projectPath);
                $process->setTimeout(120);
                $process->setEnv($this->buildProcessEnv());

                try {
                    $process->run();
                    if ($process->isSuccessful()) {
                        $success = true;
                        break;
                    }
                    $lastError = trim($process->getOutput() . "\n" . $process->getErrorOutput());
                } catch (\Exception $e) {
                    // Try next command
                    $lastError = $e->getMessage();
                    continue;
                }
            }

            if ($success) {
                $this->tui->replaceLastLogs('<fg=green>✔</> Admin user created successfully.');
            } else {
                // Fallback: Create user directly in database
                if ($lastError !== '') {
                    $this->tui->addLog('Admin creation via artisan failed, falling back to direct DB insert.', 'warning');
                    $this->tui->addLog($lastError, 'warning');
                }
                $this->createAdminUserDirectly($projectPath, $options);
            }
        } else {
            // Fallback: Create user directly in database
            $this->createAdminUserDirectly($projectPath, $options);
        }
    }

    /**
     * Create admin user directly in database (fallback method).
     *
     * @param string $projectPath
     * @param array $options
     * @return void
     */
    protected function createAdminUserDirectly(string $projectPath, array $options): void
    {
        $this->tui->addLog('Creating admin user directly in the database...', 'info');

        try {
            $dbConfig = $options['database'];
            $adminConfig = $options['admin'];

            $username = $adminConfig['username'] ?? 'admin';
            $email = $adminConfig['email'] ?? 'admin@example.com';
            $password = $adminConfig['password'] ?? '';

            // Create database connection
            $dbh = $this->createConnection($this->getDatabaseConfigForOperations($projectPath, $dbConfig));

            [$usersTable, $tablePrefix] = $this->resolveUsersTableAndPrefix($dbh, $dbConfig['prefix'] ?? 'evo_');

            $hashedPassword = $this->hashAdminPasswordForTable($dbh, $usersTable, $password);

            // Check if user already exists
            $userColumns = $this->getTableColumns($dbh, $usersTable);

            $loginColumn = null;
            foreach (['username', 'name', 'login'] as $candidate) {
                if (in_array($candidate, $userColumns, true)) {
                    $loginColumn = $candidate;
                    break;
                }
            }
            if ($loginColumn === null) {
                throw new \RuntimeException("Users table {$usersTable} does not have a supported login column (expected username/name/login).");
            }

            $hasEmail = in_array('email', $userColumns, true);
            if ($hasEmail) {
                $stmt = $dbh->prepare("SELECT * FROM {$usersTable} WHERE ({$loginColumn} = :login OR email = :email)");
                $stmt->execute([':login' => $username, ':email' => $email]);
            } else {
                $stmt = $dbh->prepare("SELECT * FROM {$usersTable} WHERE {$loginColumn} = :login");
                $stmt->execute([':login' => $username]);
            }

            $existingUser = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

            $now = time();

            // If we didn't find by login/email and this looks like a fresh install (single user),
            // update that user instead of creating a second admin account.
            if (!is_array($existingUser)) {
                try {
                    $count = (int) ($dbh->query("SELECT COUNT(*) FROM {$usersTable}")?->fetchColumn() ?: 0);
                    if ($count === 1) {
                        $existingUser = $dbh->query("SELECT * FROM {$usersTable}")?->fetch(\PDO::FETCH_ASSOC) ?: null;
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            if (is_array($existingUser)) {
                $userKeyColumn = null;
                $userKeyValue = null;
                if (array_key_exists('internalKey', $existingUser) && in_array('internalKey', $userColumns, true)) {
                    $userKeyColumn = 'internalKey';
                    $userKeyValue = (int) ($existingUser['internalKey'] ?? 0);
                } elseif (array_key_exists('id', $existingUser) && in_array('id', $userColumns, true)) {
                    $userKeyColumn = 'id';
                    $userKeyValue = (int) ($existingUser['id'] ?? 0);
                }
                if ($userKeyColumn === null || $userKeyValue === null || $userKeyValue <= 0) {
                    throw new \RuntimeException('Unable to determine admin user primary key for update.');
                }

                $updateData = [
                    $loginColumn => $username,
                    'password' => $hashedPassword,
                    'updated_at' => date('Y-m-d H:i:s', $now),
                ];
                if ($hasEmail) {
                    $updateData['email'] = $email;
                }
                if (in_array('editedon', $userColumns, true)) {
                    $updateData['editedon'] = $now;
                }

                $this->updateRowByAvailableColumns($dbh, $usersTable, $userColumns, $userKeyColumn, $userKeyValue, $updateData);

                // Best-effort: ensure user_attributes exists (do not fail install if schema differs).
                try {
                    $this->ensureUserAttributes($dbh, $tablePrefix, $userKeyColumn, $userKeyValue, $username, $email, $now);
                } catch (\Throwable $e) {
                    // ignore
                }

                $this->verifyAdminCredentials($dbh, $usersTable, $loginColumn, $username, $password);
                $this->tui->replaceLastLogs('<fg=green>✔</> Admin user updated successfully.', 2);
                return;
            }

            $internalKey = null;
            if (in_array('internalKey', $userColumns, true)) {
                $max = $dbh->query("SELECT MAX(internalKey) FROM {$usersTable}")->fetchColumn();
                $internalKey = max(1, ((int) $max) + 1);
            }

            $insertData = [
                $loginColumn => $username,
                'password' => $hashedPassword,
                'createdon' => $now,
                'created_at' => date('Y-m-d H:i:s', $now),
                'updated_at' => date('Y-m-d H:i:s', $now),
            ];
            if ($hasEmail) {
                $insertData['email'] = $email;
            }
            if ($internalKey !== null) {
                $insertData['internalKey'] = $internalKey;
            }

            $this->insertRowByAvailableColumns($dbh, $usersTable, $userColumns, $insertData);

            $userKeyColumn = null;
            $userKeyValue = null;
            if ($internalKey !== null) {
                $userKeyColumn = 'internalKey';
                $userKeyValue = $internalKey;
            } elseif (in_array('id', $userColumns, true)) {
                $lastInsertId = $dbh->lastInsertId();
                if ($lastInsertId !== '' && $lastInsertId !== '0') {
                    $userKeyColumn = 'id';
                    $userKeyValue = (int) $lastInsertId;
                }
            }

            if ($userKeyColumn !== null && $userKeyValue !== null) {
                $this->ensureUserAttributes($dbh, $tablePrefix, $userKeyColumn, $userKeyValue, $username, $email, $now);
            }

            $this->verifyAdminCredentials($dbh, $usersTable, $loginColumn, $username, $password);
            $this->tui->replaceLastLogs('<fg=green>✔</> Admin user created successfully.', 2);
        } catch (\Exception $e) {
            $this->tui->replaceLastLogs('<fg=red>✗</> Could not create admin user automatically: ' . $e->getMessage(), 2);
            throw $e;
        }
    }

    /**
     * @param array<string> $columns
     * @param array<string, mixed> $data
     */
    protected function updateRowByAvailableColumns(\PDO $dbh, string $table, array $columns, string $keyColumn, int $keyValue, array $data): void
    {
        $setParts = [];
        $params = [':k' => $keyValue];

        foreach ($data as $col => $value) {
            if (!in_array($col, $columns, true)) {
                continue;
            }
            $setParts[] = "{$col} = :{$col}";
            $params[":{$col}"] = $value;
        }

        if ($setParts === []) {
            return;
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $setParts) . " WHERE {$keyColumn} = :k";
        $stmt = $dbh->prepare($sql);
        $stmt->execute($params);
    }

    protected function verifyAdminCredentials(\PDO $dbh, string $usersTable, string $loginColumn, string $username, string $plainPassword): void
    {
        $stmt = $dbh->prepare("SELECT {$loginColumn} AS login, password FROM {$usersTable} WHERE {$loginColumn} = :login");
        $stmt->execute([':login' => $username]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException('Admin user verification failed: user not found after update.');
        }

        $stored = (string) ($row['password'] ?? '');
        if (!$this->verifyPasswordAgainstStoredHash($plainPassword, $stored)) {
            throw new \RuntimeException('Admin user verification failed: password does not match stored hash.');
        }
    }

    protected function verifyPasswordAgainstStoredHash(string $plain, string $stored): bool
    {
        $stored = trim($stored);
        if ($stored === '') {
            return false;
        }
        if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2a$') || str_starts_with($stored, '$argon2')) {
            return password_verify($plain, $stored);
        }
        if (preg_match('/^[a-f0-9]{32}$/i', $stored) === 1) {
            return md5($plain) === strtolower($stored);
        }
        // Fallback: treat as plaintext (shouldn't happen, but don't block installation unexpectedly).
        return $plain === $stored;
    }

    /**
     * Resolve manager users table name and actual prefix.
     *
     * @return array{0:string,1:string} [$usersTable, $prefix]
     */
    protected function resolveUsersTableAndPrefix(\PDO $dbh, string $preferredPrefix): array
    {
        $suffixes = ['users'];
        $tables = $this->listDatabaseTables($dbh);

        $candidates = [];
        foreach ($suffixes as $suffix) {
            $candidates[] = $preferredPrefix . $suffix;
            $candidates[] = $suffix;
        }

        foreach ($tables as $table) {
            foreach ($suffixes as $suffix) {
                if (str_ends_with($table, $suffix)) {
                    $candidates[] = $table;
                }
            }
        }

        $candidates = array_values(array_unique(array_filter($candidates, static fn($t) => is_string($t) && $t !== '')));

        $best = null;
        $bestScore = -1;
        foreach ($candidates as $candidate) {
            if (!in_array($candidate, $tables, true)) {
                continue;
            }

            $columns = $this->getTableColumns($dbh, $candidate);
            $hasLoginColumn = in_array('username', $columns, true) || in_array('name', $columns, true) || in_array('login', $columns, true);
            if (!$hasLoginColumn || !in_array('password', $columns, true)) {
                continue;
            }

            $score = 0;
            if ($candidate === $preferredPrefix . 'users') {
                $score += 90;
            } elseif ($candidate === 'users') {
                $score += 70;
            }

            if (in_array('email', $columns, true)) {
                $score += 5;
            }
            if (in_array('internalKey', $columns, true)) {
                $score += 5;
            }
            if (in_array('createdon', $columns, true) || in_array('created_at', $columns, true)) {
                $score += 2;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        if ($best === null) {
            throw new \RuntimeException('Could not find users table.');
        }

        foreach ($suffixes as $suffix) {
            if (str_ends_with($best, $suffix)) {
                return [$best, substr($best, 0, -strlen($suffix))];
            }
        }

        return [$best, $preferredPrefix];
    }

    protected function hashAdminPasswordForTable(\PDO $dbh, string $usersTable, string $password): string
    {
        $columns = $this->getTableColumns($dbh, $usersTable);

        // Prefer matching the existing schema's password hashing when possible.
        try {
            $driver = $dbh->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $sql = match ($driver) {
                'sqlsrv' => "SELECT TOP 1 password FROM {$usersTable} WHERE password IS NOT NULL AND password <> ''",
                default => "SELECT password FROM {$usersTable} WHERE password IS NOT NULL AND password <> '' LIMIT 1",
            };
            $existing = $dbh->query($sql)?->fetchColumn();
            if (is_string($existing) && trim($existing) !== '') {
                $existing = trim($existing);
                if (str_starts_with($existing, '$2y$') || str_starts_with($existing, '$2a$') || str_starts_with($existing, '$argon2')) {
                    return password_hash($password, PASSWORD_BCRYPT);
                }
                if (preg_match('/^[a-f0-9]{32}$/i', $existing) === 1) {
                    return md5($password);
                }
            }
        } catch (\Throwable $e) {
            // ignore and fall back to heuristics
        }

        if (in_array('internalKey', $columns, true) || in_array('createdon', $columns, true)) {
            return md5($password);
        }

        if (in_array('remember_token', $columns, true) || in_array('email_verified_at', $columns, true) || in_array('created_at', $columns, true)) {
            return password_hash($password, PASSWORD_BCRYPT);
        }

        return md5($password);
    }

    protected function listDatabaseTables(\PDO $dbh): array
    {
        $driver = $dbh->getAttribute(\PDO::ATTR_DRIVER_NAME);

        return match ($driver) {
            'mysql' => array_map(
                static fn(array $row) => (string) reset($row),
                $dbh->query('SHOW TABLES')->fetchAll(\PDO::FETCH_NUM)
            ),
            'pgsql' => array_map(
                static fn(array $row) => (string) $row[0],
                $dbh->query("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema')")->fetchAll(\PDO::FETCH_NUM)
            ),
            'sqlite' => array_map(
                static fn(array $row) => (string) $row[0],
                $dbh->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_NUM)
            ),
            'sqlsrv' => array_map(
                static fn(array $row) => (string) $row[0],
                $dbh->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'")->fetchAll(\PDO::FETCH_NUM)
            ),
            default => [],
        };
    }

    /**
     * @return string[]
     */
    protected function getTableColumns(\PDO $dbh, string $table): array
    {
        $stmt = $dbh->query("SELECT * FROM {$table} WHERE 1=0");
        $columns = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $meta = $stmt->getColumnMeta($i);
            if (is_array($meta) && isset($meta['name']) && is_string($meta['name']) && $meta['name'] !== '') {
                $columns[] = $meta['name'];
            }
        }

        if ($columns !== []) {
            return $columns;
        }

        $driver = $dbh->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $fallback = match ($driver) {
            'mysql' => $dbh->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'),
            'pgsql' => $dbh->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = :t"),
            'sqlsrv' => $dbh->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :t'),
            default => null,
        };

        if ($fallback !== null) {
            $fallback->execute([':t' => $table]);
            return array_map(static fn($r) => (string) $r[0], $fallback->fetchAll(\PDO::FETCH_NUM));
        }

        if ($driver === 'sqlite') {
            $rows = $dbh->query('PRAGMA table_info(' . $table . ')')->fetchAll(\PDO::FETCH_ASSOC);
            $cols = [];
            foreach ($rows as $row) {
                if (isset($row['name'])) {
                    $cols[] = (string) $row['name'];
                }
            }
            return $cols;
        }

        return [];
    }

    /**
     * Insert row using only columns that exist in the target table.
     *
     * @param array<string, mixed> $data
     */
    protected function insertRowByAvailableColumns(\PDO $dbh, string $table, array $columns, array $data): void
    {
        $insertColumns = [];
        $params = [];
        foreach ($data as $col => $value) {
            if (!in_array($col, $columns, true)) {
                continue;
            }
            $insertColumns[] = $col;
            $params[':' . $col] = $value;
        }

        if ($insertColumns === []) {
            throw new \RuntimeException("No writable columns found for insert into {$table}.");
        }

        $placeholders = array_map(static fn(string $c) => ':' . $c, $insertColumns);
        $sql = "INSERT INTO {$table} (" . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $dbh->prepare($sql);
        $stmt->execute($params);
    }

    protected function ensureUserAttributes(\PDO $dbh, string $prefix, string $userKeyColumn, int $userKeyValue, string $username, string $email, int $now): void
    {
        $candidateTables = [
            $prefix . 'user_attributes',
        ];

        $tables = $this->listDatabaseTables($dbh);
        $attributesTable = null;
        foreach ($candidateTables as $candidate) {
            if (in_array($candidate, $tables, true)) {
                $attributesTable = $candidate;
                break;
            }
        }

        if ($attributesTable === null) {
            return;
        }

        $columns = $this->getTableColumns($dbh, $attributesTable);
        $linkColumn = null;

        if ($userKeyColumn === 'id') {
            // Evolution CMS classic schema links user_attributes.internalKey -> users.id.
            // Do NOT default to "id" when it exists: it's typically the PK of user_attributes itself.
            foreach (['internalKey', 'user_id', 'userId', 'userid', 'id'] as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    $linkColumn = $candidate;
                    break;
                }
            }
        } elseif (in_array($userKeyColumn, $columns, true)) {
            $linkColumn = $userKeyColumn;
        }
        if ($linkColumn === null) {
            return;
        }

        $stmt = $dbh->prepare("SELECT * FROM {$attributesTable} WHERE {$linkColumn} = :k");
        $stmt->execute([':k' => $userKeyValue]);
        if ($stmt->fetch()) {
            return;
        }

        $roleId = 1;
        $rolesTable = $prefix . 'user_roles';
        if (in_array($rolesTable, $tables, true)) {
            try {
                $roleStmt = $dbh->query("SELECT MIN(id) FROM {$rolesTable}");
                $roleId = (int) ($roleStmt->fetchColumn() ?: 1);
            } catch (\Throwable $e) {
                // Keep default role id
            }
        }

        $data = [
            $linkColumn => $userKeyValue,
            'fullname' => $username,
            'email' => $email,
            'role' => $roleId,
            'createdon' => $now,
            'created_at' => date('Y-m-d H:i:s', $now),
            'updated_at' => date('Y-m-d H:i:s', $now),
        ];

        $this->insertRowByAvailableColumns($dbh, $attributesTable, $columns, $data);
    }

    /**
     * Install preset.
     */
    protected function installPreset(?string $preset): void
    {
        $this->steps['presets']['completed'] = true;
        $this->tui->setQuestTrack($this->steps);

        if ($preset === null) {
            return;
        }
    }

    /**
     * Install/update dependencies with Composer.
     */
    protected function installDependencies(string $name, array $options): void
    {
        $this->tui->addLog('Updating dependencies with Composer...');

        $isCurrentDir = !empty($options['install_in_current_dir']);
        $projectPath = $isCurrentDir ? $name : (getcwd() . '/' . $name);

        $composerWorkDir = is_file($projectPath . '/core/composer.json') ? ($projectPath . '/core') : $projectPath;
        $composerJson = $composerWorkDir . '/composer.json';

        if (!file_exists($composerJson)) {
            $this->tui->addLog('composer.json not found. Skipping dependency update.', 'warning');
            $this->steps['dependencies']['completed'] = true;
            $this->tui->setQuestTrack($this->steps);
            return;
        }

        $composerCommand = $this->resolveComposerCommand($composerWorkDir);

        try {
            $process = $this->runComposer($composerCommand, ['update', '--no-dev', '--prefer-dist', '--no-scripts'], $composerWorkDir);

            if ($process->isSuccessful()) {
                $this->tui->addLog('Dependencies updated successfully.', 'success');
                $this->steps['dependencies']['completed'] = true;
                $this->tui->setQuestTrack($this->steps);
                return;
            }

            $fullOutput = $this->sanitizeComposerOutput($process->getOutput() . "\n" . $process->getErrorOutput());
            throw new \RuntimeException(trim($fullOutput));
        } catch (\Exception $e) {
            $this->tui->addLog('Failed to update dependencies: ' . $e->getMessage(), 'error');
            throw new \RuntimeException("Failed to update dependencies. Please run 'composer update' manually.");
        }
    }

    protected function getDatabaseConfigForOperations(string $projectPath, array $dbConfig): array
    {
        if (($dbConfig['type'] ?? null) !== 'sqlite') {
            return $dbConfig;
        }

        $name = (string) ($dbConfig['name'] ?? '');
        if ($name === '' || $name === ':memory:' || str_starts_with($name, 'file:')) {
            return $dbConfig;
        }

        $isAbsoluteUnix = str_starts_with($name, '/');
        $isAbsoluteWindows = (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $name);
        if ($isAbsoluteUnix || $isAbsoluteWindows) {
            return $dbConfig;
        }

        $dbConfig['name'] = rtrim($projectPath, '/\\') . DIRECTORY_SEPARATOR . $name;
        return $dbConfig;
    }

    /**
     * Detect base URL for the installed application.
     *
     * @param string $projectName
     * @param bool $installInCurrentDir
     * @return string
     */
    protected function detectBaseUrl(string $projectName, bool $installInCurrentDir): string
    {
        // Try to detect from server environment variables (web request)
        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'];

            // Remove port if it's standard (80 for http, 443 for https)
            $port = $_SERVER['SERVER_PORT'] ?? null;
            if ($port && $port != 80 && $port != 443) {
                return "{$protocol}{$host}:{$port}";
            }

            return "{$protocol}{$host}";
        }

        // Try SERVER_NAME as fallback
        if (isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            return "{$protocol}{$_SERVER['SERVER_NAME']}";
        }

        // Try to detect from current directory or project name
        if ($installInCurrentDir) {
            $currentDir = basename(getcwd());
            if ($currentDir && $currentDir !== '.' && $currentDir !== '..') {
                // Common local development patterns
                if (strpos($currentDir, '.local') !== false || strpos($currentDir, '.test') !== false) {
                    return "http://{$currentDir}";
                }
            }
        } else {
            // Use project name as subdirectory if it looks like a domain
            if ($projectName && (strpos($projectName, '.local') !== false || strpos($projectName, '.test') !== false)) {
                return "http://{$projectName}";
            }
        }

        // Default fallback
        return 'http://localhost';
    }

    /**
     * Remove directory recursively.
     *
     * @param string $dir
     * @return void
     */
    /**
     * Finalize installation: clean up installation files and directories.
     */
    protected function finalizeInstallation(string $name, array $options): void
    {
        $this->tui->addLog('Finalizing installation...');

        $isCurrentDir = !empty($options['install_in_current_dir']);
        $projectPath = $isCurrentDir ? $name : (getcwd() . '/' . $name);

        $this->removeInstallerAdminEnvVars($projectPath);
        $this->removeCoreCustomExampleFiles($projectPath);
        $this->applyManagerDirectory($projectPath, $options);

        // Remove install directory
        $installDir = $projectPath . '/install';
        if (is_dir($installDir)) {
            $this->removeDirectory($installDir);
            $this->tui->addLog('Removed install directory.', 'info');
        }

        // Remove localization tooling dir (not needed in production)
        $txDir = $projectPath . '/.tx';
        if (is_dir($txDir)) {
            $this->removeDirectory($txDir);
            $this->tui->addLog('Removed .tx directory.', 'info');
        }

        // Remove composer.json from root (not the one in core/)
        $rootComposerJson = $projectPath . '/composer.json';
        if (file_exists($rootComposerJson) && !is_dir($rootComposerJson)) {
            @unlink($rootComposerJson);
            $this->tui->addLog('Removed root composer.json.', 'info');
        }

        // Remove repository-only files from project root
        foreach ([
            '.gitattributes',
            'LICENSE',
            'ng.inx',
            'README.md',
        ] as $file) {
            $path = $projectPath . '/' . $file;
            if (file_exists($path) && !is_dir($path)) {
                @unlink($path);
                $this->tui->addLog("Removed {$file}.", 'info');
            }
        }

        // Remove config.php.example
        $configExample = $projectPath . '/config.php.example';
        if (file_exists($configExample)) {
            @unlink($configExample);
            $this->tui->addLog('Removed config.php.example.', 'info');
        }

        // Rename sample-robots.txt to robots.txt
        $sampleRobots = $projectPath . '/sample-robots.txt';
        $robotsTxt = $projectPath . '/robots.txt';
        if (file_exists($sampleRobots)) {
            if (file_exists($robotsTxt)) {
                @unlink($robotsTxt);
            }
            if (@rename($sampleRobots, $robotsTxt)) {
                $this->tui->addLog('Renamed sample-robots.txt to robots.txt.', 'info');
            }
        }

        // Rename ht.access to .htaccess
        $htaccessSource = $projectPath . '/ht.access';
        $htaccessTarget = $projectPath . '/.htaccess';
        if (file_exists($htaccessSource)) {
            if (file_exists($htaccessTarget)) {
                @unlink($htaccessTarget);
            }
            if (@rename($htaccessSource, $htaccessTarget)) {
                $this->tui->addLog('Renamed ht.access to .htaccess.', 'info');
            } else {
                $this->tui->addLog('Failed to rename ht.access to .htaccess.', 'warning');
            }
        }

        // Clean up seeders directory (remove files inside, keep directory)
        $seedersDir = $projectPath . '/core/database/seeders';
        if (is_dir($seedersDir)) {
            $files = array_diff(scandir($seedersDir), ['.', '..']);
            $removedCount = 0;
            foreach ($files as $file) {
                $filePath = $seedersDir . '/' . $file;
                if (is_file($filePath)) {
                    @unlink($filePath);
                    $removedCount++;
                } elseif (is_dir($filePath)) {
                    $this->removeDirectory($filePath);
                    $removedCount++;
                }
            }
            if ($removedCount > 0) {
                $this->tui->addLog("Removed {$removedCount} file(s) from seeders directory.", 'info');
            }
        }

        // Create installation marker with current unix timestamp
        $installMarker = $projectPath . '/core/.install';
        $timestamp = (string) time() . "\n";
        if (@file_put_contents($installMarker, $timestamp) === false) {
            $this->tui->addLog('Failed to create installation marker: core/.install', 'error');
            throw new \RuntimeException('Failed to create installation marker file core/.install.');
        }
        @chmod($installMarker, 0644);
        $this->tui->addLog('Created installation marker: core/.install', 'info');

        $this->steps['finalize']['completed'] = true;
        $this->tui->setQuestTrack($this->steps);
        $this->tui->addLog('Installation finalized successfully.', 'success');
    }

    protected function applyManagerDirectory(string $projectPath, array $options): void
    {
        $adminDir = $this->sanitizeAdminDirectory($options['admin']['directory'] ?? null);
        if ($adminDir === 'manager') {
            return;
        }

        $source = rtrim($projectPath, '/\\') . DIRECTORY_SEPARATOR . 'manager';
        $target = rtrim($projectPath, '/\\') . DIRECTORY_SEPARATOR . $adminDir;

        if (is_dir($target)) {
            if (is_dir($source)) {
                $this->tui?->addLog("Cannot rename manager directory: target already exists: {$target}", 'warning');
            }
            return;
        }
        if (!is_dir($source)) {
            $this->tui?->addLog("Admin directory rename skipped: source directory not found: {$source}", 'warning');
            return;
        }

        if (@rename($source, $target)) {
            $this->tui?->addLog("Renamed manager directory to {$adminDir}.", 'info');
            return;
        }

        $this->tui?->addLog("Failed to rename manager directory to {$adminDir}.", 'warning');
    }

    /**
     * Removes all `*.example` files from `core/custom` (best-effort).
     *
     * Installer may place example configs in `core/custom`. These files should not remain in production.
     */
    protected function removeCoreCustomExampleFiles(string $projectPath): void
    {
        $customDir = rtrim($projectPath, '/') . '/core/custom';
        if (!is_dir($customDir)) {
            return;
        }

        $removed = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($customDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }
            $path = $item->getPathname();
            if (str_ends_with($path, '.example')) {
                if (@unlink($path)) {
                    $removed++;
                }
            }
        }

        if ($removed > 0) {
            $this->tui?->addLog("Removed {$removed} .example file(s) from core/custom.", 'info');
        }
    }

    /**
     * Removes temporary installer-only admin seed variables from `.env` files.
     *
     * The installer uses `EVO_ADMIN_USERNAME`, `EVO_ADMIN_EMAIL`, `EVO_ADMIN_PASSWORD`
     * to seed the initial manager user. These values should not remain in `.env`
     * after installation.
     */
    protected function removeInstallerAdminEnvVars(string $projectPath): void
    {
        $keys = ['EVO_ADMIN_USERNAME', 'EVO_ADMIN_EMAIL', 'EVO_ADMIN_PASSWORD'];
        $envFiles = [
            $projectPath . '/.env',
            $projectPath . '/core/custom/.env',
        ];

        foreach ($envFiles as $envFile) {
            $this->removeEnvKeysFromFile($envFile, $keys);
        }

        // If env cache exists, remove it so it can be rebuilt without the installer-only vars.
        $envCache = $projectPath . '/core/storage/cache/env.php';
        if (is_file($envCache)) {
            @unlink($envCache);
        }
    }

    /**
     * Removes specified keys from a dotenv file (best-effort, atomic write).
     *
     * @param string $envFile
     * @param array<int, string> $keys
     * @return void
     */
    protected function removeEnvKeysFromFile(string $envFile, array $keys): void
    {
        if (!is_file($envFile) || !is_readable($envFile)) {
            return;
        }

        $original = (string) @file_get_contents($envFile);
        if ($original === '') {
            return;
        }

        $lines = preg_split('/\\R/', $original);
        if (!is_array($lines)) {
            return;
        }

        $patterns = array_map(
            static fn(string $k): string => '/^\\s*(?:export\\s+)?' . preg_quote($k, '/') . '\\s*=.*$/',
            $keys
        );

        $changed = false;
        $filtered = [];
        foreach ($lines as $line) {
            $remove = false;
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $remove = true;
                    $changed = true;
                    break;
                }
            }
            if (!$remove) {
                $filtered[] = $line;
            }
        }

        if (!$changed) {
            return;
        }

        $content = implode("\n", $filtered);
        if ($content !== '' && !str_ends_with($content, "\n")) {
            $content .= "\n";
        }

        $tmp = $envFile . '.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            $this->tui?->addLog("Failed to update env file (installer cleanup): {$envFile}", 'warning');
            @unlink($tmp);
            return;
        }

        if (!@rename($tmp, $envFile)) {
            $this->tui?->addLog("Failed to replace env file (installer cleanup): {$envFile}", 'warning');
            @unlink($tmp);
            return;
        }
    }

    /**
     * Set stty mode safely (handle disabled system() function).
     */
    protected function setSttyMode(string $mode): void
    {
        if (!function_exists('shell_exec')) {
            return;
        }

        $disabled = ini_get('disable_functions');
        if ($disabled && stripos($disabled, 'shell_exec') !== false) {
            return;
        }

        // Use shell_exec instead of system to avoid output issues
        // For SSH, we need to ensure stty works with the terminal
        if (function_exists('posix_isatty') && !@posix_isatty(STDIN)) {
            // Not a TTY, stty won't work
            return;
        }

        $mode = trim($mode);
        if ($mode === '') {
            return;
        }

        $normalized = $this->normalizeSttyMode($mode);
        $modeTokens = preg_split('/\\s+/', $normalized) ?: [];
        $modeArgs = implode(' ', array_map('escapeshellarg', $modeTokens));

        // Set stty mode and ensure it takes effect
        @shell_exec('stty ' . $modeArgs . ' < /dev/tty 2>/dev/null');
        // Also try without /dev/tty redirect (for some SSH setups)
        @shell_exec('stty ' . $modeArgs . ' 2>/dev/null');
    }

    protected function normalizeSttyMode(string $mode): string
    {
        $mode = trim($mode);
        if ($mode === '') {
            return $mode;
        }

        $tokens = preg_split('/\\s+/', $mode) ?: [];
        $hasIcanon = in_array('-icanon', $tokens, true);
        if (!$hasIcanon) {
            return implode(' ', $tokens);
        }

        // Ensure reads block in non-canonical mode (prevents busy-loop rendering on some systems).
        $hasMin = in_array('min', $tokens, true);
        $hasTime = in_array('time', $tokens, true);
        if (!$hasMin) {
            $tokens[] = 'min';
            $tokens[] = '1';
        }
        if (!$hasTime) {
            $tokens[] = 'time';
            $tokens[] = '0';
        }

        return implode(' ', $tokens);
    }

    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
