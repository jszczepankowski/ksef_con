<?php
class KSeF_Client {
    public static function get_base_url(): string {
        $env = get_option('ksef_environment', 'test');
        return $env === 'production'
            ? 'https://api.ksef.mf.gov.pl/api/v2'
            : 'https://api-test.ksef.mf.gov.pl/api/v2';
    }

    public function test_connectivity(): bool {
        $response = wp_remote_post(self::get_base_url() . '/auth/challenge', [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => '{}',
            'timeout' => 20,
        ]);
        if (is_wp_error($response)) return false;
        if (wp_remote_retrieve_response_code($response) !== 200) return false;
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return isset($body['challenge'], $body['timestampMs']);
    }
}