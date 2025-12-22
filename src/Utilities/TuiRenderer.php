<?php namespace EvolutionCMS\Installer\Utilities;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

final class TuiRenderer
{
    private static $cachedSteps;
    private OutputInterface $output;
    private Terminal $terminal;

    private array $logs = [];
    private array $steps = [];
    private array $systemStatus = [];
    private ?string $activeInput = null;

    private bool $fixedRendered = false;
    private int $fixedLines = 0;

    private float $lastRender = 0.0;
    private ?int $activeInputIndex = null;
    private string $activeInputValue = '';

    public function __construct(OutputInterface $output)
    {
        $this->output   = $output;
        $this->terminal = new Terminal();
    }

    public function addLog(string $message, string $type = 'info'): void
    {
        $icon = match ($type) {
            'success' => '<fg=green>✔</> ',
            'error'   => '<fg=red>✗</> ',
            'warning' => '<fg=yellow>⚠</> ',
            'ask'     => '<fg=cyan>?</> ',
            default   => '',
        };

        $this->logs[] = $icon . ($type == 'ask' ? "<fg=cyan>{$message}</>" : $message);
        $this->render(true);
    }

    public function replaceLastLog(string $line): void
    {
        array_pop($this->logs);
        $this->logs[] = $line;
        $this->render(true);
    }

    /**
     * Replace last N log entries with a single new log entry.
     */
    public function replaceLastLogs(string $line, int $count = 1): void
    {
        for ($i = 0; $i < $count && count($this->logs) > 0; $i++) {
            array_pop($this->logs);
        }
        $this->logs[] = $line;
        $this->render(true);
    }

    public function setQuestTrack(array $steps): void
    {
        $this->steps = $steps;
        $this->fixedRendered = false;
        $this->render(true);
    }

    public function setSystemStatus(array $systemStatus): void
    {
        $this->systemStatus = $systemStatus;
        $this->fixedRendered = false;
        $this->render(true);
    }

    public function render(bool $force = false): void
    {
        if (!$this->isInteractive()) {
            $this->renderPlain();
            return;
        }

        if (!$force && microtime(true) - $this->lastRender < 0.03) {
            return;
        }
        $this->lastRender = microtime(true);

        if (!$this->fixedRendered) {
            $this->renderFixed();
        }

        $this->renderLogs();
    }

    private function renderFixed(): void
    {
        $fixed =
            $this->logo() . PHP_EOL .
            $this->composeSideBySide(
                $this->questTrack(),
                $this->systemStatus()
            );

        $this->output->write("\033[2J\033[H");
        $this->output->write($fixed . PHP_EOL);

        $this->fixedLines = $this->countLines($fixed);
        $this->fixedRendered = true;
    }

    private function renderLogs(): void
    {
        $termHeight = $this->terminal->getHeight();
        $available = max(1, $termHeight - $this->fixedLines - 2);

        $logs = $this->logs;

        if ($this->activeInput !== null) {
            $logs[] = $this->activeInput;
        }

        $visible = array_slice($logs, -$available);
        $block = $this->formatLogBlock($visible);

        $logStartRow = $this->fixedLines + 1;
        $this->moveCursor($logStartRow, 1);

        for ($i = 0; $i < $available + 2; $i++) {
            $this->output->write("\033[2K\033[1B");
        }

        $this->moveCursor($logStartRow, 1);
        $this->output->write($block);
    }

    /*private function renderLogs(): void
    {
        $termHeight = $this->terminal->getHeight();
        $available = max(1, $termHeight - $this->fixedLines - 2);
        $visible = array_slice($this->logs, -$available);
        $block = $this->formatLogBlock($visible);

        $logStartRow = $this->fixedLines + 1;
        $this->moveCursor($logStartRow, 1);

        // Clear FULL log zone (not incrementally!)
        $clearLines = $available + 2;
        for ($i = 0; $i < $clearLines; $i++) {
            $this->output->write("\033[2K\033[1B");
        }

        // Back to start of log zone
        $this->moveCursor($logStartRow, 1);

        // Render full log block
        $this->output->write($block);
    }*/

    private function moveCursor(int $row, int $col): void
    {
        $this->output->write("\033[{$row};{$col}H");
    }

