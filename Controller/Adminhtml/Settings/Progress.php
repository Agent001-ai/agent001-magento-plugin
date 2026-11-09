<?php

declare(strict_types=1);

namespace Agent001\Assistant\Controller\Adminhtml\Settings;

use Agent001\Assistant\Model\Channels;
use Agent001\Assistant\Model\Onboarding;
use Agent001\Assistant\Model\Settings;
use Agent001\Assistant\Model\Sync\Queue;
use IntlDateFormatter;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

class Progress extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Agent001_Assistant::settings';

    public function __construct(
        Context $context,
        private readonly Channels $channels,
        private readonly Settings $settings,
        private readonly Queue $queue,
        private readonly Onboarding $onboarding,
        private readonly JsonFactory $json,
        private readonly TimezoneInterface $timezone,
    ) {
        parent::__construct(context: $context);
    }

    public function execute()
    {
        $settings = $this->channels->settings();
        $progress = $this->onboarding->sync(
            connected: !empty($this->settings->apiKey()),
            channels: $settings,
        );
        $channels = [];
        foreach ($settings as $storeId => $setting) {
            $date = (string) ($setting['last_sync_at'] ?? '');
            $channels[$storeId] = [
                'status' => $setting['status'] ?? 'idle',
                'label' => (string) __($setting['status'] ?? 'idle'),
                'count' => (int) ($setting['count'] ?? 0),
                'message' => (string) __($setting['message'] ?? ''),
                'last_sync_at' => empty($date) ? '' : $this->timezone->formatDateTime(
                    date: $date,
                    dateType: IntlDateFormatter::MEDIUM,
                    timeType: IntlDateFormatter::SHORT,
                ),
                'failed_jobs' => $this->queue->failures(storeId: $storeId),
                'stalled' => $this->queue->stalled(storeId: $storeId),
            ];
        }
        return $this->json->create()
            ->setHeader(name: 'Cache-Control', value: 'no-store', replace: true)
            ->setData(data: ['step' => $progress['step'], 'channels' => $channels]);
    }
}
