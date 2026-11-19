<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Tests\Unit\Async;

use Aaxis\Bundle\DevToolsBundle\Async\BucketFolderArchiveProcessor;
use Aaxis\Bundle\DevToolsBundle\Async\Topic\BucketFolderArchiveTopic;
use Aaxis\Bundle\DevToolsBundle\Exception\StorageException;
use Aaxis\Bundle\DevToolsBundle\Storage\BucketFolderArchiver;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;
use Symfony\Component\OptionsResolver\OptionsResolver;

class BucketFolderArchiveProcessorTest extends TestCase
{
    private BucketFolderArchiver&MockObject $archiver;
    private LoggerInterface&MockObject $logger;
    private BucketFolderArchiveProcessor $processor;

    protected function setUp(): void
    {
        $this->archiver = $this->createMock(BucketFolderArchiver::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->processor = new BucketFolderArchiveProcessor($this->archiver, $this->logger);
    }

    private function process(mixed $body): string
    {
        $message = $this->createMock(MessageInterface::class);
        $message->method('getBody')->willReturn($body);

        return $this->processor->process($message, $this->createMock(SessionInterface::class));
    }

    public function testSubscribesToTheArchiveTopic(): void
    {
        self::assertSame(['aaxis_devtools.bucket_folder_archive'], BucketFolderArchiveProcessor::getSubscribedTopics());
    }

    public function testGeneratesTheArchiveAndAcknowledges(): void
    {
        $this->archiver->expects(self::once())->method('generateArchive')->with('abc', 'r/all.zip')->willReturn(3);
        $this->archiver->expects(self::never())->method('reportFailure');
        $this->logger->expects(self::once())->method('info')
            ->with(self::anything(), ['targetKey' => 'r/all.zip', 'files' => 3]);

        self::assertSame(MessageProcessorInterface::ACK, $this->process(['scanId' => 'abc', 'targetKey' => 'r/all.zip']));
    }

    public function testAFailureIsLoggedReportedInTheFolderAndRejected(): void
    {
        $error = new StorageException('Disk full');
        $this->archiver->method('generateArchive')->willThrowException($error);
        $this->archiver->expects(self::once())->method('reportFailure')->with('r/all.zip', 'Disk full');
        $this->logger->expects(self::once())->method('error')
            ->with(self::stringContains('could not be generated'), ['targetKey' => 'r/all.zip', 'exception' => $error]);

        self::assertSame(MessageProcessorInterface::REJECT, $this->process(['scanId' => 'abc', 'targetKey' => 'r/all.zip']));
    }

    public function testAFailureToWriteTheNoteIsLoggedToo(): void
    {
        $this->archiver->method('generateArchive')->willThrowException(new StorageException('first'));
        $this->archiver->method('reportFailure')->willThrowException(new StorageException('read-only'));
        $this->logger->expects(self::exactly(2))->method('error');

        self::assertSame(MessageProcessorInterface::REJECT, $this->process([]));
    }

    public function testTheTopicRequiresAScanIdAndATargetKey(): void
    {
        $topic = new BucketFolderArchiveTopic();
        $resolver = new OptionsResolver();
        $topic->configureMessageBody($resolver);

        self::assertSame('aaxis_devtools.bucket_folder_archive', BucketFolderArchiveTopic::getName());
        self::assertNotSame('', BucketFolderArchiveTopic::getDescription());
        self::assertSame(['scanId' => 'a', 'targetKey' => 'b'], $resolver->resolve(['scanId' => 'a', 'targetKey' => 'b']));

        $this->expectException(MissingOptionsException::class);
        $resolver->resolve(['scanId' => 'a']);
    }

    public function testTheTopicRejectsNonStringValues(): void
    {
        $resolver = new OptionsResolver();
        (new BucketFolderArchiveTopic())->configureMessageBody($resolver);

        $this->expectException(InvalidOptionsException::class);
        $resolver->resolve(['scanId' => 1, 'targetKey' => 'b']);
    }
}
