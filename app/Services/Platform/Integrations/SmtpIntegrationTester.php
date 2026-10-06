<?php

namespace App\Services\Platform\Integrations;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Throwable;

/**
 * A real SMTP handshake — connect, and authenticate if credentials are given.
 *
 * No email is sent. `EsmtpTransport::start()` performs the actual TCP connection,
 * STARTTLS negotiation (or implicit TLS on port 465) and, when a username is set,
 * the AUTH exchange — everything an email would need except the DATA command that
 * would actually queue one. `stop()` disconnects cleanly either way. This is the
 * same transport class Laravel's own SMTP mail driver uses under the hood; nothing
 * here is reimplemented, only driven directly instead of through `Mail::` so it can
 * be pointed at fields nobody has saved to `config/mail.php`.
 */
class SmtpIntegrationTester implements IntegrationTester
{
    public function test(array $config): array
    {
        $host = trim((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? 0);

        if ($host === '' || $port <= 0) {
            return ['ok' => false, 'message' => 'A host and a port are required.'];
        }

        $encryption = (string) ($config['encryption'] ?? 'none');

        /*
         * `EsmtpTransport`'s constructor `$tls` argument is NOT a three-way
         * none/tls/ssl choice — it only decides whether the socket itself opens as
         * `ssl://` from the very first byte (implicit TLS, port 465's scheme).
         * STARTTLS is a SEPARATE thing: a plaintext connection that upgrades in
         * place once the server advertises it in its EHLO response, controlled by
         * `autoTls` (on by default) rather than by this constructor argument.
         *
         * Passing `true` for STARTTLS's port 587 was the first version of this
         * method's bug, caught by testing against a real server rather than
         * assumed: it opened an SSL socket directly against a port that expects a
         * plaintext greeting first, and got back "SSL routines::wrong version
         * number" — a real but useless connectivity result. `false` here does NOT
         * mean "no encryption" — it means "do not open TLS immediately", and
         * `autoTls` still upgrades the connection via STARTTLS once the plaintext
         * handshake shows the server offers it, which is exactly what port 587
         * needs and what re-testing against a real STARTTLS server confirmed.
         */
        $transport = new EsmtpTransport($host, $port, $encryption === 'ssl');

        if ($encryption === 'none') {
            // Asked for explicitly, not merely absent — autoTls's opportunistic
            // upgrade is switched off so "none" is honoured rather than silently
            // encrypted anyway because the server happened to offer STARTTLS.
            $transport->setAutoTls(false);
        }

        $username = trim((string) ($config['username'] ?? ''));

        if ($username !== '') {
            $transport->setUsername($username);
            $transport->setPassword((string) ($config['password'] ?? ''));
        }

        try {
            $transport->start();
            $transport->stop();

            return [
                'ok' => true,
                'message' => $username !== ''
                    ? 'Connected and authenticated.'
                    : 'Connected. No credentials were given, so authentication was not attempted.',
            ];
        } catch (TransportExceptionInterface $exception) {
            // The transport's own message already names what SMTP stage failed
            // (connection, STARTTLS, AUTH) and often quotes the server's own reply —
            // exactly what somebody debugging a wrong password or a closed port needs,
            // and better than anything this class could summarise instead.
            return ['ok' => false, 'message' => $exception->getMessage()];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => 'Could not connect: ' . $exception->getMessage()];
        }
    }
}
