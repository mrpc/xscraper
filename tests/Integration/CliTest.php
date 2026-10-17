<?php
declare(strict_types=1);

namespace Mrpc\XScraper\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private string $binPath;

    protected function setUp(): void
    {
        $this->binPath = realpath(__DIR__ . '/../../bin/xscraper');
    }

    /**
     * Verifies that the --help CLI flag displays library description, options, and commands.
     */
    public function testHelpFlag(): void
    {
        // Act
        $output = shell_exec("php {$this->binPath} --help");

        // Assert
        $this->assertIsString($output);
        $this->assertStringContainsString('xscraper - Read x.com posts and replies', $output);
        $this->assertStringContainsString('--mode=smart|cache|refresh|full', $output);
    }

    /**
     * Verifies that calling get command without a tweet ID/URL returns exit status 1 and error message.
     */
    public function testMissingTargetShowsError(): void
    {
        // Act
        exec("php {$this->binPath} get 2>&1", $output, $status);
        $text = implode("\n", $output);

        // Assert
        $this->assertSame(1, $status);
        $this->assertStringContainsString('Missing post ID or URL', $text);
    }

    /**
     * Verifies that executing get command without authentication tokens returns exit status 2.
     */
    public function testMissingAuthShowsError(): void
    {
        // Arrange
        $cleanEnv = "env -i PATH=" . escapeshellarg(getenv('PATH') ?: '/usr/bin');

        // Act
        exec("{$cleanEnv} php {$this->binPath} get 1845187747970863384 2>&1", $output, $status);
        $text = implode("\n", $output);

        // Assert
        $this->assertSame(2, $status);
        $this->assertStringContainsString('Authentication error', $text);
    }

    /**
     * Verifies that invoking an unsupported CLI command outputs an error and exits with status 1.
     */
    public function testUnknownCommand(): void
    {
        // Act
        exec("php {$this->binPath} invalidcommand 2>&1", $output, $status);
        $text = implode("\n", $output);

        // Assert
        $this->assertSame(1, $status);
        $this->assertStringContainsString("Unknown command 'invalidcommand'", $text);
    }

    /**
     * Verifies that --chrome option is documented in CLI help output.
     */
    public function testChromeOptionInHelp(): void
    {
        // Act
        $output = shell_exec("php {$this->binPath} --help");

        // Assert
        $this->assertIsString($output);
        $this->assertStringContainsString('--chrome[=URL]', $output);
    }
}


