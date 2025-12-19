<?php namespace EvolutionCMS\Installer\Utilities;

class TuiRenderer
{
    protected bool $supportsColors;
    protected int $terminalWidth;
    protected int $terminalHeight;
    protected ?int $logBlockStartRow = null;
    protected ?int $questBlockStartRow = null;
    protected ?int $rightPanelWidth = null;
    protected ?int $gap = null;
    protected ?int $leftPanelWidth = null;

    public function __construct()
    {
        $this->supportsColors = function_exists('stream_isatty') && stream_isatty(STDOUT);
        
        // Try to get terminal size
        $this->terminalWidth = 120;
        $this->terminalHeight = 30;
        
        if (function_exists('exec')) {
            // Try different methods to get terminal size
            if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
                $output = [];
                exec('tput cols 2>/dev/null', $output);
                if (!empty($output[0])) {
                    $this->terminalWidth = (int)$output[0];
                }
                
                $output = [];
                exec('tput lines 2>/dev/null', $output);
                if (!empty($output[0])) {
                    $this->terminalHeight = (int)$output[0];
                }
            }
        }
    }

    /**
     * Clear screen.
     */
    public function clear(): void
    {
        echo "\033[2J\033[H";
    }

    /**
     * Render main TUI interface with two panels side by side.
     */
    public function render(array $systemStatus, array $steps = [], array $logs = []): void
    {
        // Clear screen and move cursor to top
        $this->clear();
        
        // Frame color - use same bright white as logo frame
        $frameColor = $this->supportsColors ? "\033[1;37m" : ''; // Bright white - same as logo frame
        $whiteText = $this->supportsColors ? "\033[0;37m" : ''; // Pure white for text on frames
        $green = $this->supportsColors ? "\033[0;32m" : '';
        $yellow = $this->supportsColors ? "\033[0;33m" : '';
        $reset = $this->supportsColors ? "\033[0m" : '';
        $bold = $this->supportsColors ? "\033[1m" : '';
        
        // Print logo (takes 8 lines: 1 top border + 6 logo lines + 1 bottom border)
        $this->printEvoLogoSimple();
        
        // Calculate available height (from after logo to bottom of terminal)
        $logoHeight = 8; // Top border + 6 logo lines + bottom border (no extra spacing)
        $logoBlockWidth = $this->terminalWidth;
        
        // Calculate top panel heights (Quest track and System status side by side)
        // Limit height to exactly fit content: 9 items + title (1) + bottom border (1) = 11 lines
        $topPanelHeight = 11; // Fixed height: title + 9 items + bottom border
        
        // Top panels: Quest track (left) and System status (right)
        $gap = 1; // Gap between top panels
        // Calculate inner width: total width - 2 borders (left+right) - gap between panels - 2 borders for second panel
        $topPanelWidth = (int)(($logoBlockWidth - 4 - $gap) / 2); // Inner width (without borders)
        
        // Build Quest track block (left top panel)
        $questLines = [];
        $questTitle = ' Quest track ';
        $questTitleLength = mb_strlen($this->stripAnsi($questTitle));
        $questTopBorderContent = str_repeat('─', 2) . $whiteText . $questTitle . $reset . $frameColor . str_repeat('─', max(0, $topPanelWidth - 2 - $questTitleLength));
        $questLines[] = $frameColor . '┌' . $questTopBorderContent . '┐' . $reset;
        
        foreach ($steps as $i => $step) {
            $completed = $step['completed'] ?? false;
            $checkbox = $completed ? '✔' : '□';
            $color = $completed ? $green : '';
            $label = $step['label'] ?? "Step " . ($i + 1);
            
            $line = $frameColor . '│' . $reset . ' ' . $color . $checkbox . $reset . ' ' . $label;
            $questLines[] = $this->padLine($line, $topPanelWidth, $frameColor) . '│' . $reset;
        }
        
        // Fill remaining height in Quest track
        $questContentLines = count($questLines);
        $questRemainingLines = max(0, $topPanelHeight - $questContentLines - 1); // -1 for bottom border
        for ($i = 0; $i < $questRemainingLines; $i++) {
            $questLines[] = $frameColor . '│' . str_repeat(' ', $topPanelWidth) . '│' . $reset;
        }
        $questLines[] = $frameColor . '└' . str_repeat('─', $topPanelWidth) . '┘' . $reset;
        
        // Build System status block (right top panel)
        $systemStatusLines = [];
        $systemStatusTitle = ' System status ';
        $systemStatusTitleLength = mb_strlen($this->stripAnsi($systemStatusTitle));
        $systemStatusTopBorderContent = str_repeat('─', 2) . $whiteText . $systemStatusTitle . $reset . $frameColor . str_repeat('─', max(0, $topPanelWidth - 2 - $systemStatusTitleLength));
        $systemStatusLines[] = $frameColor . '┌' . $systemStatusTopBorderContent . '┐' . $reset;
        
        foreach ($systemStatus as $item) {
            $status = $item['status'] ?? true;
            $label = $item['label'] ?? '';
            $indicator = $status ? '●' : '▲';
            $color = $status ? $green : $yellow;
            
            $line = $frameColor . '│' . $reset . ' ' . $color . $indicator . $reset . ' ' . $label;
            $systemStatusLines[] = $this->padLine($line, $topPanelWidth, $frameColor) . '│' . $reset;
        }
        
        // Fill remaining height in System status
        $systemStatusContentLines = count($systemStatusLines);
        $systemStatusRemainingLines = max(0, $topPanelHeight - $systemStatusContentLines - 1); // -1 for bottom border
        for ($i = 0; $i < $systemStatusRemainingLines; $i++) {
            $systemStatusLines[] = $frameColor . '│' . str_repeat(' ', $topPanelWidth) . '│' . $reset;
        }
        $systemStatusLines[] = $frameColor . '└' . str_repeat('─', $topPanelWidth) . '┘' . $reset;
        
        // Build Log block - full width at bottom, only 1 line (title bar), no bottom border
        $logBlockInnerWidth = $logoBlockWidth - 2; // Inner width (without borders)
        $logLines = [];
        $logTitle = ' Log ';
        $logTitleLength = mb_strlen($this->stripAnsi($logTitle));
        $logTopBorderContent = str_repeat('─', 2) . $whiteText . $logTitle . $reset . $frameColor . str_repeat('─', max(0, $logBlockInnerWidth - 2 - $logTitleLength));
        $logLines[] = $frameColor . '┌' . $logTopBorderContent . '┐' . $reset;
        // No content, no bottom border - just the title bar
        
        // Combine top panels side by side
        $topLines = [];
        $maxTopLines = max(count($questLines), count($systemStatusLines));
        for ($i = 0; $i < $maxTopLines; $i++) {
            $questLine = $questLines[$i] ?? ($frameColor . '│' . str_repeat(' ', $topPanelWidth) . '│' . $reset);
            $systemStatusLine = $systemStatusLines[$i] ?? ($frameColor . '│' . str_repeat(' ', $topPanelWidth) . '│' . $reset);
            $topLines[] = $questLine . str_repeat(' ', $gap) . $systemStatusLine;
        }
        
        // Combine: top panels + Log block
        // No empty line - Log block starts immediately after top panels
        $allLines = array_merge($topLines, $logLines);
        
        // Save positions and dimensions for later updates
        $logoHeight = 7;
        $this->questBlockStartRow = $logoHeight;
        // Log block starts after top panels
        $this->logBlockStartRow = $logoHeight + count($topLines) - 1;
        $this->rightPanelWidth = $topPanelWidth; // For quest track updates (same width)
        $this->gap = $gap;
        $this->leftPanelWidth = $topPanelWidth; // For quest track (it's on the left now)
        
        // Print all lines
        foreach ($allLines as $line) {
            echo $line . PHP_EOL;
        }
    }
    
    /**
     * Update only the Log block without re-rendering everything.
     */
    public function updateLogs(array $logs): void
    {
        if ($this->logBlockStartRow === null) {
            // If initial render hasn't been called, do nothing
            return;
        }
        
        $frameColor = $this->supportsColors ? "\033[1;37m" : '';
        $whiteText = $this->supportsColors ? "\033[0;37m" : '';
        $reset = $this->supportsColors ? "\033[0m" : '';
        
        // Log block is full width
        $logBlockInnerWidth = $this->terminalWidth - 2; // Inner width (without borders)
        
        // Build Log block - title bar + content, no bottom border
        $logLines = [];
        $logTitle = ' Log ';
        $logTitleLength = mb_strlen($this->stripAnsi($logTitle));
        $logTopBorderContent = str_repeat('─', 2) . $whiteText . $logTitle . $reset . $frameColor . str_repeat('─', max(0, $logBlockInnerWidth - 2 - 2 - $logTitleLength));
        $logLines[] = $frameColor . '┌' . $logTopBorderContent . '┐' . $reset;
        
        // Add log items (display content)
        if (!empty($logs)) {
            foreach ($logs as $log) {
                $logLines[] = $this->padLine($frameColor . '│' . $reset . ' ' . $log, $logBlockInnerWidth, $frameColor) . '│' . $reset;
            }
        }
        // No bottom border - just content below title bar
        
        // Draw each line of Log block (full width, starting from column 1)
        $currentRow = $this->logBlockStartRow;
        foreach ($logLines as $logLine) {
            // Move cursor to the position (full width, column 1)
            echo "\033[{$currentRow};1H";
            // Clear to end of line
            echo "\033[K";
            // Write the log line
            echo $logLine;
            $currentRow++;
        }
        
        // Clear any remaining lines below logs (if logs are shorter than before)
        $maxLogLines = 20; // Maximum expected log lines
        for ($i = count($logLines); $i < $maxLogLines; $i++) {
            echo "\033[{$currentRow};1H";
            echo "\033[K"; // Clear to end of line
            $currentRow++;
        }
    }
    
    /**
     * Update only the Quest track block without re-rendering everything.
     */
    public function updateSteps(array $steps): void
    {
        if ($this->questBlockStartRow === null || $this->leftPanelWidth === null) {
            // If initial render hasn't been called, do nothing
            return;
        }
        
        $frameColor = $this->supportsColors ? "\033[1;37m" : '';
        $whiteText = $this->supportsColors ? "\033[0;37m" : '';
        $green = $this->supportsColors ? "\033[0;32m" : '';
        $reset = $this->supportsColors ? "\033[0m" : '';
        $topPanelWidth = $this->leftPanelWidth; // Quest track is on the left now
        
        // Build Quest track block
        $questLines = [];
        $questTitle = ' Quest track ';
        $questTitleLength = mb_strlen($this->stripAnsi($questTitle));
        $questTopBorderContent = str_repeat('─', 2) . $whiteText . $questTitle . $reset . $frameColor . str_repeat('─', max(0, $topPanelWidth - 2 - 2 - $questTitleLength));
        $questLines[] = $frameColor . '┌' . $questTopBorderContent . '┐' . $reset;
        
        foreach ($steps as $i => $step) {
            $completed = $step['completed'] ?? false;
            $checkbox = $completed ? '✔' : '□';
            $color = $completed ? $green : '';
            $label = $step['label'] ?? "Step " . ($i + 1);
            
            $line = $frameColor . '│' . $reset . ' ' . $color . $checkbox . $reset . ' ' . $label;
            $questLines[] = $this->padLine($line, $topPanelWidth, $frameColor) . '│' . $reset;
        }
        
        // Fill remaining height
        $logoHeight = 7;
        $topPanelHeight = $this->logBlockStartRow - $logoHeight + 1;
        $questContentLines = count($questLines);
        $questRemainingLines = max(0, $topPanelHeight - $questContentLines - 1); // -1 for bottom border
        for ($i = 0; $i < $questRemainingLines; $i++) {
            $questLines[] = $frameColor . '│' . str_repeat(' ', $topPanelWidth) . '│' . $reset;
        }
        
        // Quest block closes with bottom border
        $questLines[] = $frameColor . '└' . str_repeat('─', $topPanelWidth) . '┘' . $reset;
        
        // Draw each line of Quest track block (left panel, starting from column 1)
        $currentRow = $this->questBlockStartRow;
        foreach ($questLines as $questLine) {
            // Move cursor to the left panel position (column 1)
            echo "\033[{$currentRow};1H";
            // Clear to end of line
            echo "\033[K";
            // Write the quest line
            echo $questLine;
            $currentRow++;
        }
    }
    
    /**
     * Print EVO logo in simple format with frame stretched to edges.
     */
    protected function printEvoLogoSimple(): void
    {
        $brightCyan = $this->supportsColors ? "\033[1;36m" : '';
        $reset = $this->supportsColors ? "\033[0m" : '';
        
        $logo = [
            ' ███████╗██╗   ██╗ ██████╗ ',
            ' ██╔════╝██║   ██║██╔═══██╗',
            ' ███████╗╚██╗ ██╔╝██║   ██║',
            ' ██╔════╝ ╚████╔╝ ██║   ██║',
            ' ███████╗  ╚██╔╝  ╚██████╔╝',
            ' ╚══════╝   ╚═╝    ╚═════╝ ',
        ];
        
        // Calculate logo width (longest line)
        $logoWidth = 0;
        foreach ($logo as $line) {
            $logoWidth = max($logoWidth, mb_strlen($line));
        }
        
        // Frame stretches to edges with no padding
        // Use terminal width directly, terminal should handle line wrapping
        $sidePadding = 0;
        $frameWidth = $this->terminalWidth;
        
        // Ensure frame doesn't exceed terminal width
        // Subtract 1 for the right border character
        $frameContentWidth = $frameWidth - 2; // -2 for left and right borders
        
        // Top border (white) - use bright white for better visibility
        $frameColor = $this->supportsColors ? "\033[1;37m" : ''; // Bright white for frames
        $whiteText = $this->supportsColors ? "\033[0;37m" : ''; // Pure white for text
        
        // Top border with title in left corner (2 dashes, 1 space before and after title)
        $title = 'Evolution CMF Installer';
        $titleWithSpaces = ' ' . $title . ' ';
        $titleTotalLength = mb_strlen($titleWithSpaces);
        $dashOffset = 2;
        $topBorderContent = str_repeat('─', $dashOffset) . $whiteText . $titleWithSpaces . $reset . $frameColor . str_repeat('─', max(0, $frameContentWidth - $dashOffset - $titleTotalLength));
        echo $frameColor . '┌' . $topBorderContent . '┐' . $reset . PHP_EOL;
        
        // Logo lines with frame (centered within frame) - symmetric spacing
        foreach ($logo as $line) {
            $lineLength = mb_strlen($line);
            $linePadding = (int)(($frameContentWidth - $lineLength) / 2);
            $remainingPadding = $frameContentWidth - $lineLength - $linePadding;
            
            echo $frameColor . '│' . $reset 
                . str_repeat(' ', $linePadding)
                . $brightCyan . $line . $reset
                . str_repeat(' ', $remainingPadding)
                . $frameColor . '│' . $reset . PHP_EOL;
        }
        
        // Bottom border (symmetric to top - no empty line before)
        echo $frameColor . '└' . str_repeat('─', $frameContentWidth) . '┘' . $reset . PHP_EOL;
    }
    
    /**
     * Pad line to specified width.
     */
    protected function padLine(string $line, int $width, string $borderColor = ''): string
    {
        $cleanLength = mb_strlen($this->stripAnsi($line));
        $padding = max(0, $width - $cleanLength + 1);
        return $line . str_repeat(' ', $padding);
    }
    
    /**
     * Strip ANSI codes from string for length calculation.
     */
    protected function stripAnsi(string $text): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $text);
    }

    /**
     * Simple render without full TUI (fallback).
     */
    public function renderSimple(string $logo, array $systemStatus): void
    {
        $cyan = $this->supportsColors ? "\033[1;36m" : '';
        $reset = $this->supportsColors ? "\033[0m" : '';
        
        echo $cyan . $logo . $reset . PHP_EOL . PHP_EOL;
        
        echo "\033[1mSystem status\033[0m" . PHP_EOL;
        foreach ($systemStatus as $item) {
            $status = $item['status'] ?? true;
            $label = $item['label'] ?? '';
            $indicator = $status ? '●' : '▲';
            $color = $status ? ($this->supportsColors ? "\033[0;32m" : '') : ($this->supportsColors ? "\033[0;33m" : '');
            $resetColor = $this->supportsColors ? "\033[0m" : '';
            
            echo "  {$color}{$indicator}{$resetColor} {$label}" . PHP_EOL;
        }
        echo PHP_EOL;
    }
}
