<?php namespace EvolutionCMS\Installer\Commands;

use AllowDynamicProperties;
use EvolutionCMS\Installer\Concerns\ConfiguresDatabase;
use EvolutionCMS\Installer\Presets\Preset;
use EvolutionCMS\Installer\Utilities\Console;
use EvolutionCMS\Installer\Utilities\SystemInfo;
use EvolutionCMS\Installer\Utilities\TuiRenderer;
use EvolutionCMS\Installer\Validators\PhpValidator;
use PDOException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AllowDynamicProperties]
class NewCommand extends Command
{
    use ConfiguresDatabase;
    
    protected ?OutputInterface $logSection = null;
    protected ?TuiRenderer $tui = null;
    protected array $steps = [
        'php' => ['label' => 'Step 1: Validate PHP version', 'completed' => false],
        'database' => ['label' => 'Step 2: Check database connection', 'completed' => false],
        'download' => ['label' => 'Step 3: Download Evolution CMF', 'completed' => false],
        'extract' => ['label' => 'Step 4: Extract files', 'completed' => false],
        'install' => ['label' => 'Step 5: Install Evolution CMF', 'completed' => false],
        'dependencies' => ['label' => 'Step 6: Install dependencies', 'completed' => false],
        'admin' => ['label' => 'Step 7: Create admin user', 'completed' => false],
        'git' => ['label' => 'Step 8: Initialize Git repository', 'completed' => false],
        'finalize' => ['label' => 'Step 9: Finalize installation', 'completed' => false],
    ];

