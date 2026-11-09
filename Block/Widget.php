<?php

declare(strict_types=1);

namespace Agent001\Assistant\Block;

use Agent001\Assistant\Model\Config;
use Agent001\Assistant\Model\Settings;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

class Widget extends Template implements IdentityInterface
{
    public function __construct(
        Context $context,
        private readonly Settings $settings,
        private readonly Config $config,
        private readonly SecureHtmlRenderer $secureRenderer,
        array $data = [],
    ) {
        parent::__construct(context: $context, data: $data);
    }

    public function scriptHtml(): string
    {
        $attributes = $this->widget();
        if (empty($attributes)) {
            return '';
        }
        return $this->secureRenderer->renderTag(
            tagName: 'script',
            attributes: $attributes,
            content: '',
            textContent: false,
        );
    }

    public function getIdentities(): array
    {
        return ['AGENT001_' . (int) $this->_storeManager->getStore()->getId()];
    }

    private function widget(): array
    {
        $storeId = (int) $this->_storeManager->getStore()->getId();
        $setting = $this->settings->get(storeId: $storeId);
        if (empty($setting['api_key']) || empty($setting['setup_completed']) || empty($setting['active'])
            || empty($setting['embed_token']) || empty($setting['widget_key'])) {
            return [];
        }
        $site = $this->config->site(storeId: $storeId);
        if ($site !== ($setting['site'] ?? '')) {
            return [];
        }
        return [
            'src' => $this->config->baseUrl() . '/embed/loader.js',
            'data-token' => $setting['embed_token'],
            'data-plugin-key' => $setting['widget_key'],
            'data-plugin-rest' => $site . '/agent001/v1',
            'async' => 'async',
        ];
    }
}
