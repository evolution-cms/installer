<?php namespace EvolutionCMS\Installer\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use EvolutionCMS\Installer\Utilities\Console;
use EvolutionCMS\Installer\Utilities\SystemInfo;
use EvolutionCMS\Installer\Utilities\TuiRenderer;
use EvolutionCMS\Installer\Validators\PhpValidator;
use EvolutionCMS\Installer\Presets\Preset;

class NewCommand extends Command
{
    protected ?TuiRenderer $tui = null;
    protected array $logs = [];
    protected array $steps = [];
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
            ->addOption('database', null, InputOption::VALUE_OPTIONAL, 'The database type (mysql, pgsql)')
            ->addOption('database-host', null, InputOption::VALUE_OPTIONAL, 'The database host', 'localhost')
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
        $this->output = $output;
        
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

        // Display welcome message first (this renders the TUI with empty logs)
        $this->displayWelcomeMessage();

        // Pre-validate PHP version (after TUI is rendered)
        $phpValidator = new PhpValidator();
        $phpVersion = SystemInfo::getPhpVersion();
        if (!$phpValidator->isSupported()) {
            $this->addLog("Cannot proceed with installation.");
            Console::error("Cannot proceed with installation.");
            return Command::FAILURE;
        }
        $this->addLog("✔ PHP version {$phpVersion} is supported.");
        // Note: Logs will be rendered in displayTuiWelcome() via render()

        $options = $this->gatherInputs($input, $output);
        $options['git'] = $input->getOption('git');
        $options['install_in_current_dir'] = $installInCurrentDir;

        $preset->install($name, $options);

        Console::success("Evolution CMS application ready! Build something amazing.");

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
    protected function displayWelcomeMessage(): void
    {
        try {
            $this->displayTuiWelcome();
        } catch (\Exception $e) {
            // Fallback to simple display
            $this->printLogo();
            $this->printSystemStatus();
            Console::line('');
        }
    }

    /**
     * Display TUI welcome screen.
     */
    protected function displayTuiWelcome(): void
    {
        $this->tui = new TuiRenderer($this->output);
        
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

        $this->tui->render($systemStatus, $this->steps, $this->logs);
        
        // Note: With Output Sections, logs are already rendered in render() via renderLogSection().
        // We only need to call updateTuiLogs() when adding new logs after initial render.
    }

    /**
     * Add log message.
     */
    protected function addLog(string $message): void
    {
        $this->logs[] = $message;
    }

    /**
     * Update TUI logs display (only Log block, not the whole screen).
     */
    protected function updateTuiLogs(): void
    {
        if ($this->tui !== null) {
            $this->tui->updateLogs($this->logs);
        }
    }

    /**
     * Update TUI steps display (only Quest track block, not the whole screen).
     */
    protected function updateTuiSteps(): void
    {
        if ($this->tui !== null) {
            $this->tui->updateSteps($this->steps);
        }
    }

    /**
     * Mark a step as completed.
     */
    protected function completeStep(int $stepNumber): void
    {
        if (isset($this->steps[$stepNumber - 1])) {
            $this->steps[$stepNumber - 1]['completed'] = true;
            $this->updateTuiSteps();
        }
    }

    /**
     * Print EVO ASCII logo.
     */
    protected function printLogo(): void
    {
        $logo = <<<'LOGO'
     ███████╗██╗   ██╗ ██████╗ 
     ██╔════╝██║   ██║██╔═══██╗
     █████╗  ██║   ██║██║   ██║
     ██╔══╝  ██║   ██║██║   ██║
     ███████╗╚██████╔╝╚██████╔╝
     ╚══════╝ ╚═════╝  ╚═════╝ 
     
     Evolution CMF Installer
LOGO;

        // Print logo in bright cyan color
        if (function_exists('stream_isatty') && stream_isatty(STDOUT)) {
            echo "\033[1;36m"; // Bright cyan
            echo $logo;
            echo "\033[0m"; // Reset
        } else {
            echo $logo;
        }
        Console::line('');
    }

    /**
     * Print system status information.
     */
    protected function printSystemStatus(): void
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
    protected function printStatusItem(string $label, bool $status, bool $isHeader = false): void
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

