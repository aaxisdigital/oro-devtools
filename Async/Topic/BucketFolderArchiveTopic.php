<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Async\Topic;

use Oro\Component\MessageQueue\Topic\AbstractTopic;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Builds the ZIP of a scanned Bucket Viewer folder and uploads it into that folder.
 */
class BucketFolderArchiveTopic extends AbstractTopic
{
    #[\Override]
    public static function getName(): string
    {
        return 'aaxis_devtools.bucket_folder_archive';
    }

    #[\Override]
    public static function getDescription(): string
    {
        return 'Builds the ZIP of a scanned Bucket Viewer folder and uploads it into that folder.';
    }

    #[\Override]
    public function configureMessageBody(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired(['scanId', 'targetKey'])
            ->setAllowedTypes('scanId', 'string')
            ->setAllowedTypes('targetKey', 'string');
    }
}
