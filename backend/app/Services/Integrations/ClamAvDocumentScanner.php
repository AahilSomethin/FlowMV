<?php

namespace App\Services\Integrations;

class ClamAvDocumentScanner implements DocumentScanner
{
    public function scan(#[\SensitiveParameter] string $bytes): string
    {
        $socket = null;
        try {
            $host = config('email_integrations.documents.clamav_host');
            $port = (int) config('email_integrations.documents.clamav_port');
            if (! in_array($host, ['127.0.0.1', '::1'], true) || $port < 1 || $port > 65535 || strlen($bytes) > (int) config('email_integrations.documents.max_bytes')) {
                return 'unavailable';
            }
            $socket = @stream_socket_client('tcp://'.($host === '::1' ? '[::1]' : $host).':'.$port, $errno, $error, 2);
            if (! $socket) {
                return 'unavailable';
            }
            stream_set_timeout($socket, 5);
            $this->write($socket, "zINSTREAM\0");
            for ($offset = 0; $offset < strlen($bytes); $offset += 32768) {
                $part = substr($bytes, $offset, 32768);
                $this->write($socket, pack('N', strlen($part)).$part);
            }
            $this->write($socket, pack('N', 0));
            $result = stream_get_line($socket, 1024, "\0");
            if (is_string($result) && trim($result) === 'stream: OK') {
                return 'clean';
            }

            return is_string($result) && str_ends_with(trim($result), ' FOUND') ? 'infected' : 'unavailable';
        } catch (\Throwable) {
            return 'unavailable';
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
    }

    private function write(mixed $socket, #[\SensitiveParameter] string $data): void
    {
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = fwrite($socket, substr($data, $offset));
            if (! $written) {
                throw new \RuntimeException('Scanner unavailable.');
            }
            $offset += $written;
        }
    }
}
