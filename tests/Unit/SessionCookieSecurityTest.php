<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SessionCookieSecurityTest extends TestCase
{
    public function test_session_cookie_defaults_to_secure_in_production(): void
    {
        $this->assertSame('true', $this->readSessionSecureSetting(['APP_ENV' => 'production']));
    }

    public function test_session_cookie_defaults_to_non_secure_in_local_development(): void
    {
        $this->assertSame('false', $this->readSessionSecureSetting(['APP_ENV' => 'local']));
    }

    public function test_session_cookie_can_be_explicitly_enabled_for_https_production(): void
    {
        $this->assertSame('true', $this->readSessionSecureSetting([
            'APP_ENV' => 'production',
            'SESSION_SECURE_COOKIE' => 'true',
        ]));
    }

    public function test_application_name_fallback_uses_public_brand(): void
    {
        $this->assertSame('Gjej portier', $this->readApplicationName());
    }

    /** @param array<string, string> $environment */
    private function readSessionSecureSetting(array $environment): string
    {
        $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo config('session.secure') ? 'true' : 'false';
PHP;

        return $this->runConfigProbe($script, $environment);
    }

    private function readApplicationName(): string
    {
        $script = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$config = require getcwd().'/config/app.php';
echo $config['name'];
PHP;

        return $this->runConfigProbe($script, []);
    }

    /** @param array<string, string> $environment */
    private function runConfigProbe(string $script, array $environment): string
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $environment,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start isolated configuration probe.');
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException('Configuration probe failed: '.$error);
        }

        return trim($output);
    }
}
