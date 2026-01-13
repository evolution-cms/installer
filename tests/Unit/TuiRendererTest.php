<?php

namespace EvolutionCMS\Installer\Tests\Unit;

use EvolutionCMS\Installer\Utilities\TuiRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class TuiRendererTest extends TestCase
{
    public function testPlainModeDoesNotRepeatLastLogOnNonLogRenders(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
        $tui = new TuiRenderer($output);

        $tui->addLog('Testing database connection...');
        $this->assertStringContainsString('Testing database connection...', $output->fetch());

        $tui->replaceLastLogs('<fg=green>✔</> Database connection successful!', 2);
        $this->assertSame("✔ Database connection successful!\n", $output->fetch());

        $tui->setQuestTrack([
            'database' => ['label' => 'Step 2: Check database connection', 'completed' => true],
        ]);
        $this->assertSame('', $output->fetch());
    }
}

