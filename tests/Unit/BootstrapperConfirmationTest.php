<?php

namespace EvolutionCMS\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Exercise the bootstrapper's real STDIN prompt in an isolated PHP process.
 *
 * @since 1.4.0
 */
final class BootstrapperConfirmationTest extends TestCase
{
    /**
     * Invalid lines must not decide the answer or prevent a later valid choice.
     *
     * @since 1.4.0
     */
    #[DataProvider('confirmationCases')]
    public function testConfirmationInput(
        string $input,
        bool $defaultYes,
        bool $expected,
        int $prompts,
        int $invalidLines
    ): void {
        $script = <<<'PHP'
// Load the real class up to the top-level launcher without executing updates.
$code = '';
$depth = 0;
foreach (token_get_all(file_get_contents('bin/evo'), TOKEN_PARSE) as $token) {
    if (is_array($token)) {
        if (in_array($token[0], [T_OPEN_TAG, T_INLINE_HTML], true)) {
            continue;
        }
        if ($token[0] === T_TRY && $depth === 0) {
            break;
        }
        if (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $depth++;
        }
        $code .= $token[1];
    } else {
        $depth += $token === '{' ? 1 : ($token === '}' ? -1 : 0);
        $code .= $token;
    }
}
eval($code);
$reflection = new ReflectionClass(EvoBootstrapper::class);
$app = $reflection->newInstanceWithoutConstructor();
$result = $reflection->getMethod('promptYesNo')->invoke($app, 'Confirm?', $argv[1] === 'yes');
echo $result ? "RESULT=yes\n" : "RESULT=no\n";
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, $defaultYes ? 'yes' : 'no'], dirname(__DIR__, 2));
        $process->setInput($input);
        $process->setTimeout(5);
        $process->mustRun();

        $output = $process->getOutput();
        $this->assertSame('', $process->getErrorOutput());
        $this->assertStringEndsWith($expected ? "RESULT=yes\n" : "RESULT=no\n", $output);
        $this->assertSame($prompts, substr_count($output, 'Confirm?'));
        $this->assertSame($invalidLines, substr_count($output, 'Please answer y/yes or n/no.'));
    }

    /**
     * Cover valid choices, defaults, repeated invalid input, and EOF termination.
     *
     * @return array<string, array{string, bool, bool, int, int}>
     * @since 1.4.0
     */
    public static function confirmationCases(): array
    {
        return [
            'lowercase yes' => ["y\n", true, true, 1, 0],
            'uppercase yes' => ["Y\n", false, true, 1, 0],
            'mixed case yes with spaces' => [" YeS \n", false, true, 1, 0],
            'lowercase no' => ["n\n", true, false, 1, 0],
            'uppercase no' => ["N\n", true, false, 1, 0],
            'mixed case no' => [" No \n", true, false, 1, 0],
            'Enter defaults to yes' => ["\n", true, true, 1, 0],
            'Enter defaults to no' => ["\n", false, false, 1, 0],
            'invalid then yes' => ["maybe\ny\n", true, true, 2, 1],
            'invalid then no' => ["maybe\nn\n", true, false, 2, 1],
            'several invalid lines' => ["maybe\n?\nYES\n", false, true, 3, 2],
            'Cyrillic lookalike then yes' => ["\u{0443}\ny\n", true, true, 2, 1],
            'nonbreaking space then yes' => ["y\u{00a0}\nyes\n", true, true, 2, 1],
            'paste markers then no' => ["\x1b[200~y\x1b[201~\nn\n", true, false, 2, 1],
            'invalid then default' => ["?\n\n", true, true, 2, 1],
            'EOF defaults to yes' => ['', true, true, 1, 0],
            'EOF defaults to no' => ['', false, false, 1, 0],
            'invalid then EOF defaults to no' => ["maybe\n", false, false, 2, 1],
            'invalid then EOF defaults to yes' => ["maybe\n", true, true, 2, 1],
            'valid final line without newline' => ['yes', false, true, 1, 0],
        ];
    }
}
