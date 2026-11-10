<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Order;

class Tracking
{
    private const CARRIERS = [
        'inpost' => ['inpost|paczkomat', 'https://inpost.pl/sledzenie-przesylek?number='],
        'dpd' => ['\bdpd\b', 'https://tracktrace.dpd.com.pl/parcelDetails?p1='],
        'dhl' => ['\bdhl\b', 'https://www.dhl.com/pl-pl/home/sledzenie-przesylek.html?tracking-id='],
        'pocztapolska' => ['poczta|pocztex|envelo', 'https://emonitoring.poczta-polska.pl/?numer='],
        'gls' => ['\bgls\b', 'https://gls-group.com/PL/pl/sledzenie-paczek?match='],
        'ups' => ['\bups\b', 'https://www.ups.com/track?tracknum='],
        'fedex' => ['fedex', 'https://www.fedex.com/fedextrack/?trknbr='],
        'orlen' => ['orlen', 'https://www.orlenpaczka.pl/sledz-paczke/?numer='],
    ];

    /**
     * Return a tracking entry with its carrier and URL.
     *
     * @param string $number
     * @param string $description
     */
    public function entry(string $number, string $description): array
    {
        if (filter_var(value: $number, filter: FILTER_VALIDATE_URL)) {
            $parts = parse_url(url: $number);
            $host = strtolower(string: $parts['host'] ?? '');
            $domains = ['inpost.pl' => 'inpost', 'dpd.com.pl' => 'dpd', 'dhl.com' => 'dhl',
                'poczta-polska.pl' => 'poczta', 'gls-group.com' => 'gls', 'ups.com' => 'ups',
                'fedex.com' => 'fedex', 'orlenpaczka.pl' => 'orlen'];
            foreach ($domains as $domain => $label) {
                if ($host !== $domain && !str_ends_with(haystack: $host, needle: '.' . $domain)) {
                    continue;
                }
                $query = [];
                parse_str(string: $parts['query'] ?? '', result: $query);
                foreach (['number', 'numer', 'tracking-id', 'trknbr', 'tracknum', 'p1', 'match'] as $key) {
                    if (isset($query[$key]) && is_string(value: $query[$key]) && !empty($query[$key])) {
                        return $this->entry(number: $query[$key], description: $label);
                    }
                }
            }
            return ['number' => $number, 'carrier' => '', 'url' => ''];
        }
        $carrier = '';
        $url = '';
        foreach (self::CARRIERS as $name => [$pattern, $base]) {
            if (preg_match(pattern: '/' . $pattern . '/i', subject: $description)) {
                $carrier = $name;
                $url = $base . rawurlencode(string: $number);
                break;
            }
        }
        return ['number' => $number, 'carrier' => $carrier, 'url' => $url];
    }
}
