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
use GuzzleHttp\TransferStats;
use PDOException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AllowDynamicProperties]
class NewCommand extends Command
{
    use ConfiguresDatabase;

    protected ?OutputInterface $logSection = null;
    protected ?TuiRenderer $tui = null;
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
            ->setName('new')
            ->setDescription('Create a new Evolution CMS application')
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
            ->addOption('branch', null, InputOption::VALUE_OPTIONAL, 'Install from specific Git branch (e.g., develop, nightly, main) instead of latest release')
            ->addOption('language', null, InputOption::VALUE_OPTIONAL, 'The installation language')
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

        $this->tui->addLog("Evolution CMS application ready! Build something amazing.", 'success');

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
        $os = SystemInfo::getOS();
        $phpVersion = SystemInfo::getPhpVersion();
        $composerVersion = SystemInfo::getComposerVersion();
        $diskFree = SystemInfo::getDiskFreeSpace();
        $memoryLimit = SystemInfo::getMemoryLimit();

        $phpOk = version_compare($phpVersion, '8.3.0', '>=');
        $pdoOk = SystemInfo::hasExtension('pdo');
        $jsonOk = SystemInfo::hasExtension('json');
        $mysqliOk = SystemInfo::hasExtension('mysqli');
        $mbstringOk = SystemInfo::hasExtension('mbstring');
        $composerOk = $composerVersion !== null;

        // Check available PDO drivers
        $availablePdoDrivers = \PDO::getAvailableDrivers();
        $pdoSqliteOk = in_array('sqlite', $availablePdoDrivers);
        $pdoMysqlOk = in_array('mysql', $availablePdoDrivers);
        $pdoPgsqlOk = in_array('pgsql', $availablePdoDrivers);
        $pdoSqlsrvOk = in_array('sqlsrv', $availablePdoDrivers);

        // Additional extension checks
        $curlOk = SystemInfo::hasExtension('curl');
        $gdOk = SystemInfo::hasExtension('gd');
        $imagickOk = SystemInfo::hasExtension('imagick');
        $imageExtensionOk = $gdOk || $imagickOk; // At least one image extension

