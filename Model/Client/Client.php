<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Client;

use Agent001\Assistant\Model\Config;
use Exception;
use Magento\Framework\HTTP\Client\CurlFactory;
use RuntimeException;

class Client
{
    public const VERSION = '1.0.0';

    public function __construct(private readonly Config $config, private readonly CurlFactory $curlFactory)
    {
    }

    public function request(
        string $method,
        string $path,
        string $apiKey = '',
        ?array $data = null,
        array $headers = [],
        string $channel = '',
    ): array {
        $headers = ['Accept' => 'application/json', 'Content-Type' => 'application/json',
            'User-Agent' => 'Agent001-Magento/' . self::VERSION] + $headers;
        if (!empty($channel)) {
            $headers['X-Channel-Code'] = $channel;
        }
        if (!empty($apiKey)) {
            $headers['X-API-Key'] = $apiKey;
        }
        $body = $data !== null ? json_encode(value: $data, flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : '';
        if (strlen(string: $body) > 8388608) {
            throw new RuntimeException(message: 'The Agent001 payload exceeds 8 MB.');
        }
        $url = $this->config->baseUrl(internal: true) . $path;
        $status = 0;
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            [$status, $response] = $this->send(method: $method, url: $url, headers: $headers, body: $body);
            if ($status >= 200 && $status < 300) {
                return $this->decode(response: $response);
            }
            if ($status > 0 && $status < 500 && $status !== 429) {
                break;
            }
            if ($attempt < 2) {
                usleep(microseconds: 500000 * (2 ** $attempt));
            }
        }
        throw new RuntimeException(message: 'Agent001 request failed (HTTP ' . $status . ').', code: $status);
    }

    /**
     * @param array<string, string> $headers
     * @return array{int, string}
     */
    private function send(string $method, string $url, array $headers, string $body): array
    {
        $curl = $this->curlFactory->create();
        $curl->setHeaders(headers: $headers);
        $curl->setTimeout(value: 30);
        $curl->setOptions(arr: [
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        try {
            if ($method === 'GET') {
                $curl->get(uri: $url);
            } else {
                $curl->setOption(name: CURLOPT_CUSTOMREQUEST, value: $method);
                $curl->post(uri: $url, params: $body);
            }
        } catch (Exception) {
            return [0, ''];
        }
        return [(int) $curl->getStatus(), (string) $curl->getBody()];
    }

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
