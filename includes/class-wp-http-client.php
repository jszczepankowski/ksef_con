<?php
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class WP_Http_Client implements ClientInterface {
    public function sendRequest(RequestInterface $request): ResponseInterface {
        $args = [
            'method'  => $request->getMethod(),
            'headers' => $request->getHeaders(),
            'body'    => (string) $request->getBody(),
            'timeout' => 60,
        ];

        $response = wp_remote_request((string) $request->getUri(), $args);

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $response_body = wp_remote_retrieve_body($response);
        $response_code = wp_remote_retrieve_response_code($response);
        $response_headers = wp_remote_retrieve_headers($response);

        return new \Nyholm\Psr7\Response(
            $response_code,
            $response_headers->getAll(),
            $response_body
        );
    }
}