    private function getVisibleLogCount(): int
    {
        return min(
            count($this->logs) + ($this->activeInput ? 1 : 0),
            max(1, $this->terminal->getHeight() - $this->fixedLines - 2)
        );
    }

    /*private function clearLines(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->output->write("\033[2K");
            $this->output->write("\033[1B");
        }
        $this->output->write("\033[{$count}A");
    }*/

    private function countLines(string $text): int
    {
        return substr_count(rtrim($text, "\n"), "\n") + 1;
    }

    private function isInteractive(): bool
    {
        return $this->output->isDecorated() && !getenv('CI');
    }

    private function renderPlain(): void
    {
        if ($log = end($this->logs)) {
            $this->output->writeln(strip_tags($log));
        }
    }

    /*private function composeFixedBlock(string $logo, string $quest, string $status): string
    {
        $q = explode(PHP_EOL, rtrim($quest));
        $s = explode(PHP_EOL, rtrim($status));
        $max = max(count($q), count($s));

        $lines = [];
        for ($i = 0; $i < $max; $i++) {
            $lines[] = ($q[$i] ?? '') . ' ' . ($s[$i] ?? '');
        }

        return rtrim($logo) . PHP_EOL . implode(PHP_EOL, $lines);
    }*/

    /**
     * Compose two multiline blocks side by side.
     * Left and right blocks are aligned by line count.
     */
    private function composeSideBySide(string $left, string $right): string
    {
        $leftLines  = explode(PHP_EOL, rtrim($left));
        $rightLines = explode(PHP_EOL, rtrim($right));

        $maxLines = max(count($leftLines), count($rightLines));

        $lines = [];

        for ($i = 0; $i < $maxLines; $i++) {
            $l = $leftLines[$i]  ?? '';
            $r = $rightLines[$i] ?? '';

            // Single space gap between blocks (important for stability)
            $lines[] = $l . ' ' . $r;
        }

        return implode(PHP_EOL, $lines);
    }

    private function questTrack(): string
    {
        $frameWidth = $this->terminal->getWidth();
        $contentWidth = intdiv($frameWidth - 1 - 4, 2); // -1 for gap, -4 for borders (2 per side)

        $lines = [];

        // ── Top border with title ─────────────────────────────
        $title = ' Quest track ';
        $titleLen = mb_strlen($title);

        $left = 2;
        $right = max(0, $contentWidth - $left - $titleLen);

        $lines[] =
            '<fg=white;options=bold>┌'
            . str_repeat('─', $left)
            . '</><fg=white>'
            . $title
            . '</><fg=white;options=bold>'
            . str_repeat('─', $right)
            . '┐</>';

        // ── Quest track items ──────────────────────────────────
        $steps = !empty($this->steps) ? $this->steps : (self::$cachedSteps ?? []);
        foreach ($steps as $step) {
            $completed = $step['completed'] ?? false;
            $label = $step['label'] ?? '';

            $checkbox = $completed ? '<fg=green>✔</>' : '<fg=white>□</>';
            $labelColor = $completed ? '<fg=green>' : '';
            $resetColor = $completed ? '</>' : '';

            $line = ' ' . $checkbox . ' ' . $labelColor . $label . $resetColor;

            // Calculate padding to fill the line
            $lineLen = mb_strlen(strip_tags($line)); // Strip tags for length calculation
            $padding = max(0, $contentWidth - $lineLen);

            $lines[] =
                '<fg=white;options=bold>│</>'
                . $line
                . str_repeat(' ', $padding)
                . '<fg=white;options=bold>│</>';
        }

        // ── Bottom border ─────────────────────────────────────
        $lines[] =
            '<fg=white;options=bold>└'
            . str_repeat('─', $contentWidth)
            . '┘</>';

        return implode(PHP_EOL, $lines);
    }

