<?php namespace EvolutionCMS\Installer\Utilities;

class Console
{
    /**
     * Check if terminal supports color output.
     */
    protected static function supportsColor(): bool
    {
        return function_exists('stream_isatty') && stream_isatty(STDOUT);
    }

    /**
     * Write colored text to console.
     */
    protected static function writeColored(string $text, ?string $color = null): void
    {
        if (!static::supportsColor() || $color === null) {
            echo $text;
            return;
        }

        $colors = [
            'green' => "\033[0;32m",
            'red' => "\033[0;31m",
            'yellow' => "\033[0;33m",
            'blue' => "\033[0;34m",
            'cyan' => "\033[0;36m",
            'magenta' => "\033[0;35m",
            'bright-cyan' => "\033[1;36m",
            'bright-green' => "\033[1;32m",
            'bright-yellow' => "\033[1;33m",
            'dim' => "\033[2m",
            'bold' => "\033[1m",
            'reset' => "\033[0m",
        ];

        $colorCode = $colors[$color] ?? '';
        $reset = $colors['reset'] ?? '';

        echo $colorCode . $text . $reset;
    }

    /**
     * Write info message.
     */
    public static function info(string $message): void
    {
        static::writeColored($message, 'cyan');
        echo PHP_EOL;
    }

    /**
     * Write success message.
     */
    public static function success(string $message): void
    {
        static::writeColored('✔ ', 'green');
        static::writeColored($message, 'green');
        echo PHP_EOL;
    }

    /**
     * Write error message.
     */
    public static function error(string $message): void
    {
        static::writeColored('✖ ', 'red');
        static::writeColored($message, 'red');
        echo PHP_EOL;
    }

    /**
     * Write warning message.
     */
    public static function warning(string $message): void
    {
        static::writeColored('⚠ ', 'yellow');
        static::writeColored($message, 'yellow');
        echo PHP_EOL;
    }

    /**
     * Write comment/muted message.
     */
    public static function comment(string $message): void
    {
        static::writeColored($message, 'blue');
        echo PHP_EOL;
    }

    /**
     * Write line break.
     */
    public static function line(string $message = ''): void
    {
        echo $message . PHP_EOL;
    }
}

