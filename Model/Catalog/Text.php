<?php

declare(strict_types=1);

namespace Agent001\Assistant\Model\Catalog;

use Magento\Catalog\Helper\Output;
use Magento\Catalog\Model\Product;
use Psr\Log\LoggerInterface;
use Throwable;

class Text
{
    private const DIRECTIVE = '/\{\{[^{}]*\}\}/';

    /**
     * @param Output $output
     * @param LoggerInterface $logger
     */
    public function __construct(private readonly Output $output, private readonly LoggerInterface $logger)
    {
    }

    /**
     * Return attribute HTML with Magento directives rendered for the storefront.
     *
     * @param Product $product
     * @param string $code
     */
    public function html(Product $product, string $code): string
    {
        $html = (string) $product->getData($code);
        if (empty(trim(string: $html))) {
            return '';
        }
        try {
            $html = (string) $this->output->productAttribute($product, $html, $code);
        } catch (Throwable $error) {
            $this->logger->warning('Agent001 could not render product content.', [
                'product_id' => (int) $product->getId(), 'attribute' => $code,
                'exception_class' => $error::class, 'message' => $error->getMessage(),
            ]);
        }
        return trim(string: (string) preg_replace(pattern: self::DIRECTIVE, replacement: '', subject: $html));
    }

    /**
     * Convert HTML to plain text without styles, scripts and entities.
     *
     * @param string $html
     */
    public function plain(string $html): string
    {
        $text = mb_scrub(string: $html, encoding: 'UTF-8');
        $text = (string) preg_replace(pattern: '~<(script|style)\b[^>]*>.*?</\1>~is', replacement: ' ', subject: $text);
        $text = (string) preg_replace(pattern: self::DIRECTIVE, replacement: '', subject: $text);
        $text = html_entity_decode(
            string: strip_tags(string: $text),
            flags: ENT_QUOTES | ENT_HTML5,
            encoding: 'UTF-8',
        );
        return trim(string: (string) preg_replace(pattern: '/\s+/u', replacement: ' ', subject: $text));
    }
}
