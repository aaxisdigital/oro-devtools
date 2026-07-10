<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Controller;

use Aaxis\Bundle\DevToolsBundle\Storage\FilesystemStorageBrowser;
use Aaxis\Bundle\DevToolsBundle\Storage\StorageBrowserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only JSON endpoints backing the Filesystem Viewer page.
 *
 * Containment (base path) and the ".." restriction are enforced in the storage layer,
 * regardless of what the front-end sends.
 */
class FilesystemViewerController extends AbstractStorageBrowserController
{
    #[Route(path: '/filesystem-viewer/list', name: 'aaxis_devtools_filesystem_viewer_list', methods: ['GET'])]
    public function listAction(Request $request): JsonResponse
    {
        return $this->doList($request);
    }

    #[Route(path: '/filesystem-viewer/preview', name: 'aaxis_devtools_filesystem_viewer_preview', methods: ['GET'])]
    public function previewAction(Request $request): JsonResponse
    {
        return $this->doPreview($request);
    }

    #[Route(path: '/filesystem-viewer/raw', name: 'aaxis_devtools_filesystem_viewer_raw', methods: ['GET'])]
    public function rawAction(Request $request): Response
    {
        return $this->doStream($request, $this->disposition('inline'));
    }

    #[Route(path: '/filesystem-viewer/download', name: 'aaxis_devtools_filesystem_viewer_download', methods: ['GET'])]
    public function downloadAction(Request $request): Response
    {
        return $this->doStream($request, $this->disposition('attachment'));
    }

    #[\Override]
    protected function storage(): StorageBrowserInterface
    {
        return $this->container->get(FilesystemStorageBrowser::class);
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), self::sharedSubscribedServices(), [
            FilesystemStorageBrowser::class,
        ]);
    }
}
