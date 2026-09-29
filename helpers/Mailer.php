<?php
declare(strict_types=1);

/** Small isolated SMTP transport. No message/token logging or on-disk outbox. */
final class Mailer
{
    public static function send(string $to, string $subject, string $body): void
    {
        $c = Config::get('mail');
        $from = $c['from'];
        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)
            || preg_match('/[\r\n]/', $from . $to . $subject . $c['from_name']) || !$c['host']) {
            throw new RuntimeException('Mail configuration is incomplete');
        }
        $security = $c['encryption'];
        if (!in_array($security, ['tls', 'ssl', 'none'], true)) throw new RuntimeException('Invalid mail encryption');
        if ($security === 'none' && !in_array($c['host'], ['127.0.0.1', 'localhost', '::1'], true)) throw new RuntimeException('Remote SMTP requires TLS');
        $socket = stream_socket_client(($security === 'ssl' ? 'ssl://' : 'tcp://') . $c['host'] . ':' . $c['port'], $errno, $error, 10);
        if (!$socket) throw new RuntimeException('SMTP unavailable');
        stream_set_timeout($socket, 10);
        $read = static function (array $expected) use ($socket): void {
            do {
                $line = fgets($socket, 4096);
                if ($line === false) throw new RuntimeException('SMTP connection closed');
            } while (isset($line[3]) && $line[3] === '-');
            if (!in_array((int) substr($line, 0, 3), $expected, true)) throw new RuntimeException('SMTP command rejected');
        };
        $command = static function (string $line, array $expected) use ($socket, $read): void {
            $wire = $line . "\r\n";
            while ($wire !== '') {
                $sent = fwrite($socket, $wire);
                if (!$sent) throw new RuntimeException('SMTP write failed');
                $wire = substr($wire, $sent);
            }
            $read($expected);
        };
        try {
            $read([220]);
            $command('EHLO localhost', [250]);
            if ($security === 'tls') {
                $command('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP TLS failed');
                $command('EHLO localhost', [250]);
            }
            if ($c['username'] !== '') {
                $command('AUTH LOGIN', [334]);
                $command(base64_encode($c['username']), [334]);
                $command(base64_encode($c['password']), [235]);
            }
            $command("MAIL FROM:<$from>", [250]);
            $command("RCPT TO:<$to>", [250, 251]);
            $command('DATA', [354]);
            $name = '=?UTF-8?B?' . base64_encode($c['from_name']) . '?=';
            $message = "From: $name <$from>\r\nTo: <$to>\r\nSubject: $subject\r\nDate: " . gmdate('r') . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
            $command($message . '.', [250]);
            // Message accepted; a failure closing the connection must not invalidate its token.
            @fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }
}
