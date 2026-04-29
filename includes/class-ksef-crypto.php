<?php
class KSeF_Crypto {
    public static function get_public_key(string $base_url): string {
        $response = wp_remote_get($base_url . '/security/public-key-certificates', [
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 30,
        ]);
        if (is_wp_error($response)) {
            throw new Exception('Nie udało się pobrać klucza: ' . $response->get_error_message());
        }
        $http_code = wp_remote_retrieve_response_code($response);
        $raw_body  = wp_remote_retrieve_body($response);
        if ($http_code !== 200) {
            throw new Exception("Endpoint certyfikatów zwrócił HTTP $http_code");
        }
        $data = json_decode($raw_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Nieprawidłowa odpowiedź JSON z endpointu certyfikatów');
        }
        $cert_value = null;
        if (is_array($data) && isset($data[0]['certificate'])) {
            $cert_value = $data[0]['certificate'];
        }
        if ($cert_value === null && isset($data['certificates']) && is_array($data['certificates'])) {
            foreach ($data['certificates'] as $cert) {
                if (!empty($cert['certificate'])) { $cert_value = $cert['certificate']; break; }
                if (!empty($cert['value']))       { $cert_value = $cert['value'];       break; }
            }
        }
        if ($cert_value === null && is_array($data) && isset($data[0]['value'])) {
            $cert_value = $data[0]['value'];
        }
        if ($cert_value === null && !empty($data['value'])) {
            $cert_value = $data['value'];
        }
        if ($cert_value === null && !empty($data['certificate'])) {
            $cert_value = $data['certificate'];
        }
        if ($cert_value === null) {
            throw new Exception('Brak certyfikatów w odpowiedzi. Odpowiedź: ' . substr($raw_body, 0, 200));
        }
        return $cert_value;
    }

    public static function encrypt_token(string $ksef_token, int $timestamp_ms, string $cert_der_base64): string {
        $cert_der   = base64_decode($cert_der_base64);
        $public_key = \phpseclib3\Crypt\PublicKeyLoader::load($cert_der);
        $rsa = \phpseclib3\Crypt\RSA::loadPublicKey($public_key->toString('PKCS8'))
            ->withPadding(\phpseclib3\Crypt\RSA::ENCRYPTION_OAEP)
            ->withHash('sha256')
            ->withMGFHash('sha256');
        $plaintext = $ksef_token . '|' . $timestamp_ms;
        return base64_encode($rsa->encrypt($plaintext));
    }
}