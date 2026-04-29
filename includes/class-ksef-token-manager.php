<?php
class KSeF_Token_Manager {
    private string $encryption_key;

    public function __construct() {
        $this->encryption_key = hash('sha256', SECURE_AUTH_KEY . 'ksef_v2');
    }

    public function is_configured(): bool {
        return !empty(get_option('ksef_nip')) && !empty($this->get_stored_token());
    }

    public function save_tokens(string $access_token, string $refresh_token, int $expires_in = 900): void {
        update_option('ksef_access_token', $this->encrypt($access_token));
        update_option('ksef_refresh_token', $this->encrypt($refresh_token));
        update_option('ksef_token_expires', time() + $expires_in);
    }

    public function save_token(string $plain_token): void {
        update_option('ksef_token', $this->encrypt($plain_token));
    }

    public function get_stored_token(): string {
        return $this->decrypt(get_option('ksef_token', ''));
    }

    public function get_valid_access_token(): string {
        if ($this->is_access_token_valid()) {
            return $this->decrypt(get_option('ksef_access_token'));
        }
        return $this->refresh();
    }

    private function is_access_token_valid(): bool {
        $expires = (int) get_option('ksef_token_expires', 0);
        return time() < ($expires - 60);
    }

    private function refresh(): string {
        $refresh_token = $this->decrypt(get_option('ksef_refresh_token'));
        $auth = new KSeF_Auth();
        $new_tokens = $auth->refresh_access_token($refresh_token);
        if (empty($new_tokens['accessToken'])) {
            throw new Exception('Nie udało się odświeżyć tokena');
        }
        $this->save_tokens(
            $new_tokens['accessToken'],
            $new_tokens['refreshToken'] ?? $refresh_token,
            900
        );
        return $new_tokens['accessToken'];
    }

    private function encrypt(string $data): string {
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $this->encryption_key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    private function decrypt(string $data): string {
        $decoded   = base64_decode($data);
        $iv        = substr($decoded, 0, 16);
        $encrypted = substr($decoded, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $this->encryption_key, 0, $iv);
    }
}