<?php

declare(strict_types=1);

namespace Agent001\Assistant\Controller\Adminhtml\Settings;

use Agent001\Assistant\Model\Channels;
use Agent001\Assistant\Model\Onboarding;
use Agent001\Assistant\Model\Settings;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Agent001_Assistant::settings';

    /**
     * @param Context $context
     * @param PageFactory $pages
     * @param Channels $channels
     * @param Settings $settings
     * @param Onboarding $onboarding
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $pages,
        private readonly Channels $channels,
        private readonly Settings $settings,
        private readonly Onboarding $onboarding,
    ) {
        parent::__construct($context);
    }

    /**
     * Render the Agent001 settings page.
     */
    public function execute()
    {
        $this->onboarding->sync(
            connected: !empty($this->settings->apiKey()),
            channels: $this->channels->settings(),
        );
        $page = $this->pages->create();
        $page->setActiveMenu('Agent001_Assistant::settings');
        $page->getConfig()->getTitle()->prepend('Agent001');
        return $page;
    }
}
