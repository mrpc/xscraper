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

    public function testHelpFlag(): void
    {
        $output = shell_exec("php {$this->binPath} --help");
        $this->assertIsString($output);
        $this->assertStringContainsString('xscraper - Read x.com posts and replies', $output);
        $this->assertStringContainsString('--mode=smart|cache|refresh|full', $output);
    }

    public function testMissingTargetShowsError(): void
    {
        exec("php {$this->binPath} get 2>&1", $output, $status);
        $text = implode("\n", $output);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('Missing post ID or URL', $text);
    }

    public function testMissingAuthShowsError(): void
    {
        // Run with cleared environment
        exec("env -i PATH=" . escapeshellarg(getenv('PATH') ?: '/usr/bin') . " php {$this->binPath} get 1845187747970863384 2>&1", $output, $status);
        $text = implode("\n", $output);
        $this->assertSame(2, $status);
        $this->assertStringContainsString('Authentication error', $text);
    }

    public function testUnknownCommand(): void
    {
        exec("php {$this->binPath} invalidcommand 2>&1", $output, $status);
        $text = implode("\n", $output);
        $this->assertSame(1, $status);
        $this->assertStringContainsString("Unknown command 'invalidcommand'", $text);
    }
}
