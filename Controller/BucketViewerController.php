<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Controller;

use Aaxis\Bundle\DevToolsBundle\Storage\BucketFolderArchiver;
use Aaxis\Bundle\DevToolsBundle\Storage\BucketStorageBrowser;
use Aaxis\Bundle\DevToolsBundle\Storage\ObjectKey;
use Aaxis\Bundle\DevToolsBundle\Storage\StorageBrowserInterface;
use Oro\Bundle\SecurityBundle\Attribute\AclAncestor;
use Oro\Bundle\SecurityBundle\Attribute\CsrfProtection;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON endpoints backing the Bucket Viewer page (S3-compatible storage).
 *
 * Shares all browsing logic with the Filesystem Viewer via {@see AbstractStorageBrowserController};
 * only the storage implementation differs. Adds the bucket-only write actions (create folder, upload,
 * delete — gated by the aaxis_devtools_write capability) and "download folder" as a ZIP
 * ({@see BucketFolderArchiver}).
 */
class BucketViewerController extends AbstractStorageBrowserController
{
    private const string INVALID_PAYLOAD = 'Invalid payload.';

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
            return new JsonResponse(['success' => false, 'message' => self::INVALID_PAYLOAD], 400);
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
            return new JsonResponse(['success' => false, 'message' => self::INVALID_PAYLOAD], 400);
        }

        try {
            $this->bucketStorage()->delete((string) ($payload['path'] ?? ''), ($payload['type'] ?? '') === 'dir');
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['success' => true]);
    }

    /**
     * "Download folder", step 1: starts a recursive scan of {path} or, given {id}, reads its next page.
     * The client calls it until "complete" is true, showing the running file count / size.
     */
    #[Route(path: '/bucket-viewer/archive/scan', name: 'aaxis_devtools_bucket_viewer_archive_scan', methods: ['POST'])]
    #[CsrfProtection]
    public function archiveScanAction(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => self::INVALID_PAYLOAD], 400);
        }

        try {
            $id = (string) ($payload['id'] ?? '');
            $scan = $id !== ''
                ? $this->archiver()->continueScan($id, $this->owner())
                : $this->archiver()->startScan((string) ($payload['path'] ?? ''), $this->owner());
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['success' => true] + $scan);
    }

    /**
     * "Download folder", step 2 for a small folder: the ZIP of the scanned files, built in the request.
     */
    #[Route(path: '/bucket-viewer/archive/download', name: 'aaxis_devtools_bucket_viewer_archive_download', methods: ['GET'])]
    public function archiveDownloadAction(Request $request): Response
    {
        try {
            $archive = $this->archiver()->buildDirectArchive((string) $request->query->get('id', ''), $this->owner());
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $response = new BinaryFileResponse($archive['file'], 200, [
            'Content-Type' => 'application/zip',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $archive['name'],
            // ASCII fallback for clients without RFC 6266 filename* support ("/", "\\" and "%" are not allowed in it).
            (string) preg_replace('/[^\x20-\x7e]/', '_', str_replace(['%', '/', '\\'], '_', $archive['name']))
        );
        $response->deleteFileAfterSend();

        return $response;
    }

    /**
     * "Download folder", step 2 for a large folder: queues a background job that writes the ZIP into
     * the scanned folder as {name}. Writes to the bucket, so it needs the write capability.
     */
    #[Route(path: '/bucket-viewer/archive/generate', name: 'aaxis_devtools_bucket_viewer_archive_generate', methods: ['POST'])]
    #[CsrfProtection]
    #[AclAncestor('aaxis_devtools_write')]
    public function archiveGenerateAction(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['success' => false, 'message' => self::INVALID_PAYLOAD], 400);
        }

        try {
            $key = $this->archiver()->requestArchive(
                (string) ($payload['id'] ?? ''),
                $this->owner(),
                (string) ($payload['name'] ?? '')
            );
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['success' => true, 'key' => $key, 'name' => ObjectKey::basename($key)]);
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

    private function archiver(): BucketFolderArchiver
    {
        return $this->container->get(BucketFolderArchiver::class);
    }

    /**
     * Folder scans belong to the user who started them.
     */
    private function owner(): string
    {
        $userId = $this->container->get(TokenAccessorInterface::class)->getUserId();
        if ($userId === null) {
            throw $this->createAccessDeniedException('A logged-in user is required.');
        }

        return 'user:' . $userId;
    }

    #[\Override]
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), self::sharedSubscribedServices(), [
            BucketStorageBrowser::class,
            BucketFolderArchiver::class,
            TokenAccessorInterface::class,
        ]);
    }
}