        return [
            ['label' => $os, 'status' => true],
            ['label' => "PHP - {$phpVersion}", 'status' => $phpOk],
            ['label' => "Composer" . ($composerVersion ? " - {$composerVersion}" : ''), 'status' => $composerOk],
            ['label' => 'PDO extension', 'status' => $pdoOk],
            ['label' => 'PDO MySQL driver', 'status' => $pdoMysqlOk, 'warning' => !$pdoMysqlOk],
            ['label' => 'PDO PostgreSQL driver', 'status' => $pdoPgsqlOk, 'warning' => !$pdoPgsqlOk],
            ['label' => 'PDO SQLite driver', 'status' => $pdoSqliteOk, 'warning' => !$pdoSqliteOk],
            ['label' => 'PDO SQL Server driver', 'status' => $pdoSqlsrvOk, 'warning' => !$pdoSqlsrvOk],
            ['label' => 'JSON extension', 'status' => $jsonOk],
            ['label' => 'MySQLi extension', 'status' => $mysqliOk],
            ['label' => 'MBString extension', 'status' => $mbstringOk],
            ['label' => 'cURL extension', 'status' => $curlOk],
            ['label' => 'Image extension', 'status' => $imageExtensionOk, 'warning' => !$imageExtensionOk],
            ['label' => 'Disk free - ' . ($diskFree ?: 'Unknown'), 'status' => $diskFree !== null],
            ['label' => 'Memory limit - ' . ($memoryLimit ?: 'Unknown'), 'status' => true],
        ];
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
        $inputs['admin']['directory'] = ($input->getOption('admin-directory') !== null) ? $input->getOption('admin-directory') : $this->askAdminDirectory();
        $inputs['language'] = ($input->getOption('language') !== null) ? $input->getOption('language') : $this->askLanguage();

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
        system('stty -icanon -echo');
        try {
            while (true) {
                $key = fread(STDIN, 3);

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
            system('stty sane');
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
                return false;
            }

            if ($type === 'sqlite') {
                // SQLite: Connect directly to the database file
                if (empty($config['name'])) {
                    $this->tui->replaceLastLogs('<fg=red>✗</> Database connection failed: SQLite database path is required.', 2);
                    return false;
                }
                $this->createConnection($config);
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

        // Display question in cyan without icon
        $this->tui->addLog('Would you like to try again or exit installation?', 'ask');

        // Display initial radio options (force render to avoid throttle)
        $radioText = $this->tui->renderRadio($options, $active);
        $this->tui->addLog($radioText);

        // raw input
        system('stty -icanon -echo');
        try {
            while (true) {
                $key = fread(STDIN, 3);

                switch ($key) {
                    case "\033[D": // ←
                    case "\033[A": // ↑
                        $active = max(0, $active - 1) ? true : false;
                        break;

                    case "\033[C": // →
                    case "\033[B": // ↓
                        $active = min(count($options) - 1, $active + 1) ? true : false;
                        break;

                    case "\n": // Enter
                        $active
                            ? $this->tui->replaceLastLogs('Reinput DB connections.', 2)
                            : $this->tui->replaceLastLogs('Exit ...', 3);
                        return $active;
                }

                $this->tui->replaceLastLog($this->tui->renderRadio($options, $active));
            }
        } finally {
            system('stty sane');
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

        // Validate directory name (only alphanumeric, hyphen, underscore allowed)
        $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $answer);
        if (empty($sanitized)) {
            $sanitized = 'manager';
        }

        $this->tui->replaceLastLogs('<fg=green>✔</> Your Admin directory: ' . $sanitized . '.', 2);
        return $sanitized ?: 'manager';
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
        system('stty -icanon -echo');
        try {
            while (true) {
                $key = fread(STDIN, 3);

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
            system('stty sane');
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

        // If branch is specified, download from branch
        if ($branch !== null) {
            $branch = trim($branch);
            $this->tui->addLog("Downloading Evolution CMS from branch: {$branch}...");

            $downloadUrl = $versionResolver->getBranchDownloadUrl($branch);
            $displayName = $branch;
        } else {
            // Get latest compatible version
            $this->tui->addLog('Finding compatible Evolution CMS version...');
            $phpVersion = PHP_VERSION;
            $version = $versionResolver->getLatestCompatibleVersion($phpVersion, true);

            if (!$version) {
                $this->tui->replaceLastLogs('<fg=red>✗</> Could not find a compatible Evolution CMS version for PHP ' . $phpVersion . '.', 2);
                throw new \RuntimeException("Could not find a compatible Evolution CMS version for PHP {$phpVersion}.");
            }

            // Remove 'v' prefix if present
            $versionTag = ltrim($version, 'v');
            $this->tui->replaceLastLogs('<fg=green>✔</> Found compatible version: ' . $version . '.', 2);
            $this->tui->addLog("Downloading Evolution CMS {$versionTag}...");

            $downloadUrl = $versionResolver->getDownloadUrl($version);
            $displayName = $versionTag;
        }

        // Create temp file for download
        $tempFile = sys_get_temp_dir() . '/evo-installer-' . uniqid() . '.zip';

        try {
            // Download with progress
            $this->downloadFile($downloadUrl, $tempFile);

            // Extract archive
            $this->tui->addLog('Extracting archive...');
            $this->extractZip($tempFile, $targetPath);

            // Clean up
            @unlink($tempFile);

            $sourceLabel = $branch ? "branch {$branch}" : "version {$displayName}";
            $this->tui->addLog("Evolution CMS from {$sourceLabel} downloaded and extracted successfully!", 'success');

            // Mark Step 3 (Download Evolution CMS) as completed
            $this->steps['download']['completed'] = true;
            $this->tui->setQuestTrack($this->steps);
        } catch (\Exception $e) {
            // Clean up on error
            @unlink($tempFile);
            $this->tui->addLog("Failed to download Evolution CMS: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    /**
     * Download file with progress bar.
     */
    protected function downloadFile(string $url, string $destination): void
    {
        $client = new Client(['timeout' => 300]);

        // First, get content length for progress tracking
        $totalBytes = 0;
        try {
            $headResponse = $client->head($url, ['allow_redirects' => true]);
            $totalBytes = (int) $headResponse->getHeaderLine('Content-Length');
        } catch (\Exception $e) {
            // If HEAD fails, we'll track progress from GET request
        }

        $downloadedBytes = 0;
        $lastUpdate = 0;

        // Download with on_stats callback for progress tracking
        $response = $client->get($url, [
            'sink' => $destination,
            'on_stats' => function (TransferStats $stats) use (&$downloadedBytes, &$lastUpdate, $totalBytes) {
                $downloadedBytes = $stats->getHandlerStat('size_download') ?: 0;
                $totalSize = $totalBytes > 0 ? $totalBytes : ($stats->getHandlerStat('size_download') ?: 0);

                // Update progress every 100KB or every second
                $now = microtime(true);
                if ($totalSize > 0 && ($downloadedBytes - $lastUpdate > 100000 || $now - ($lastUpdate / 1000000) > 1)) {
                    $this->tui->updateProgress('Downloading', $downloadedBytes, $totalSize);
                    $lastUpdate = $downloadedBytes;
                }
            },
        ]);

        // Final progress update
        if ($totalBytes > 0) {
            $this->tui->updateProgress('Downloading', $totalBytes, $totalBytes);
        } else {
            $actualSize = (int) $response->getHeaderLine('Content-Length');
            if ($actualSize > 0) {
                $this->tui->updateProgress('Downloading', $actualSize, $actualSize);
            }
        }

        $this->tui->replaceLastLogs('<fg=green>✔</> Download completed.');
    }

    /**
     * Extract ZIP archive.
     */
    protected function extractZip(string $zipFile, string $destination): void
    {
        if (!extension_loaded('zip')) {
            throw new \RuntimeException('ZIP extension is required to extract the archive.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException("Failed to open ZIP archive: {$zipFile}");
        }

        // Create destination directory if it doesn't exist
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        // Extract all files
        $totalFiles = $zip->numFiles;
        $extractedFiles = 0;

        for ($i = 0; $i < $totalFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            // Skip directory entries
            if (substr($filename, -1) === '/') {
                continue;
            }

            // Remove source prefix from path (e.g., "evolution-3.5.0/" or "evolution-branch-name/" -> "")
            $localPath = preg_replace('/^[^\/]+\//', '', $filename);

            if (empty($localPath)) {
                continue;
            }

            $targetPath = $destination . '/' . $localPath;
            $targetDir = dirname($targetPath);

            // Create directory if needed
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            // Extract file
            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                file_put_contents($targetPath, $content);
                $extractedFiles++;

                // Update progress every 10 files
                if ($extractedFiles % 10 === 0 || $extractedFiles === $totalFiles) {
                    $this->tui->updateProgress('Extracting', $extractedFiles, $totalFiles, 'files');
                }
            }
        }

        $zip->close();
        $this->tui->replaceLastLogs('<fg=green>✔</> Extracted ' . $extractedFiles . ' files.');
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

        // Step 4.2: Install dependencies via Composer
        $this->setupComposer($projectPath);

        // Step 4.3: Run database migrations
        $this->runMigrations($projectPath);

        // Step 4.4: Create admin user
        $this->createAdminUser($projectPath, $options);

        // Step 4.5: Run database seeders
        $this->runSeeders($projectPath);

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
        $options['database']['charset'] = $this->getCharsetFromCollation($collation);
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
    protected function runSeeders(string $projectPath): void
    {
        $this->tui->addLog('Running database seeders...');

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
    protected function setupComposer(string $projectPath): void
    {
        $this->tui->addLog('Installing dependencies with Composer...');

        $composerWorkDir = is_file($projectPath . '/core/composer.json') ? ($projectPath . '/core') : $projectPath;
        $composerJson = $composerWorkDir . '/composer.json';

        if (!file_exists($composerJson)) {
            $this->tui->replaceLastLogs('<fg=yellow>⚠</> composer.json not found. Skipping dependency installation.', 2);
            return;
        }

        // Some releases may omit these directories; Composer and Evolution CMS expect them.
        @mkdir($composerWorkDir . '/storage/bootstrap', 0755, true);
        @mkdir($composerWorkDir . '/storage/cache', 0755, true);
        @mkdir($composerWorkDir . '/storage/logs', 0755, true);
        @mkdir($composerWorkDir . '/storage/sessions', 0755, true);

        $this->tui->addLog('Composer working directory: ' . basename($composerWorkDir));

        // Always use system composer
        $composerCommand = ['composer'];

        try {
            $process = $this->runComposer($composerCommand, ['install', '--no-dev', '--prefer-dist', '--no-scripts'], $composerWorkDir);
            if ($process->isSuccessful()) {
                $this->tui->addLog('Dependencies installed successfully.', 'success');
                return;
            }

            $fullOutput = $this->sanitizeComposerOutput($process->getOutput() . "\n" . $process->getErrorOutput());

            // Handle missing .git directory error (packages installed with --prefer-source)
            if (str_contains($fullOutput, '.git directory is missing') || str_contains($fullOutput, 'see https://getcomposer.org/commit-deps')) {
                $this->tui->addLog('Detected source-installed packages with missing .git. Removing vendor directory and reinstalling with --prefer-dist...', 'warning');

                // Remove vendor directory to clear source-installed packages
                $vendorDir = $composerWorkDir . '/vendor';
                if (is_dir($vendorDir)) {
                    $this->removeDirectory($vendorDir);
                }

                // Also remove composer.lock if it exists to force fresh install
                $composerLock = $composerWorkDir . '/composer.lock';
                if (file_exists($composerLock)) {
                    @unlink($composerLock);
                    $this->tui->addLog('Removed composer.lock to force fresh dependency resolution.');
                }

                // After removing vendor, we must use system composer
                $composerCommand = ['composer'];

                // Reinstall with prefer-dist
                $reinstall = $this->runComposer($composerCommand, ['install', '--no-dev', '--prefer-dist', '--no-scripts'], $composerWorkDir);
                if ($reinstall->isSuccessful()) {
                    $this->tui->addLog('Dependencies reinstalled successfully.', 'success');
                    return;
                }

                // If install fails, try update as fallback
                $this->tui->addLog('Install failed. Trying composer update...', 'warning');
                $update = $this->runComposer($composerCommand, ['update', '--no-dev', '--prefer-dist', '--no-scripts'], $composerWorkDir);
                if ($update->isSuccessful()) {
                    $this->tui->addLog('Dependencies updated successfully.', 'success');
                    return;
                }

                $updateOutput = $this->sanitizeComposerOutput($update->getOutput() . "\n" . $update->getErrorOutput());
                throw new \RuntimeException(trim($updateOutput));
            }

            // composer.lock may be generated for a newer PHP version; fall back to update to resolve a compatible set.
            if (str_contains($fullOutput, 'Your lock file does not contain a compatible set of packages')
                || str_contains($fullOutput, 'requires php >=8.4')) {
                $this->tui->addLog('composer.lock is not compatible with current PHP. Running composer update...', 'warning');
                $update = $this->runComposer($composerCommand, ['update', '--no-dev', '--prefer-dist', '--no-scripts'], $composerWorkDir);
                if ($update->isSuccessful()) {
                    $this->tui->addLog('Dependencies updated successfully.', 'success');
                    return;
                }

                $updateOutput = $this->sanitizeComposerOutput($update->getOutput() . "\n" . $update->getErrorOutput());

                // Check if update failed due to .git directory issue
                if (str_contains($updateOutput, '.git directory is missing') || str_contains($updateOutput, 'see https://getcomposer.org/commit-deps')) {
                    $this->tui->addLog('Update failed due to missing .git. Removing vendor and reinstalling...', 'warning');

                    // Remove vendor directory to clear source-installed packages
                    $vendorDir = $composerWorkDir . '/vendor';
                    if (is_dir($vendorDir)) {
                        $this->removeDirectory($vendorDir);
                    }

                    // Remove composer.lock to force fresh install
                    $composerLock = $composerWorkDir . '/composer.lock';
                    if (file_exists($composerLock)) {
                        @unlink($composerLock);
                    }

                    // After removing vendor, we must use system composer
                    $composerCommand = ['composer'];

                    // Reinstall with prefer-dist
                    $reinstall = $this->runComposer($composerCommand, ['install', '--no-dev', '--prefer-dist', '--no-scripts'], $composerWorkDir);
                    if ($reinstall->isSuccessful()) {
                        $this->tui->addLog('Dependencies reinstalled successfully.', 'success');
                        return;
                    }

                    $reinstallOutput = $this->sanitizeComposerOutput($reinstall->getOutput() . "\n" . $reinstall->getErrorOutput());
                    throw new \RuntimeException(trim($reinstallOutput));
                }

                throw new \RuntimeException(trim($updateOutput));
            }

            throw new \RuntimeException(trim($fullOutput));
        } catch (\Exception $e) {
            $this->tui->addLog('Failed to install dependencies: ' . $e->getMessage(), 'error');
            throw new \RuntimeException("Failed to install dependencies. Please run 'composer install' (or 'composer update') manually.");
        }
    }

    protected function runComposer(array $composerCommand, array $args, string $workingDir): Process
    {
        $process = new Process([
            ...$composerCommand,
            ...$args,
            '--no-interaction',
            '--no-ansi',
            '--no-progress',
        ], $workingDir);
        $process->setTimeout(600);

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

        return $process;
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
    protected function runMigrations(string $projectPath): void
    {
        $this->tui->addLog('Running database migrations...');

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
                $this->runPackageDiscovery($projectPath, $artisanScript);
            } else {
                $errorOutput = $process->getErrorOutput();
                throw new \RuntimeException("Migration failed: " . $errorOutput);
            }
        } catch (\Exception $e) {
            $this->tui->addLog('Migration failed: ' . $e->getMessage(), 'error');
            throw new \RuntimeException("Failed to run migrations. Please run 'php core/artisan migrate' manually.");
        }
    }

    protected function runPackageDiscovery(string $projectPath, string $artisanScript): void
    {
        $this->tui->addLog('Running package discovery...');

        $sessionDir = $projectPath . '/core/storage/sessions';
        if (!str_starts_with($artisanScript, 'core/')) {
            $sessionDir = $projectPath . '/storage/sessions';
        }
        @mkdir($sessionDir, 0755, true);

        $argv = ['artisan', 'package:discover'];
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
            $this->tui->replaceLastLogs('<fg=red>✗</> Package discovery failed (you can run it manually later)');
        }
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
                ['php', '-d', 'session.save_path=' . $sessionDir, $artisanScript, 'evo:install', '--username', $username, '--email', $email, '--password', $password, '--admin-dir', $adminDirectory],
                ['php', '-d', 'session.save_path=' . $sessionDir, $artisanScript, 'evolution:install', '--username', $username, '--email', $email, '--password', $password, '--admin-dir', $adminDirectory],
            ];

            $success = false;
            foreach ($commands as $cmd) {
                $process = new Process($cmd, $projectPath);
                $process->setTimeout(120);

                try {
                    $process->run();
                    if ($process->isSuccessful()) {
                        $success = true;
                        break;
                    }
                } catch (\Exception $e) {
                    // Try next command
                    continue;
                }
            }

            if ($success) {
                $this->tui->replaceLastLogs('<fg=green>✔</> Admin user created successfully.');
            } else {
                // Fallback: Create user directly in database
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
        try {
            $dbConfig = $options['database'];
            $adminConfig = $options['admin'];
            $tablePrefix = $dbConfig['prefix'] ?? 'evo_';

            $username = $adminConfig['username'] ?? 'admin';
            $email = $adminConfig['email'] ?? 'admin@example.com';
            $password = $adminConfig['password'] ?? '';

            // Create database connection
            $dbh = $this->createConnection($this->getDatabaseConfigForOperations($projectPath, $dbConfig));

            // Hash password (MD5 for Evolution CMS legacy compatibility, or bcrypt)
            $hashedPassword = md5($password);

            // Check if user already exists
            $checkQuery = "SELECT id FROM {$tablePrefix}manager_users WHERE username = :username OR email = :email LIMIT 1";
            $stmt = $dbh->prepare($checkQuery);
            $stmt->execute([':username' => $username, ':email' => $email]);

            if ($stmt->fetch()) {
                $this->tui->replaceLastLogs('<fg=yellow>⚠</> Admin user already exists. Skipping creation.', 2);
                return;
            }

            // Insert admin user
            $insertQuery = "INSERT INTO {$tablePrefix}manager_users (username, password, email, createdon, internalKey) VALUES (:username, :password, :email, :createdon, 1)";
            $stmt = $dbh->prepare($insertQuery);
            $stmt->execute([
                ':username' => $username,
                ':password' => $hashedPassword,
                ':email' => $email,
                ':createdon' => time(),
            ]);

            $this->tui->replaceLastLogs('<fg=green>✔</> Admin user created successfully.', 2);
        } catch (\Exception $e) {
            $this->tui->replaceLastLogs('<fg=yellow>⚠</> Could not create admin user automatically: ' . $e->getMessage() . '. You may need to create it manually.', 2);
        }
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

        // Always use system composer for update
        $composerCommand = ['composer'];

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

        // Remove install directory
        $installDir = $projectPath . '/install';
        if (is_dir($installDir)) {
            $this->removeDirectory($installDir);
            $this->tui->addLog('Removed install directory.', 'info');
        }

        // Remove composer.json from root (not the one in core/)
        $rootComposerJson = $projectPath . '/composer.json';
        if (file_exists($rootComposerJson) && !is_dir($rootComposerJson)) {
            @unlink($rootComposerJson);
            $this->tui->addLog('Removed root composer.json.', 'info');
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

        $this->steps['finalize']['completed'] = true;
        $this->tui->setQuestTrack($this->steps);
        $this->tui->addLog('Installation finalized successfully.', 'success');
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