        // Database configuration
        $inputs['database']['type'] = $input->getOption('database') ?: $this->askDatabaseType($helper, $input, $output);
        $inputs['database']['host'] = $input->getOption('database-host') ?: $this->askDatabaseHost($helper, $input, $output);
        
        if ($input->getOption('database-port')) {
            $inputs['database']['port'] = $input->getOption('database-port');
        }
        
        $inputs['database']['name'] = $input->getOption('database-name') ?: $this->askDatabaseName($helper, $input, $output);
        $inputs['database']['user'] = $input->getOption('database-user') ?: $this->askDatabaseUser($helper, $input, $output);
        $inputs['database']['password'] = $input->getOption('database-password') ?: $this->askDatabasePassword($helper, $input, $output);

        // Admin configuration
        $inputs['admin']['username'] = $input->getOption('admin-username') ?: $this->askAdminUsername($helper, $input, $output);
        $inputs['admin']['email'] = $input->getOption('admin-email') ?: $this->askAdminEmail($helper, $input, $output);
        $inputs['admin']['password'] = $input->getOption('admin-password') ?: $this->askAdminPassword($helper, $input, $output);

        return $inputs;
    }

    /**
     * Ask for database type.
     */
    protected function askDatabaseType($helper, InputInterface $input, OutputInterface $output): string
    {
        $this->addLog('Which database driver do you want to use?');
        $this->addLog('  [0] mysql');
        $this->addLog('  [1] pgsql');
        $this->updateTuiLogs();
        
        // Use input section from TUI to display prompt inside Log block
        $inputSection = $this->tui?->getInputSection();
        
        // Use regular Question to avoid duplicate option display
        // ChoiceQuestion automatically displays options, which duplicates our Log output
        $question = new Question(' > ');
        $question->setAutocompleterValues(['0', '1', 'mysql', 'pgsql']);
        $question->setValidator(function ($answer) {
            if (empty($answer)) {
                throw new \RuntimeException('Database driver cannot be empty.');
            }
            
            // Normalize answer: allow both index and name
            $normalized = strtolower(trim($answer));
            if (in_array($normalized, ['0', 'mysql'])) {
                return 'mysql';
            }
            if (in_array($normalized, ['1', 'pgsql'])) {
                return 'pgsql';
            }
            
            throw new \RuntimeException('Database driver must be either "mysql" or "pgsql".');
        });

        // Ask the question - use input section if available to redirect output inside Log block
        if ($inputSection !== null) {
            // Use input section as output so the prompt appears inside Log block
            $answer = $helper->ask($input, $inputSection, $question);
        } else {
            $answer = $helper->ask($input, $output, $question);
        }
        
        // Clear input prompt after answer
        if ($inputSection !== null) {
            $inputSection->clear();
        }
        
        // Add the answer to log
        $this->addLog(' > ' . $answer);
        $this->updateTuiLogs();
        
        return $answer;
    }

    /**
     * Ask for database host.
     */
    protected function askDatabaseHost($helper, InputInterface $input, OutputInterface $output): string
    {
        $question = new Question('Database host:', 'localhost');
        return $helper->ask($input, $output, $question);
    }

    /**
     * Ask for database name.
     */
    protected function askDatabaseName($helper, InputInterface $input, OutputInterface $output): string
    {
        $question = new Question('Database name:', 'evo_db');
        return $helper->ask($input, $output, $question);
    }

    /**
     * Ask for database user.
     */
    protected function askDatabaseUser($helper, InputInterface $input, OutputInterface $output): string
    {
        $question = new Question('Database user:', 'root');
        return $helper->ask($input, $output, $question);
    }

    /**
     * Ask for database password.
     */
    protected function askDatabasePassword($helper, InputInterface $input, OutputInterface $output): string
    {
        $question = new Question('Database password:');
        $question->setHidden(true);
        return $helper->ask($input, $output, $question) ?: '';
    }

    /**
     * Ask for admin username.
     */
    protected function askAdminUsername($helper, InputInterface $input, OutputInterface $output): string
    {
        $question = new Question('Admin username:', 'admin');
        return $helper->ask($input, $output, $question);
    }

    /**
     * Ask for admin email.
     */
    protected function askAdminEmail($helper, InputInterface $input, OutputInterface $output): string
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
    protected function askAdminPassword($helper, InputInterface $input, OutputInterface $output): string
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
