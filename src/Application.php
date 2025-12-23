<?php namespace EvolutionCMS\Installer;

use Symfony\Component\Console\Application as ConsoleApplication;
use EvolutionCMS\Installer\Commands\NewCommand;

class Application extends ConsoleApplication
{
    /**
     * Create a new Application instance.
     */
    public function __construct()
    {
        parent::__construct('Evolution CMS Installer', '1.0.0');

        $this->add(new NewCommand());
    }

    /**
     * Get the long version of the application.
     */
    public function getLongVersion(): string
    {
        return parent::getLongVersion() . ' by <comment>Evolution CMS</comment>';
    }
}
