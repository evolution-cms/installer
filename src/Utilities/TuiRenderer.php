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
    private bool $plainLogDirty = false;

    private bool $fixedRendered = false;
    private int $fixedLines = 0;

    private float $lastRender = 0.0;
    private ?int $activeInputIndex = null;

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
        $this->plainLogDirty = true;
        $this->render(true);
    }

    /**
     * Clear all logs.
     */
    public function clearLogs(): void
    {
        $this->logs = [];
        $this->activeInput = null;
        $this->activeInputIndex = null;
        $this->plainLogDirty = false;
        $this->render(true);
    }

    public function replaceLastLog(string $line): void
    {
        array_pop($this->logs);
        $this->logs[] = $line;
        $this->plainLogDirty = true;
        $this->render(true);
    }

    /**
     * Update the last log line with progress bar (for downloads, etc.).
     * 
     * @param string $message Base message
     * @param int $current Current progress (bytes, items, etc.)
     * @param int $total Total size (bytes, items, etc.)
     * @param string $unit Unit to display (e.g., 'MB', 'files')
     */
    public function updateProgress(string $message, int $current, int $total, string $unit = 'MB'): void
    {
        if ($total === 0) {
            $percentage = 0;
            $barWidth = 0;
        } else {
            $percentage = min(100, round(($current / $total) * 100));
            $barWidth = 20; // Width of progress bar
            $filled = round(($percentage / 100) * $barWidth);
        }

        // Format current and total with appropriate unit
        if ($unit === 'files') {
            // For files, just show numbers with "files" text
            $formattedCurrent = number_format($current) . ' files';
            $formattedTotal = number_format($total) . ' files';
        } else {
            // For bytes/MB, use formatBytes
            $formattedCurrent = $this->formatBytes($current, $unit);
            $formattedTotal = $this->formatBytes($total, $unit);
        }
        
        // Create progress bar
        $bar = str_repeat('█', $filled ?? 0) . str_repeat('░', ($barWidth ?? 0) - ($filled ?? 0));
        
        // Combine message with progress bar
        $progressLine = "{$message} [<fg=green>{$bar}</>] {$percentage}% ({$formattedCurrent} / {$formattedTotal})";
        
        // Replace last log if it's a progress line, otherwise add new
        if (!empty($this->logs) && strpos(end($this->logs), $message) === 0) {
            $this->replaceLastLog($progressLine);
        } else {
            $this->logs[] = $progressLine;
            $this->plainLogDirty = true;
            $this->render(true);
        }
    }
    
    /**
     * Format bytes to human-readable format.
     */
    private function formatBytes(int $bytes, string $unit = 'MB'): string
    {
        if ($unit === 'MB') {
            return round($bytes / 1024 / 1024, 2) . ' MB';
        }
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        $size = $bytes;
        
        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }
        
        return round($size, 2) . ' ' . $units[$unitIndex];
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
        $this->plainLogDirty = true;
        $this->render(true);
    }

    /**
     * Replace the last N log lines with multiple new lines.
     */
    public function replaceLastLogsMultiple(array $lines, int $count): void
    {
        for ($i = 0; $i < $count && count($this->logs) > 0; $i++) {
            array_pop($this->logs);
        }
        foreach ($lines as $line) {
            $this->logs[] = $line;
        }
        $this->plainLogDirty = true;
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
        $fixed = $this->composeFixedBlocks();

        $this->output->write("\033[2J\033[H");
        $this->output->write($fixed . PHP_EOL);

        $this->fixedLines = $this->countLines($fixed);
        $this->fixedRendered = true;
    }

    /**
     * Compose fixed blocks in columns:
     */
    private function composeFixedBlocks(): string
    {
        $logoVersionBlock = $this->logoVersionBlock();
        $statusBlock = $this->systemStatus();
        $questBlock = $this->questTrack();

        // Stack left column blocks vertically, keeping them separate (with their own frames)
        $leftColumn = $logoVersionBlock . PHP_EOL . $questBlock;
        $leftColumnHeight = $this->countLines($leftColumn);
        
        // Align status block to match left column height
        $statusBlockAligned = $this->alignBlockHeight($statusBlock, $leftColumnHeight);
        
        // Compose two columns side by side (1/2 + 1/2)
        return $this->composeSideBySide($leftColumn, $statusBlockAligned);
    }

    /**
     * Get half width (1/2 of screen width minus borders and gap).
     */
    private function getHalfWidth(): int
    {
        $frameWidth = $this->terminal->getWidth();
        // -1 for gap between columns, -4 for borders (2 per side)
        return intdiv($frameWidth - 1 - 4, 2);
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

    private function moveCursor(int $row, int $col): void
    {
        $this->output->write("\033[{$row};{$col}H");
    }

    private function countLines(string $text): int
    {
        return substr_count(rtrim($text, "\n"), "\n") + 1;
    }

    /**
     * Align block to specified height by padding with empty lines.
     */
    private function alignBlockHeight(string $block, int $targetHeight): string
    {
        $currentHeight = $this->countLines($block);
        
        if ($currentHeight >= $targetHeight) {
            return $block;
        }

        $lines = explode(PHP_EOL, rtrim($block));
        if (empty($lines)) {
            return $block;
        }

        // Extract content width from a middle line
        $contentWidth = 0;
        foreach ($lines as $line) {
            if (preg_match('/│([^│]*)│/', $line, $matches)) {
                $contentWidth = mb_strlen(strip_tags($matches[1]));
                break;
            }
        }

        // If we couldn't extract width, try to get it from top border
        if ($contentWidth === 0 && isset($lines[0])) {
            $topLine = strip_tags($lines[0]);
            if (preg_match('/┌(─+)┐/', $topLine, $matches)) {
                $contentWidth = mb_strlen($matches[1]);
            }
        }

        // Create empty line with borders
        $leftBorder = '<fg=white;options=bold>│</>';
        $rightBorder = '<fg=white;options=bold>│</>';
        $emptyLine = $leftBorder . str_repeat(' ', max(0, $contentWidth)) . $rightBorder;
        
        // Add empty lines before last line (bottom border)
        $emptyLinesToAdd = $targetHeight - $currentHeight;
        if ($emptyLinesToAdd > 0) {
            $lastIndex = count($lines) - 1;
            array_splice($lines, $lastIndex, 0, array_fill(0, $emptyLinesToAdd, $emptyLine));
        }
        
        return implode(PHP_EOL, $lines);
    }

    private function isInteractive(): bool
    {
        return $this->output->isDecorated() && !getenv('CI');
    }

    private function renderPlain(): void
    {
        if (!$this->plainLogDirty) {
            return;
        }
        $this->plainLogDirty = false;

        if ($log = end($this->logs)) {
            $this->output->writeln(strip_tags($log));
        }
    }
    
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
        $contentWidth = $this->getHalfWidth();

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
        // System status takes 2/4 (half) of the width
        $contentWidth = $this->getHalfWidth();

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
            $warning = $item['warning'] ?? false; // Optional warning flag for non-critical issues

            // Use different indicators: success (✓), warning (⚠), error (✗)
            if ($status) {
                $indicator = '<fg=green>●</>';
                $labelColor = '';
                $resetColor = '';
            } elseif ($warning) {
                $indicator = '<fg=yellow>●</>';
                $labelColor = '<fg=yellow>';
                $resetColor = '</>';
            } else {
                $indicator = '<fg=red>●</>';
                $labelColor = '<fg=red>';
                $resetColor = '</>';
            }

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

    /**
     * Contains title "Evolution CMS Installer" with logo and version.
     */
    private function logoVersionBlock(): string
    {
        $logo = [
            ' ███████╗██╗   ██╗ ██████╗ ',
            ' ██╔════╝██║   ██║██╔═══██╗',
            ' ███████╗╚██╗ ██╔╝██║   ██║',
            ' ██╔════╝ ╚████╔╝ ██║   ██║',
            ' ███████╗  ╚██╔╝  ╚██████╔╝',
            ' ╚══════╝   ╚═╝    ╚═════╝ ',
        ];

        $versionInfo = $this->getLatestVersion();
        $contentWidth = $this->getHalfWidth(); // 1/2 width minus borders and gap
        // Each quarter takes half of the block content width
        $quarterWidth = intdiv($contentWidth, 2);
        
        $lines = [];

        // ── Top border with title ─────────────────────────────
        $title = ' Evolution CMS Installer ';
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

        // ── Logo (left quarter) + Version (right quarter) ──
        $logoHeight = count($logo);
        
        // Version lines
        $versionLines = [
            '<fg=white;options=bold>v' . $versionInfo . '</>',
            '',
            '<fg=green>The world\'s fastest!</>'
        ];
        $versionBlockHeight = count($versionLines);
        $versionStartOffset = intdiv($logoHeight - $versionBlockHeight, 2);

        for ($i = 0; $i < $logoHeight; $i++) {
            // Left side: logo (centered in left quarter)
            $logoLine = $logo[$i];
            $logoLineLen = mb_strlen($logoLine);
            $logoPadLeft = intdiv($quarterWidth - $logoLineLen, 2);
            $logoPadRight = $quarterWidth - $logoLineLen - $logoPadLeft;
            $leftContent = str_repeat(' ', $logoPadLeft)
                         . '<fg=cyan>' . $logoLine . '</>'
                         . str_repeat(' ', $logoPadRight);

            // Right side: version (centered in right quarter)
            $rightContent = '';
            $versionIndex = $i - $versionStartOffset;
            if ($versionIndex >= 0 && $versionIndex < $versionBlockHeight) {
                $versionLine = $versionLines[$versionIndex];
                if ($versionLine !== '') {
                    $versionLen = mb_strlen(strip_tags($versionLine));
                    $versionPadLeft = intdiv($quarterWidth - $versionLen, 2);
                    $versionPadRight = $quarterWidth - $versionLen - $versionPadLeft;
                    $rightContent = str_repeat(' ', $versionPadLeft)
                                  . $versionLine
                                  . str_repeat(' ', $versionPadRight);
                } else {
                    $rightContent = str_repeat(' ', $quarterWidth);
                }
            } else {
                $rightContent = str_repeat(' ', $quarterWidth);
            }

            // Calculate remaining space in the middle (between logo and version quarters)
            $middleSpace = $contentWidth - ($quarterWidth * 2);

            $lines[] =
                '<fg=white;options=bold>│</>'
                . $leftContent
                . str_repeat(' ', $middleSpace)
                . $rightContent
                . '<fg=white;options=bold>│</>';
        }

        // ── Bottom border ─────────────────────────────────────
        $lines[] =
            '<fg=white;options=bold>└'
            . str_repeat('─', $contentWidth)
            . '┘</>';

        return implode(PHP_EOL, $lines);
    }

    /**
     * Get latest version from GitHub releases.
     */
    private function getLatestVersion(): string
    {
        static $cachedVersion = null;
        
        if ($cachedVersion !== null) {
            return $cachedVersion;
        }

        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => 'https://api.github.com',
                'timeout' => 25.0,
                'http_errors' => false,
            ]);

            $response = $client->get('/repos/evolution-cms/evolution/releases/latest');
            
            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                if (isset($data['tag_name'])) {
                    // Remove 'v' prefix if present
                    $version = ltrim($data['tag_name'], 'v');
                    $cachedVersion = $version;
                    return $version;
                }
            }
        } catch (\Exception $e) {
            // Silent fail, return default version
        }

        // Default version if API fails
        $cachedVersion = '3.5.0';
        return $cachedVersion;
    }
}
