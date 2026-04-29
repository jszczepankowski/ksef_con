<?php
class KSeF_Invoices {
    private KSeF_Client $client;

    public function __construct(KSeF_Client $client) {
        $this->client = $client;
    }

    public function download_invoice(string $access_token, string $ksef_number): string {
        $response = wp_remote_get(
            $this->client->get_base_url() . '/invoices/ksef/' . urlencode($ksef_number),
            [
                'headers' => ['Authorization' => 'Bearer ' . $access_token, 'Accept' => 'application/octet-stream'],
                'timeout' => 60,
            ]
        );
        if (is_wp_error($response)) throw new Exception('Download error: ' . $response->get_error_message());
        if (wp_remote_retrieve_response_code($response) !== 200) throw new Exception('HTTP error');
        return wp_remote_retrieve_body($response);
    }

    public function query_invoices(
        string $access_token,
        string $date_from,
        string $date_to,
        string $subject_type,
        int $page_size = 100,
        ?string $page_offset = null
    ): array {
        $body = [
            'filters' => [
                'subjectType' => $subject_type,
                'dateRange'   => [
                    'dateType' => 'PermanentStorage',
                    'from'     => $date_from . '.000+00:00',
                    'to'       => $date_to . '.000+00:00',
                    'restrictToPermanentStorageHwmDate' => true,
                ],
            ],
            'queryCriteria' => [
                'pageSize'   => $page_size,
                'pageOffset' => $page_offset,
            ],
        ];

        $json_body = json_encode($body);
        
        // Diagnostyka: pokaż dokładnie wysyłany ciąg
        error_log('[KSeF] Surowe ciało żądania: ' . $json_body);
        error_log('[KSeF] Długość ciała: ' . strlen($json_body));
        
        // Użycie wp_remote_request z jawnym ustawieniem Content-Type
        $response = wp_remote_request($this->client->get_base_url() . '/invoices/query/metadata', [
            'method'  => 'POST',
            'headers' => [
                'Content-Type'  => 'application/json; charset=utf-8',
                'Authorization' => 'Bearer ' . $access_token,
                'Accept'        => 'application/json',
            ],
            'body'    => $json_body,
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) throw new Exception('Query error: ' . $response->get_error_message());

        $http_code = wp_remote_retrieve_response_code($response);
        $raw_body  = wp_remote_retrieve_body($response);
        error_log("[KSeF] Query HTTP $http_code dla $subject_type (offset: $page_offset): " . substr($raw_body, 0, 1000));

        if ($http_code !== 200) {
            throw new Exception("Query HTTP $http_code: " . substr($raw_body, 0, 300));
        }

        return json_decode($raw_body, true);
    }

    public function query_all_invoices(
        string $access_token,
        string $date_from,
        string $date_to,
        string $subject_type
    ): array {
        $all = [];
        $offset = null;
        do {
            $result   = $this->query_invoices($access_token, $date_from, $date_to, $subject_type, 100, $offset);
            $invoices = $result['invoices'] ?? [];
            $all      = array_merge($all, $invoices);
            $offset   = $result['nextPageOffset'] ?? null;
            usleep(200000);
        } while ($offset !== null);
        return $all;
    }
}