    private function systemStatus(): string
    {
        $frameWidth = $this->terminal->getWidth();
        // System status takes half the width (minus 1 for gap), then minus 2 for borders
        $contentWidth = intdiv($frameWidth - 1 - 4, 2); // -1 for gap, -4 for borders (2 per side)

        $lines = [];

        // ── Top border with title ─────────────────────────────
        $title = ' System status ';
        $titleLen = mb_strlen($title);

        $left = 2;
        $right = max(0, $contentWidth - $left - $titleLen);

        $lines[] =
            '<fg=white;options=bold>┌'
            . str_repeat('─', $left)
            . '</><fg=white>'
            . $title
            . '</><fg=white;options=bold>'
            . str_repeat('─', $right)
            . '┐</>';

        // ── System status items ──────────────────────────────────
        // Use instance systemStatus if available, otherwise fallback to static cache
        $systemStatus = !empty($this->systemStatus) ? $this->systemStatus : (self::$cachedSystemStatus ?? []);
        foreach ($systemStatus as $item) {
            $status = $item['status'] ?? true;
            $label = $item['label'] ?? '';

            $indicator = $status ? '<fg=green>●</>' : '<fg=yellow>▲</>';
            $labelColor = $status ? '' : '<fg=yellow>';
            $resetColor = $status ? '' : '</>';

            $line = ' ' . $indicator . ' ' . $labelColor . $label . $resetColor;

            // Calculate padding to fill the line
            $lineLen = mb_strlen(strip_tags($line)); // Strip tags for length calculation
            $padding = max(0, $contentWidth - $lineLen);

            $lines[] =
                '<fg=white;options=bold>│</>'
                . $line
                . str_repeat(' ', $padding)
                . '<fg=white;options=bold>│</>';
        }

        // ── Bottom border ─────────────────────────────────────
        $lines[] =
            '<fg=white;options=bold>└'
            . str_repeat('─', $contentWidth)
            . '┘</>';

        return implode(PHP_EOL, $lines);
    }

    private function formatLogBlock(array $logs): string
    {
        $frameWidth = $this->terminal->getWidth();
        $contentWidth = $frameWidth - 2;

        $lines = [];

        // ── Top border with title ─────────────────────────────
        $title = ' Log ';
        $titleLen = mb_strlen($title);

        $left = 2;
        $right = max(0, $contentWidth - $left - $titleLen);

        $lines[] =
            '<fg=white;options=bold>┌'
            . str_repeat('─', $left)
            . '</><fg=white>'
            . $title
            . '</><fg=white;options=bold>'
            . str_repeat('─', $right)
            . '┐</>';

        // ── Log items ────────────────────────────────────────
        foreach ($logs as $log) {
            // Calculate padding to fill the line
            $lineLen = mb_strlen(strip_tags($log)); // Strip tags for length calculation
            $padding = max(0, $contentWidth - $lineLen - 1); // -1 for space before log

            $lines[] =
                '<fg=white;options=bold>│</>'
                . ' ' . $log
                . str_repeat(' ', $padding)
                . '<fg=white;options=bold>│</>';
        }

        // ── Bottom border ─────────────────────────────────────
        $lines[] =
            '<fg=white;options=bold>└'
            . str_repeat('─', $contentWidth)
            . '┘</>';

        return implode(PHP_EOL, $lines);
    }

    private function renderInputLine(
        string $value,
        bool $hasTyped,
        string $default = '',
        bool $hidden = false
    ): string {
        // 1. BEFORE typing → placeholder mode
        if (!$hasTyped) {
            $placeholder = $hidden ? str_repeat('•', mb_strlen($default)) : $default;
            return '<fg=cyan>›</> ' . '<fg=white>▏</>' . '<fg=gray>' . $placeholder . '</>';
        }

        // 2. AFTER typing → normal input
        $display = $hidden ? str_repeat('•', mb_strlen($value)) : $value;
        return '<fg=cyan>›</> ' . '<fg=white>' . $display . '</>' . '<fg=white>▏</>';
    }

