<?php

declare(strict_types=1);

namespace OCA\StarRate\BackgroundJob;

use OCA\StarRate\Service\GuestActivityNotifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Alle 5 Minuten: gesammelte Gast-Bewertungen als eine Benachrichtigung pro Gast melden.
 */
class GuestActivityNotifyJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private readonly GuestActivityNotifier $notifier,
    ) {
        parent::__construct($time);
        $this->setInterval(300);
        $this->setTimeSensitivity(IJob::TIME_SENSITIVE);
    }

    protected function run($argument): void
    {
        $this->notifier->run();
    }
}
