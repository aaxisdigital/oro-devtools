<?php

declare(strict_types=1);

namespace Aaxis\Bundle\DevToolsBundle\Controller;

use Aaxis\Bundle\DevToolsBundle\Storage\StorageBrowserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared read-only browsing endpoints (list / preview / raw / download) used by both the
 * Filesystem Browser and the Bucket Browser. Subclasses only supply the storage implementation
 * and declare the routes.
 */
abstract class AbstractStorageBrowserController extends AbstractController
{
    abstract protected function storage(): StorageBrowserInterface;

    protected function doList(Request $request): JsonResponse
    {
        try {
            $data = $this->storage()->listDirectory((string) $request->query->get('path', ''));

            return new JsonResponse(['success' => true] + $data + ['basePath' => $this->storage()->getStartPath()]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    protected function doPreview(Request $request): JsonResponse
    {
        try {
            $data = $this->storage()->readFileContent((string) $request->query->get('path', ''));

            return new JsonResponse(['success' => true] + $data);
        } catch (\Throwable $e) {
            $this->container->get(LoggerInterface::class)
                ->info('Aaxis Tools storage preview failed.', ['exception' => $e]);

            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    protected function doStream(Request $request, string $disposition): Response
    {
        try {
            $resource = $this->storage()->openResource((string) $request->query->get('path', ''));
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $stream = $resource['stream'];
        $response = new StreamedResponse(static function () use ($stream): void {
            while (!feof($stream)) {
                echo fread($stream, 8192);
            }
            fclose($stream);
        });
        $mime = $resource['mime'] ?: 'application/octet-stream';
        // Never let the browser content-sniff a stored file into an executable type.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        if ($disposition === ResponseHeaderBag::DISPOSITION_INLINE && !$this->isInlineSafe($mime)) {
            // Only vetted media (images / PDF) may render inline; anything else is forced to
            // download as an opaque stream so a mislabeled .html/.svg cannot execute in our origin.
            $mime = 'application/octet-stream';
            $disposition = ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        }
        if ($disposition === ResponseHeaderBag::DISPOSITION_INLINE && str_contains(strtolower($mime), 'svg')) {
            // SVG can carry scripts. The UI loads it via <img> (where the sandbox CSP does not
            // apply, so rendering is unaffected), but the CSP neutralizes script execution if the
            // raw URL is opened as a top-level document.
            $response->headers->set('Content-Security-Policy', 'sandbox');
        }

        $response->headers->set('Content-Type', $mime);
        if ($resource['size'] !== null) {
            $response->headers->set('Content-Length', (string) $resource['size']);
        }
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition($disposition, $resource['name'] ?: 'download')
        );

        return $response;
    }

    /**
     * Whether a stored file's MIME type may be served inline. Restricted to the only types the
     * browser UI renders inline (images via &lt;img&gt;, PDF via &lt;iframe&gt;); everything else
     * is downloaded so it cannot execute in the admin origin.
     */
    private function isInlineSafe(string $mime): bool
    {
        $mime = strtolower(trim($mime));

        return str_starts_with($mime, 'image/') || $mime === 'application/pdf';
    }

    /**
     * @return array<int, class-string>
     */
    protected static function sharedSubscribedServices(): array
    {
        return [LoggerInterface::class];
    }

    /**
     * @return string
     */
    protected function disposition(string $kind): string
    {
        return $kind === 'inline' ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
    }
}
