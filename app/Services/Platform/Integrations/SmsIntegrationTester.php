<?php

namespace App\Services\Platform\Integrations;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tests the generic, per-tenant SMS gateway (`sms_api_details`'s real shape,
 * now backed by the encrypted integration-credential vault) that
 * `authController::sendSMS()` already builds a URL for like this:
 *
 *   url + pram + mobile_var + <mobile> + text_var + <urlencoded text> + last_var
 *
 * UNLIKE SmtpIntegrationTester/WebhookIntegrationTester, this one does NOT
 * default to a real send — a well-formed request to one of these gateways
 * IS the send action itself, so a "Test Connection" with no explicit opt-in
 * only checks that the configured host is reachable at all (DNS resolves, a
 * connection opens, any HTTP response comes back), never constructing a
 * send-shaped URL. A real test send happens ONLY when the admin has also
 * filled in `test_mobile_number` — deliberately a separate, optional field,
 * so sending an actual priced text is opt-in, not the default behaviour of
 * clicking "Test".
 */
class SmsIntegrationTester implements IntegrationTester
{
    private const TIMEOUT_SECONDS = 8;

    public function test(array $config): array
    {
        $url = trim((string) ($config['url'] ?? ''));

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return ['ok' => false, 'message' => 'A valid gateway URL is required.'];
        }

        $testMobile = trim((string) ($config['test_mobile_number'] ?? ''));

        return $testMobile !== ''
            ? $this->sendRealTest($config, $url, $testMobile)
            : $this->checkReachability($url);
    }

    /**
     * No send-shaped URL is built here — just the bare configured host, so a
     * provider that rejects a parameterless request with a 4xx is still
     * correctly reported as "reachable", not "unreachable". Only a genuine
     * connection failure (DNS, TLS, timeout) counts as a failed test.
     */
    private function checkReachability(string $url): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withOptions(['verify' => false]) // matches sendSMS()'s own SSL leniency for these gateways
                ->get($url);

            return [
                'ok' => true,
                'message' => 'Reached the gateway (' . $response->status() . '). No test message was sent — fill in a test mobile number to send one.',
            ];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => 'Could not reach the gateway: ' . $exception->getMessage()];
        }
    }

    private function sendRealTest(array $config, string $url, string $mobile): array
    {
        $pram = (string) ($config['pram'] ?? '');
        $mobileVar = (string) ($config['mobile_var'] ?? '');
        $textVar = (string) ($config['text_var'] ?? '');
        $lastVar = urlencode((string) ($config['last_var'] ?? ''));

        $text = urlencode('This is a test message from your ERP\'s SMS integration settings.');

        $sendUrl = $url . $pram . $mobileVar . $mobile . $textVar . $text . $lastVar;

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withOptions(['verify' => false])
                ->get($sendUrl);

            if ($response->successful()) {
                return ['ok' => true, 'message' => 'Test SMS sent to ' . $mobile . ' (' . $response->status() . ').'];
            }

            return ['ok' => false, 'message' => 'The gateway responded with ' . $response->status() . '.'];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => 'Could not send the test SMS: ' . $exception->getMessage()];
        }
    }
}