    //protected array $logs = [];
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
            ->addOption('database', null, InputOption::VALUE_OPTIONAL, 'The database type (mysql, pgsql, sqlite, sqlsrv)')
            ->addOption('database-host', null, InputOption::VALUE_OPTIONAL, 'The database host (localhost)')
            ->addOption('database-port', null, InputOption::VALUE_OPTIONAL, 'The database port')
            ->addOption('database-name', null, InputOption::VALUE_OPTIONAL, 'The database name')
            ->addOption('database-user', null, InputOption::VALUE_OPTIONAL, 'The database user')
            ->addOption('database-password', null, InputOption::VALUE_OPTIONAL, 'The database password')
            ->addOption('admin-username', null, InputOption::VALUE_OPTIONAL, 'The admin username', 'admin')
            ->addOption('admin-email', null, InputOption::VALUE_OPTIONAL, 'The admin email')
            ->addOption('admin-password', null, InputOption::VALUE_OPTIONAL, 'The admin password')
            ->addOption('language', null, InputOption::VALUE_OPTIONAL, 'The installation language', 'en')
            ->addOption('git', null, InputOption::VALUE_NONE, 'Initialize a Git repository')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force install even if directory exists');
    }

    /**
     * Execute the command.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->tui = new TuiRenderer($output);
        $this->tui->setSystemStatus($this->checkSystemStatus());

        $name = $input->getArgument('name') ?? '.';
        $preset = $this->getPreset($input->getOption('preset'));

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
        //$options['git'] = $input->getOption('git');
        //$options['install_in_current_dir'] = $installInCurrentDir;

        //$preset->install($name, $options);

        //Console::success("Evolution CMF application ready! Build something amazing.");

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
     * Display the welcome message with system information.
     */
    /*protected function displayWelcomeMessage($output): void
    {
        try {
            $this->displayTuiWelcome($output);
        } catch (\Exception $e) {
            $this->printSystemStatus();
            Console::line('');
        }
    }

    /**
     * Display TUI welcome screen.
     */
    /*protected function displayTuiWelcome($output): void
    {
        $output->write(sprintf("\033\143"));

        // Pass sections to TuiRenderer
        $this->tui = new TuiRenderer($output);
        $this->tui->clear();

        $logoSection = $output->section();
        //$logoSection->setMaxHeight(9); // title + 6 logo lines + bottom border

        $questSection = $output->section();
        //$questSection->setMaxHeight(11); // title + 9 items + bottom border

        $this->logSection = $output->section();
        //$this->logSection->setMaxHeight(5); // allow scrolling for logs

        $this->inputSection = $output->section();
        //$this->logSection->setMaxHeight(2);
        
        // Set input section in TuiRenderer
        $this->tui->setInputSection($this->inputSection);

        $logoSection->writeln($this->tui->buildLogoContent());

        // Prepare system status
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

        $systemStatus = [
            ['label' => $os, 'status' => true],
            ['label' => "PHP - {$phpVersion}", 'status' => $phpOk],
            ['label' => "Composer" . ($composerVersion ? " - {$composerVersion}" : ''), 'status' => $composerOk],
            ['label' => 'PDO extension', 'status' => $pdoOk],
            ['label' => 'JSON extension', 'status' => $jsonOk],
            ['label' => 'MySQLi extension', 'status' => $mysqliOk],
            ['label' => 'MBString extension', 'status' => $mbstringOk],
            ['label' => 'Disk free - ' . ($diskFree ?: 'Unknown'), 'status' => $diskFree !== null],
            ['label' => 'Memory limit - ' . ($memoryLimit ?: 'Unknown'), 'status' => true],
        ];

        // Installation steps
        $this->steps = [
            ['label' => 'Step 1: Validate PHP version', 'completed' => $phpOk],
            ['label' => 'Step 2: Check database connection', 'completed' => false],
            ['label' => 'Step 3: Download Evolution CMF', 'completed' => false],
            ['label' => 'Step 4: Extract files', 'completed' => false],
            ['label' => 'Step 5: Install Evolution CMF', 'completed' => false],
            ['label' => 'Step 6: Install dependencies', 'completed' => false],
            ['label' => 'Step 7: Create admin user', 'completed' => false],
            ['label' => 'Step 8: Initialize Git repository', 'completed' => false],
            ['label' => 'Step 9: Finalize installation', 'completed' => false],
        ];

        $questSection->writeln($this->tui->buildQuestAndSystemContent($this->steps, $systemStatus));
        $this->logSection->writeln($this->tui->buildLogContent());
    }

    /**
     * Add log message.
     */
    /*protected function addLog(string $message): void
    {
        $this->logs[] = $message;
    }

    /**
     * Update TUI logs display (only Log block, not the whole screen).
     */
    /*protected function updateTuiLogs(): void
    {
        if ($this->tui !== null) {
            $this->tui->updateLogs($this->logs);
        }
    }

    /**
     * Update TUI steps display (only Quest track block, not the whole screen).
     */
    /*protected function updateTuiSteps(): void
    {
        if ($this->tui !== null) {
            // Use fullRender to ensure all blocks stay in correct positions
            $this->tui->fullRender($this->logs);
        }
    }

    /**
     * Mark a step as completed.
     */
    /*protected function completeStep(int $stepNumber): void
    {
        if (isset($this->steps[$stepNumber - 1])) {
            $this->steps[$stepNumber - 1]['completed'] = true;
            $this->updateTuiSteps();
        }
    }

    /**
     * Print system status information.
     */
    /*protected function printSystemStatus(): void
    {
        $os = SystemInfo::getOS();
        $phpVersion = SystemInfo::getPhpVersion();
        $composerVersion = SystemInfo::getComposerVersion();
        $diskFree = SystemInfo::getDiskFreeSpace();
        $memoryLimit = SystemInfo::getMemoryLimit();

        // Check PHP version compatibility
        $phpOk = version_compare($phpVersion, '8.3.0', '>=');
        $pdoOk = SystemInfo::hasExtension('pdo');
        $jsonOk = SystemInfo::hasExtension('json');
        $mysqliOk = SystemInfo::hasExtension('mysqli');
        $mbstringOk = SystemInfo::hasExtension('mbstring');
        $composerOk = $composerVersion !== null;

        $this->printStatusItem('System status', true, true);
        Console::line('');
        
        $this->printStatusItem($os, true);
        $this->printStatusItem("PHP - {$phpVersion}", $phpOk);
        $this->printStatusItem("Composer" . ($composerVersion ? " - {$composerVersion}" : ''), $composerOk);
        $this->printStatusItem('PDO extension', $pdoOk);
        $this->printStatusItem('JSON extension', $jsonOk);
        $this->printStatusItem('MySQLi extension', $mysqliOk);
        $this->printStatusItem('MBString extension', $mbstringOk);
        
        if ($diskFree) {
            $this->printStatusItem("Disk free - {$diskFree}", true);
        }
        
        if ($memoryLimit) {
            $this->printStatusItem("Memory limit - {$memoryLimit}", true);
        }
    }

    /**
     * Print a status item with indicator.
     *
     * @param string $label
     * @param bool $status
     * @param bool $isHeader
     */
    /*protected function printStatusItem(string $label, bool $status, bool $isHeader = false): void
    {
        if ($isHeader) {
            // Header without indicator
            echo "\033[1m{$label}\033[0m";
            echo PHP_EOL;
            return;
        }

        $indicator = $status ? '●' : '▲';
        $indicatorColor = $status ? "\033[0;32m" : "\033[0;33m";
        $reset = "\033[0m";
        
        echo "  {$indicatorColor}{$indicator}{$reset} ";
        
        if (!$status) {
            echo "\033[2m{$label}\033[0m";
        } else {
            echo $label;
        }
        
        echo PHP_EOL;
    }

    /*
     *
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
        
        return [
            ['label' => $os, 'status' => true],
            ['label' => "PHP - {$phpVersion}", 'status' => $phpOk],
            ['label' => "Composer" . ($composerVersion ? " - {$composerVersion}" : ''), 'status' => $composerOk],
            ['label' => 'PDO extension', 'status' => $pdoOk],
            ['label' => 'PDO MySQL driver', 'status' => $pdoMysqlOk],
            ['label' => 'PDO PostgreSQL driver', 'status' => $pdoPgsqlOk],
            ['label' => 'PDO SQLite driver', 'status' => $pdoSqliteOk],
            ['label' => 'PDO SQL Server driver', 'status' => $pdoSqlsrvOk],
            ['label' => 'JSON extension', 'status' => $jsonOk],
            ['label' => 'MySQLi extension', 'status' => $mysqliOk],
            ['label' => 'MBString extension', 'status' => $mbstringOk],
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
            'language' => $input->getOption('language') ?: 'en',
        ];

        // Database configuration with connection retry loop
        $databaseConnected = false;
        $firstAttempt = true;
        while (!$databaseConnected) {
            if ($firstAttempt && $this->hasAllDatabaseOptions($input)) {
                // First attempt with command-line options
                $inputs['database']['type'] = $input->getOption('database');
                $inputs['database']['host'] = $input->getOption('database-host');
                if ($input->getOption('database-port')) {
                    $inputs['database']['port'] = $input->getOption('database-port');
                }
                $inputs['database']['name'] = $input->getOption('database-name');
                $inputs['database']['user'] = $input->getOption('database-user');
                $inputs['database']['password'] = $input->getOption('database-password');
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
        $inputs['admin']['username'] = $input->getOption('admin-username') ?: $this->askAdminUsername();
        $inputs['admin']['email'] = $input->getOption('admin-email') ?: $this->askAdminEmail();
        $inputs['admin']['password'] = $input->getOption('admin-password') ?: $this->askAdminPassword();

        return $inputs;
    }

    /**
     * Check if all database options are provided via command line.
     */
    protected function hasAllDatabaseOptions(InputInterface $input): bool
    {
        return $input->getOption('database') !== null
            && $input->getOption('database-host') !== null
            && $input->getOption('database-name') !== null
            && $input->getOption('database-user') !== null
            && $input->getOption('database-password') !== null;
    }

    /**
     * Gather database configuration inputs from user.
     */
    protected function gatherDatabaseInputs($helper, InputInterface $input, OutputInterface $output, bool $useOptions = true): array
    {
        $database = [];
        
        // Use command-line options if available, otherwise ask interactively
        $database['type'] = ($useOptions && $input->getOption('database')) ? $input->getOption('database') : $this->askDatabaseType();
        
        // SQLite doesn't need host, port, user, password
        if ($database['type'] === 'sqlite') {
            $database['name'] = ($useOptions && $input->getOption('database-name')) 
                ? $input->getOption('database-name') 
                : $this->askDatabaseName('sqlite');
            // SQLite doesn't need these fields, but set defaults for compatibility
            $database['host'] = '';
            $database['user'] = '';
            $database['password'] = '';
        } else {
            $database['host'] = ($useOptions && $input->getOption('database-host')) 
                ? $input->getOption('database-host') 
                : $this->askDatabaseHost();
            
            if ($input->getOption('database-port')) {
                $database['port'] = $input->getOption('database-port');
            }
            
            $database['name'] = ($useOptions && $input->getOption('database-name')) 
                ? $input->getOption('database-name') 
                : $this->askDatabaseName();
            $database['user'] = ($useOptions && $input->getOption('database-user')) 
                ? $input->getOption('database-user') 
                : $this->askDatabaseUser();
            $database['password'] = ($useOptions && $input->getOption('database-password')) 
                ? $input->getOption('database-password') 
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

    /*protected function askDatabaseType($helper, InputInterface $input, OutputInterface $output): string
    {
        $this->tui->addLog("Which database driver do you want to use?", 'success');

        $options = ['mysql', 'pgsql'];
        $selectedIndex = 0;

        // Display initial options with radio buttons
        $this->updateDatabaseDriverOptions($selectedIndex);

        // Use custom interactive selection via TuiRenderer
        // Create key map for database driver selection (custom keys for mysql/pgsql)
        $keyMap = [
            '0' => 'select_0',  // Direct select mysql
            '1' => 'select_1',  // Direct select pgsql
            'm' => 'select_0',  // 'm' for mysql
            'M' => 'select_0',
            'p' => 'select_1',  // 'p' for pgsql
            'P' => 'select_1',
            'UP_ARROW' => 'toggle',
            'DOWN_ARROW' => 'toggle',
            'LEFT_ARROW' => 'toggle',
            'RIGHT_ARROW' => 'toggle',
            "\t" => 'toggle',  // TAB
            'CTRL_P' => 'toggle',
            'CTRL_N' => 'toggle',
            'CTRL_F' => 'toggle',
            'CTRL_B' => 'toggle',
            'h' => 'toggle',
            'j' => 'toggle',
            'k' => 'toggle',
            'l' => 'toggle',
            'H' => 'toggle',
            'J' => 'toggle',
            'K' => 'toggle',
            'L' => 'toggle',
            "\n" => 'confirm',
            "\r" => 'confirm',
        ];

        // Handle interactive selection with arrow keys using TuiRenderer
        $answer = $this->tui?->handleInteractiveSelection(
            $options,
            $selectedIndex,
            function($index) {
                $this->updateDatabaseDriverOptions($index);
                $this->logSection->write($this->logs ?? []);
            },
            $keyMap
        ) ?? $options[$selectedIndex];

        // Clear the input section after answer
        $inputSection = $this->tui?->getInputSection();
        if ($inputSection !== null) {
            $inputSection->clear();
        }

        // Update Log Section with the final selected answer
        $finalIndex = array_search($answer, $options, true);
        if ($finalIndex === false) {
            $finalIndex = 0;
        }

        $this->updateDatabaseDriverOptions($finalIndex);

        // Add success message about selected database driver
        $green = TuiRenderer::colorGreen();
        $reset = TuiRenderer::colorReset();
        $driverNames = ['mysql' => 'MySQL', 'pgsql' => 'PgSQL'];
        $successMessage = $green . '✔' . $reset . ' Selected database driver: ' . $driverNames[$answer];

        // Update log section
        $msg = $this->tui->buildLogLine($successMessage);
        $this->logSection->clear(2);
        $this->logSection->writeln($msg);

        return $answer;
    }

    /**
     * Update database driver options display with radio buttons.
     */
    /*protected function updateDatabaseDriverOptions(int $selectedIndex): void
    {
        // Create options with radio buttons based on selection
        // Selected option has brighter text, unselected has dimmer text
        $mysqlOption = ($selectedIndex === 0) ?
            ('<fg=green>●</> <fg=white;options=bold>mysql</>') :
            ('<fg=gray>○ mysql</>');
        $pgsqlOption = ($selectedIndex === 1) ?
            ('<fg=green>●</> <fg=white;options=bold>pgsql</>') :
            ('<fg=gray>○ pgsql</>');

        $optionsLine = '  ' . $mysqlOption . ' ' . '<fg=gray>/</>' . ' ' . $pgsqlOption;

        // Update or add the options line in logs
        // Find if options line already exists (should be the last log entry before potential prompt)
        $optionsLineIndex = -1;
        for ($i = count($this->logs ?? []) - 1; $i >= 0; $i--) {
            if (strpos($this->logs[$i], 'mysql') !== false || strpos($this->logs[$i], 'pgsql') !== false) {
                $optionsLineIndex = $i;
                break;
            }
        }

        if ($optionsLineIndex >= 0) {
            $this->tui->addLog($optionsLineIndex, 'ask');
            $this->tui->addLog($optionsLine, 'ask');
        } else {
            $this->tui->addLog($optionsLine, 'ask');
        }
    }

    /**
     * Ask for database host.
     */
    /*protected function askDatabaseHost($helper, InputInterface $input, OutputInterface $output): string
    {
        $this->tui->addLog('Where is your database server located?', 'ask');
        
        // Use input section for prompt
        /*$inputSection = $this->tui->getInputSection();
        if ($inputSection !== null) {
            $inputSection->clear();
            $inputSection->write('localhost');
        }*/
        
        // Use Question helper with default value
        /*$question = new Question('', 'localhost');
        $answer = $helper->ask($input, $output, $question);
        var_dump($answer);die;
        
        // Clear input section after answer
        /*if ($inputSection !== null) {
            $inputSection->clear();
        }
        
        // Display selected value
        $green = TuiRenderer::colorGreen();
        $successMessage = $green . '✔' . $reset . ' Database host: ' . $answer;
        $msg = $this->tui->buildLogLine($successMessage);
        $this->logSection->clear(2);
        $this->logSection->writeln($msg);*/

        //return $answer ?: 'localhost';
    //}

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
     * Update retry options display with radio buttons.
     */
    protected function updateRetryOptions(int $selectedIndex): void
    {
        $green = TuiRenderer::colorGreen();
        $gray = TuiRenderer::colorWhite(); // Light gray for unselected
        $brightWhite = TuiRenderer::colorBold(); // Bright white for selected text
        $reset = TuiRenderer::colorReset();
        
        // Create options with radio buttons based on selection
        // Selected option has brighter text, unselected has dimmer text
        $tryAgainOption = ($selectedIndex === 0) ? 
            ($green . '●' . $reset . ' ' . $brightWhite . 'Try again' . $reset) : 
            ($gray . '○' . $reset . ' ' . $gray . 'Try again' . $reset);
        $exitOption = ($selectedIndex === 1) ? 
            ($green . '●' . $reset . ' ' . $brightWhite . 'Exit installation' . $reset) : 
            ($gray . '○' . $reset . ' ' . $gray . 'Exit installation' . $reset);
        
        $optionsLine = '  ' . $tryAgainOption . ' ' . $gray . '/' . $reset . ' ' . $exitOption;
        
        // Update the options line in log section
        $this->logSection->clear(1);
        $this->logSection->writeln($optionsLine);
    }

    /**
     * Ask for admin username.
     */
    /*protected function askAdminUsername($helper, InputInterface $input, OutputInterface $output): string
    {
        $question = new Question('Admin username:', 'admin');
        return $helper->ask($input, $output, $question);
    }

    /**
     * Ask for admin email.
     */
    /*protected function askAdminEmail($helper, InputInterface $input, OutputInterface $output): string
    {
        $question = new Question('Admin email:');
        $question->setValidator(function ($value) {
            if (empty($value)) {
                throw new \RuntimeException('Email address is required.');
            }
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Please enter a valid email address.');
            }
            return $value;
        });
        $answer = $helper->ask($input, $output, $question);
        return $answer ?? '';
    }

    /**
     * Ask for admin password.
     */
    /*protected function askAdminPassword($helper, InputInterface $input, OutputInterface $output): string
    {
        $question = new Question('Admin password:');
        $question->setHidden(true);
        $question->setValidator(function ($value) {
            if (strlen($value) < 6) {
                throw new \RuntimeException('Password must be at least 6 characters long.');
            }
            return $value;
        });
        $result = $helper->ask($input, $output, $question);
        return $result ?? '';
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
