<?php
class KSeF_Auth {
    private string $base_url;

    public function __construct(?string $base_url = null) {
        $this->base_url = $base_url ?? KSeF_Client::get_base_url();
    }

    public function authenticate(string $nip, string $ksef_token): array {
        $challenge_data = $this->get_challenge();
        $cert_der_b64   = KSeF_Crypto::get_public_key($this->base_url);
        $encrypted_token = KSeF_Crypto::encrypt_token($ksef_token, $challenge_data['timestampMs'], $cert_der_b64);
        $result = $this->send_auth_token_request($nip, $encrypted_token, $challenge_data['challenge']);
        $reference = $result['reference'];

        if (!empty($result['authToken'])) {
            return $this->redeem_token($result['authToken']);
        }

        $auth_token = $this->wait_for_auth($reference);
        return $this->redeem_token($auth_token);
    }

    private function get_challenge(): array {
        $response = wp_remote_post($this->base_url . '/auth/challenge', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => '{}',
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) {
            throw new Exception('Challenge error: ' . $response->get_error_message());
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['challenge']) || empty($body['timestampMs'])) {
            throw new Exception('Nieprawidłowa odpowiedź challenge');
        }
        return $body;
    }

    private function send_auth_token_request(string $nip, string $encrypted_token_b64, string $challenge): array {
        $body = json_encode([
            'challenge'         => $challenge,
            'contextIdentifier' => ['type' => 'NIP', 'value' => $nip],
            'encryptedToken'    => $encrypted_token_b64,
        ]);

        $response = wp_remote_post($this->base_url . '/auth/ksef-token', [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            'body'    => $body,
            'timeout' => 60,
        ]);
        if (is_wp_error($response)) {
            throw new Exception('Auth token request failed: ' . $response->get_error_message());
        }
        $http_code = wp_remote_retrieve_response_code($response);
        $raw_body  = wp_remote_retrieve_body($response);

        error_log('[KSeF] AuthTokenRequest HTTP: ' . $http_code);
        error_log('[KSeF] Response: ' . $raw_body);

        if ($http_code !== 200 && $http_code !== 202) {
            throw new Exception("AuthTokenRequest HTTP $http_code: " . substr($raw_body, 0, 300));
        }

        $data = json_decode($raw_body, true);
        if (empty($data['referenceNumber'])) {
            throw new Exception('Brak referenceNumber');
        }

        return [
            'reference' => $data['referenceNumber'],
            'authToken' => $data['authenticationToken']['token'] ?? null,
        ];
    }

    private function wait_for_auth(string $reference, int $max_attempts = 30): string {
        for ($i = 0; $i < $max_attempts; $i++) {
            sleep(2);
            $response = wp_remote_get($this->base_url . '/auth/' . $reference, [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 30,
            ]);
            if (is_wp_error($response)) continue;
            $body   = json_decode(wp_remote_retrieve_body($response), true);
            $status = $body['processingCode'] ?? 0;
            if ($status === 200) return $body['authenticationToken'];
            if ($status >= 400) throw new Exception('Auth failed: ' . ($body['processingDescription'] ?? 'unknown'));
        }
        throw new Exception('Timeout uwierzytelniania');
    }

    private function redeem_token(string $authentication_token): array {
        $response = wp_remote_post($this->base_url . '/auth/token/redeem', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $authentication_token,
            ],
            'body'    => '{}',
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) throw new Exception('Redeem failed');
        $body = json_decode(wp_remote_retrieve_body($response), true);
        error_log('[KSeF] Redeem response: ' . json_encode($body));

        $access  = $body['accessToken']['token'] ?? $body['accessToken'] ?? null;
        $refresh = $body['refreshToken']['token'] ?? $body['refreshToken'] ?? null;

        if (empty($access)) {
            throw new Exception('Brak accessToken');
        }

        return [
            'access_token'  => $access,
            'refresh_token' => $refresh ?? '',
        ];
    }

    public function refresh_access_token(string $refresh_token): array {
        $response = wp_remote_post($this->base_url . '/auth/token/refresh', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $refresh_token,
            ],
            'body'    => '{}',
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) throw new Exception('Refresh failed');
        $body = json_decode(wp_remote_retrieve_body($response), true);

        $access  = $body['accessToken']['token'] ?? $body['accessToken'] ?? null;
        $refresh = $body['refreshToken']['token'] ?? $body['refreshToken'] ?? null;

        return [
            'accessToken'  => $access,
            'refreshToken' => $refresh ?? $refresh_token,
        ];
    }
}