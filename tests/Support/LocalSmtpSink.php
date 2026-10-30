<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

class LocalSmtpSink
{
    public string $directory;

    public int $port;

    private Process $process;

    public function __construct(string $mode = 'accept')
    {
        $this->directory = sys_get_temp_dir().'/rgk-mail-qa-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->process = new Process([PHP_BINARY, __DIR__.'/smtp-sink.php', $this->directory, $mode]);
        $this->process->start();
        if (! $this->process->waitUntil(fn ($type, $output) => preg_match('/127\.0\.0\.1:\d+/', $output))) {
            throw new \RuntimeException('QA SMTP sink could not start');
        }
        preg_match('/127\.0\.0\.1:(\d+)/', $this->process->getOutput(), $match);
        $this->port = (int) $match[1];
    }

    public function messages(): array
    {
        return array_map('file_get_contents', glob($this->directory.'/*.eml'));
    }

    public function stop(): void
    {
        $this->process->stop(1);
        foreach (glob($this->directory.'/*.eml') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }
}
