<?php namespace EvolutionCMS\Installer\Commands;

use AllowDynamicProperties;
use EvolutionCMS\Installer\Concerns\ConfiguresDatabase;
use EvolutionCMS\Installer\Presets\Preset;
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
        'dependencies' => ['label' => 'Step 5: Install dependencies', 'completed' => false],
        'admin' => ['label' => 'Step 6: Create admin user', 'completed' => false],
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
            ->addOption('preset', null, InputOption::VALUE_OPTIONAL, 'The preset to use', 'evolution')
            ->addOption('db-type', null, InputOption::VALUE_OPTIONAL, 'The database type (mysql, pgsql, sqlite, sqlsrv)')
            ->addOption('db-host', null, InputOption::VALUE_OPTIONAL, 'The database host (localhost)')
            ->addOption('db-port', null, InputOption::VALUE_OPTIONAL, 'The database port')
            ->addOption('db-name', null, InputOption::VALUE_OPTIONAL, 'The database name')
            ->addOption('db-user', null, InputOption::VALUE_OPTIONAL, 'The database user')
            ->addOption('db-password', null, InputOption::VALUE_OPTIONAL, 'The database password')
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

        //$preset = $this->getPreset($input->getOption('preset'));
        //$preset->install($name, $options);

        //Console::success("Evolution CMS application ready! Build something amazing.");

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
            
            // Mark step as completed
            $this->steps['download']['completed'] = true;
            $this->tui->setQuestTrack($this->steps);
            
            $sourceLabel = $branch ? "branch {$branch}" : "version {$displayName}";
            $this->tui->addLog("Evolution CMS from {$sourceLabel} downloaded and extracted successfully!", 'success');
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
            
            // Remove source prefix from path (e.g., "evolution-3.3.0/" or "evolution-branch-name/" -> "")
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
     * Get preset instance.
     */
    protected function getPreset(string $preset): Preset
    {
        $presetClass = "EvolutionCMS\\Installer\\Presets\\" . ucfirst($preset) . "Preset";

        if (!class_exists($presetClass)) {
            throw new \RuntimeException("Preset [{$preset}] not found.");
        }

        return new $presetClass();
    }
}

/**
 * Filtered Output wrapper that suppresses standard ChoiceQuestion output
 * and tracks selection changes for updating radio buttons.
 */
/*class FilteredOutput extends Output
{
    protected OutputInterface $output;
    protected array $options;
    protected $updateCallback;
    protected int $currentSelection = 0;
    
    public function __construct(OutputInterface $output, array $options, callable $updateCallback)
    {
        $this->output = $output;
        $this->options = $options;
        $this->updateCallback = $updateCallback;
        parent::__construct($output->getVerbosity(), $output->isDecorated(), $output->getFormatter());
    }
    
    protected function doWrite(string $message, bool $newline): void
    {
        // Filter out standard ChoiceQuestion options list ([0] mysql, [1] pgsql)
        // ChoiceQuestion outputs options using escape sequences, so we need to filter carefully
        $lines = explode("\n", $message);
        $filtered = [];
        
        foreach ($lines as $line) {
            // Skip lines that look like ChoiceQuestion numbered options
            // Pattern: [0] mysql or [1] pgsql (may have ANSI codes)
            $cleanLine = preg_replace('/\033\[[0-9;]*m/', '', $line); // Remove ANSI codes for matching
            if (preg_match('/^\s*\[\d+\]\s+(mysql|pgsql)/i', $cleanLine, $matches)) {
                // This is a standard option line - extract index and update selection
                if (preg_match('/\[(\d+)\]/', $cleanLine, $indexMatch)) {
                    $index = (int)$indexMatch[1];
                    if ($index !== $this->currentSelection) {
                        $this->currentSelection = $index;
                        ($this->updateCallback)($index);
                    }
                }
                // Don't output this line - we have our custom radio buttons
                continue;
            }
            
            // Suppress prompt lines starting with " > " or just ">"
            if (preg_match('/^\s*>\s* /', $cleanLine)) {
                // This is a prompt line, skip it
                continue;
            }
            
            // Suppress any lines containing both "[" and "]" with numbers (option lists)
            if (preg_match('/\[\d+\]/', $cleanLine)) {
                // Likely contains option numbering, skip it
                continue;
            }
            
            $filtered[] = $line;
        }
        
        // Only output if there's actual content (not just option lists)
        if (!empty($filtered)) {
            $filteredMessage = implode("\n", $filtered);
            $this->output->write($filteredMessage, $newline);
        }
    }
    
    public function getStream()
    {
        return $this->output->getStream();
    }
}

/**
 * Null Output that suppresses all output.
 */
/*class NullOutput extends Output
{
    protected function doWrite(string $message, bool $newline): void
    {
        // Suppress all output
    }
    
    public function getStream()
    {
        return STDIN;
    }
}*/
