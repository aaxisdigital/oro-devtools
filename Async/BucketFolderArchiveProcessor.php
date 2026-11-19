<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Async;

use Aaxis\Bundle\DevToolsBundle\Async\Topic\BucketFolderArchiveTopic;
use Aaxis\Bundle\DevToolsBundle\Storage\BucketFolderArchiver;
use Oro\Component\MessageQueue\Client\TopicSubscriberInterface;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs a Bucket Viewer "download folder" archive that is too large for a direct download. Failures are
 * logged and reported next to the expected archive ("<name>.error.txt"); they are not retried, since a
 * retry would repeat the whole (possibly long) transfer.
 */
class BucketFolderArchiveProcessor implements MessageProcessorInterface, TopicSubscriberInterface
{
    public function __construct(
        private readonly BucketFolderArchiver $archiver,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public static function getSubscribedTopics(): array
    {
        return [BucketFolderArchiveTopic::getName()];
    }

    #[\Override]
    public function process(MessageInterface $message, SessionInterface $session): string
    {
        $body = $message->getBody();
        $scanId = (string) ($body['scanId'] ?? '');
        $targetKey = (string) ($body['targetKey'] ?? '');

        try {
            $files = $this->archiver->generateArchive($scanId, $targetKey);
        } catch (\Throwable $e) {
            $this->logger->error('Aaxis Dev Tools: the bucket folder archive could not be generated.', [
                'targetKey' => $targetKey,
                'exception' => $e,
            ]);
            try {
                $this->archiver->reportFailure($targetKey, $e->getMessage());
            } catch (\Throwable $reportError) {
                $this->logger->error('Aaxis Dev Tools: the archive failure note could not be written.', [
                    'targetKey' => $targetKey,
                    'exception' => $reportError,
                ]);
            }

            return self::REJECT;
        }

        $this->logger->info('Aaxis Dev Tools: bucket folder archive generated.', ['targetKey' => $targetKey, 'files' => $files]);

        return self::ACK;
    }
}
