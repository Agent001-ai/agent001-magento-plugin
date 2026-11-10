<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Client;

use Agent001\Assistant\Model\Config;
use Exception;
use Magento\Framework\HTTP\Client\CurlFactory;
use RuntimeException;

class Client
{
    public const VERSION = '1.1.0';

    public const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param Config $config
     * @param CurlFactory $curlFactory
     */
    public function __construct(private readonly Config $config, private readonly CurlFactory $curlFactory)
    {
    }

    /**
     * Send a request to the Agent001 API with retries.
     *
     * @param string $method
     * @param string $path
     * @param string $apiKey
     * @param array|null $data
     * @param array $headers
     * @param string $channel
     * @param int $attempts
     * @param int $timeout
     */
    public function request(
        string $method,
        string $path,
        string $apiKey = '',
        ?array $data = null,
        array $headers = [],
        string $channel = '',
        int $attempts = 3,
        int $timeout = 30,
    ): array {
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json',
            'User-Agent' => 'Agent001-Magento/' . self::VERSION] + $headers;
        if (!empty($channel)) {
            $headers['X-Channel-Code'] = $channel;
        }
        if (!empty($apiKey)) {
            $headers['X-API-Key'] = $apiKey;
        }
        $body = $data !== null ? json_encode(value: $data, flags: self::JSON_FLAGS) : '';
        if (strlen(string: $body) > 8388608) {
            throw new RuntimeException(message: 'The Agent001 payload exceeds 8 MB.');
        }
        $url = $this->config->baseUrl(internal: true) . $path;
        $status = 0;
        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            [$status, $response] = $this->send(
                method: $method,
                url: $url,
                headers: $headers,
                body: $body,
                timeout: $timeout,
            );
            if ($status >= 200 && $status < 300) {
                return $this->decode(response: $response);
            }
            if ($status === 401) {
                throw new UnauthorizedException(message: 'The Agent001 API key was rejected.', code: $status);
            }
            if ($status > 0 && $status < 500 && $status !== 429) {
                break;
            }
            if ($attempt < $attempts - 1) {
                usleep(microseconds: 500000 * (2 ** $attempt));
            }
        }
        throw new RuntimeException(message: 'Agent001 request failed (HTTP ' . $status . ').', code: $status);
    }

    /**
     * Send a single HTTP request.
     *
     * @param string $method
     * @param string $url
     * @param array<string,string> $headers
     * @param string $body
     * @param int $timeout
     * @return array{int, string}
     */
    private function send(string $method, string $url, array $headers, string $body, int $timeout): array
    {
        $curl = $this->curlFactory->create();
        $curl->setHeaders($headers);
        $curl->setTimeout($timeout);
        $curl->setOptions([
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        try {
            if ($method === 'GET') {
                $curl->get($url);
            } else {
                $curl->setOption(CURLOPT_CUSTOMREQUEST, $method);
                $curl->post($url, $body);
            }
        } catch (Exception) {
            return [0, ''];
        }
        return [(int) $curl->getStatus(), (string) $curl->getBody()];
    }

    /**
     * Decode a JSON response.
     *
     * @param string $response
     */
    private function decode(string $response): array
    {
        if (empty($response)) {
            return [];
        }
        $decoded = json_decode(json: $response, associative: true, flags: JSON_THROW_ON_ERROR);
        if (!is_array(value: $decoded)) {
            throw new RuntimeException(message: 'Invalid Agent001 response.');
        }
        return $decoded;
    }
}