    /**
     * Render radio button options.
     * 
     * @param array $options Array of option values (e.g., ['mysql', 'pgsql'])
     * @param int $active Index of active option
     * @param array|null $labels Optional array of display labels for options
     * @param array|null $descriptions Optional array of descriptions for options
     * @return string Formatted radio button options string
     */
    public function renderRadio(array $options, int $active, ?array $labels = null, ?array $descriptions = null): string
    {
        $out = [];

        foreach ($options as $i => $value) {
            $label = $labels[$i] ?? $value;
            $description = $descriptions[$i] ?? null;

            if ($i === $active) {
                $option = '<fg=green>●</> ' . '<fg=white;options=bold>' . $label . '</>';
                if ($description !== null) {
                    $option .= ' <fg=gray>(' . $description . ')</>';
                }
                $out[] = $option;
            } else {
                $option = '<fg=gray>○ ' . $label . '</>';
                if ($description !== null) {
                    $option .= ' <fg=gray>(' . $description . ')</>';
                }
                $out[] = $option;
            }
        }

        return '  ' . implode(' <fg=gray>/</> ', $out);
    }

    public function ask(string $label, string $default = '', bool $hidden = false): string
    {
        $this->addLog($label, 'ask');
        $this->activeInputIndex = count($this->logs);

        $value = '';
        $hasTyped = false;

        $this->logs[] = $this->renderInputLine($value, $hasTyped, $default, $hidden);
        $this->render(true);

        // 3. Raw input (always hide input for raw mode, we'll handle display ourselves)
        shell_exec('stty -icanon -echo min 1 time 0');

        try {
            while (true) {
                $char = fread(STDIN, 1);

                // ENTER
                if ($char === "\n" || $char === "\r") {
                    break;
                }

                // BACKSPACE
                if ($char === "\x7f" || $char === "\x08") {
                    if (!$hasTyped) {
                        $value = '';
                        $hasTyped = true;
                    } else {
                        $value = mb_substr($value, 0, -1);
                    }
                }
                // Printable char
                elseif (ord($char) >= 32) {
                    if (!$hasTyped) {
                        // 🔑 FIRST KEY PRESS — wipe default
                        $value = '';
                        $hasTyped = true;
                    }

                    $value .= $char;
                }

                $this->logs[$this->activeInputIndex] = $this->renderInputLine($value, $hasTyped, $default, $hidden);
                $this->render(true);
            }
        } finally {
            shell_exec('stty sane');
        }

        // 4. Final value
        $final = trim($value) !== '' ? $value : $default;

        // For hidden input, show dots or (empty) instead of actual value
        $displayValue = $hidden 
            ? (mb_strlen($final) > 0 ? '••••••••' : '(empty)')
            : $final;

        $this->logs[$this->activeInputIndex] = '<fg=green>✔</> ' . $displayValue;

        $this->activeInputIndex = null;
        $this->render(true);

        return $final;
    }

    private function logo(): string
    {
        $logo = [
            ' ███████╗██╗   ██╗ ██████╗ ',
            ' ██╔════╝██║   ██║██╔═══██╗',
            ' ███████╗╚██╗ ██╔╝██║   ██║',
            ' ██╔════╝ ╚████╔╝ ██║   ██║',
            ' ███████╗  ╚██╔╝  ╚██████╔╝',
            ' ╚══════╝   ╚═╝    ╚═════╝ ',
        ];

        $frameWidth = $this->terminal->getWidth();
        $contentWidth = $frameWidth - 2;

        $lines = [];

        // ── Top border with title ─────────────────────────────
        $title = ' Evolution CMF Installer ';
        $titleLen = mb_strlen($title);

        $left = 2;
        $right = max(0, $contentWidth - $left - $titleLen);

        $lines[] =
            '<fg=white;options=bold>┌'
            . str_repeat('─', $left)
            . '</><fg=white>'
            . $title
            . '</>'
            . str_repeat('─', $right)
            . '┐</>';

        // ── Logo block ────────────────────────────────────────
        foreach ($logo as $line) {
            $lineLen = mb_strlen($line);
            $padLeft = intdiv($contentWidth - $lineLen, 2);
            $padRight = $contentWidth - $lineLen - $padLeft;

            $lines[] =
                '<fg=white;options=bold>│</>'
                . str_repeat(' ', $padLeft)
                . '<fg=cyan>' . $line . '</>'
                . str_repeat(' ', $padRight)
                . '<fg=white;options=bold>│</>';
        }

        // ── Bottom border ─────────────────────────────────────
        $lines[] =
            '<fg=white;options=bold>└'
            . str_repeat('─', $contentWidth)
            . '┘</>';

        return implode(PHP_EOL, $lines);
    }
}
