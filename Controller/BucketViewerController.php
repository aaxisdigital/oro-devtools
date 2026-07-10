<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Controller;

use Aaxis\Bundle\DevToolsBundle\Storage\BucketStorageBrowser;
use Aaxis\Bundle\DevToolsBundle\Storage\StorageBrowserInterface;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only JSON endpoints backing the Bucket Viewer page (S3-compatible storage).
 *
 * Shares all browsing logic with the Filesystem Viewer via {@see AbstractStorageBrowserController};
 * only the storage implementation differs.
 */
class BucketViewerController extends AbstractStorageBrowserController
{
    #[Route(path: '/bucket-viewer/list', name: 'aaxis_devtools_bucket_viewer_list', methods: ['GET'])]
    public function listAction(Request $request): JsonResponse
    {
        return $this->doList($request);
    }

    #[Route(path: '/bucket-viewer/preview', name: 'aaxis_devtools_bucket_viewer_preview', methods: ['GET'])]
    public function previewAction(Request $request): JsonResponse
    {
        return $this->doPreview($request);
    }

    #[Route(path: '/bucket-viewer/raw', name: 'aaxis_devtools_bucket_viewer_raw', methods: ['GET'])]
    public function rawAction(Request $request): Response
    {
        return $this->doStream($request, $this->disposition('inline'));
    }

    #[Route(path: '/bucket-viewer/download', name: 'aaxis_devtools_bucket_viewer_download', methods: ['GET'])]
    public function downloadAction(Request $request): Response
    {
        return $this->doStream($request, $this->disposition('attachment'));
    }

    #[Route(path: '/bucket-viewer/folder', name: 'aaxis_devtools_bucket_viewer_folder', methods: ['POST'])]
    #[CsrfProtection]
    #[AclAncestor('aaxis_devtools_write')]
    public function createFolderAction(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid payload.'], 400);
        }

        try {
            $this->bucketStorage()->createFolder((string) ($payload['path'] ?? ''), (string) ($payload['name'] ?? ''));
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route(path: '/bucket-viewer/upload', name: 'aaxis_devtools_bucket_viewer_upload', methods: ['POST'])]
    #[CsrfProtection]
    #[AclAncestor('aaxis_devtools_write')]
    public function uploadAction(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if ($file === null) {
            return new JsonResponse(['success' => false, 'message' => 'No file uploaded.'], 400);
        }

        try {
            $this->bucketStorage()->uploadFile(
                (string) $request->request->get('path', ''),
                $file->getPathname(),
                $file->getClientOriginalName(),
                $file->getMimeType() ?: 'application/octet-stream'
            );
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route(path: '/bucket-viewer/delete', name: 'aaxis_devtools_bucket_viewer_delete', methods: ['POST'])]
    #[CsrfProtection]
    #[AclAncestor('aaxis_devtools_write')]
    public function deleteAction(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid payload.'], 400);
        }

        try {
            $this->bucketStorage()->delete((string) ($payload['path'] ?? ''), ($payload['type'] ?? '') === 'dir');
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['success' => true]);
    }

    #[\Override]
    protected function storage(): StorageBrowserInterface
    {
        return $this->container->get(BucketStorageBrowser::class);
    }

    private function bucketStorage(): BucketStorageBrowser
    {
        return $this->container->get(BucketStorageBrowser::class);
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), self::sharedSubscribedServices(), [
            BucketStorageBrowser::class,
        ]);
    }
}
