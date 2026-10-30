<?php

// Test-only SMTP receiver. Loopback only, no relay, no authentication or forwarding.
$directory = $argv[1] ?? '';
if (! is_dir($directory) || ! str_starts_with(realpath($directory), realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR)) {
    exit(2);
}
umask(0077);
$server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
if (! $server) {
    exit(3);
}
echo stream_socket_get_name($server, false)."\n";
flush();
$count = 0;
while ($client = @stream_socket_accept($server, 30)) {
    stream_set_timeout($client, 5);
    fwrite($client, "220 QA SMTP sink\r\n");
    while (($line = fgets($client)) !== false) {
        $command = strtoupper(strtok(trim($line), ' '));
        if ($command === 'EHLO' || $command === 'HELO') {
            fwrite($client, "250-QA sink\r\n250 8BITMIME\r\n");
        } elseif ($command === 'DATA') {
            if (($argv[2] ?? '') === 'reject') {
                fwrite($client, "554 QA test rejection; secret-SMTP-response-must-not-leak\r\n");

                continue;
            }
            fwrite($client, "354 End with dot\r\n");
            $body = '';
            while (($data = fgets($client)) !== false && $data !== ".\r\n") {
                $body .= str_starts_with($data, '..') ? substr($data, 1) : $data;
            }
            file_put_contents($directory.'/'.sprintf('%03d', ++$count).'.eml', $body);
            fwrite($client, "250 Captured locally\r\n");
        } elseif ($command === 'QUIT') {
            fwrite($client, "221 Bye\r\n");
            break;
        } else {
            fwrite($client, "250 OK\r\n");
        }
    }
    fclose($client);
}
