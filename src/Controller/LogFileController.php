<?php

declare(strict_types=1);

namespace InfoPlusCommerce\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RequestStack;

#[Route(defaults: ['_routeScope' => ['api']])]
class LogFileController extends AbstractController
{
    private string $logsDir;
    private RequestStack $requestStack;

    public function __construct(ParameterBagInterface $params, RequestStack $requestStack)
    {
        $value = $params->get('kernel.logs_dir');
        if (is_string($value)) {
            $this->logsDir = $value;
        } elseif (is_scalar($value)) {
            $this->logsDir = (string) $value;
        } else {
            $this->logsDir = '';
        }
        $this->requestStack = $requestStack;
    }

    #[Route(path: '/api/_action/infoplus/logs', name: 'api.infoPlus.logs', methods: ['GET'])]
    public function logs(): JsonResponse
    {
        $logs = [];
        try {
            if (!is_dir($this->logsDir)) {
                $response = new JsonResponse(['error' => 'Log directory not found'], 500);
                $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                return $response;
            }

            $files = scandir($this->logsDir, SCANDIR_SORT_DESCENDING);
            if (!is_array($files)) {
                $response = new JsonResponse(['error' => 'Unable to read log directory'], 500);
                $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                return $response;
            }

            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                if (str_starts_with($file, 'InfoPlus') && str_ends_with($file, '.log')) {
                    $path = $this->logsDir . '/' . $file;
                    if (!is_file($path) || !is_readable($path)) {
                        continue;
                    }

                    $firstLines = [];
                    $fh = new \SplFileObject($path, 'r');
                    for ($i = 0; $i < 1 && !$fh->eof(); $i++) {
                        $line = $fh->fgets();
                        // @phpstan-ignore-next-line
                        if ($line === false) {
                            break;
                        }
                        $firstLines[] = substr(rtrim($line, "\r\n"), 0, 500);
                    }

                    $logs[] = [
                        'file' => $file,
                        'content' => $firstLines,
                    ];
                }
            }
        } catch (\Exception $e) {
            $response = new JsonResponse(['error' => 'An error occurred while retrieving logs: ' . $e->getMessage()], 500);
            $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return $response;
        }
        $response = new JsonResponse(['logs' => $logs]);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return $response;
    }

    #[Route(
        path: '/api/_action/infoplus/logs/{file}/content',
        name: 'api.infoplus.logs.file',
        methods: ['GET'],
        defaults: ['_routeScope' => ['api']],
        requirements: ['file' => '[A-Za-z0-9._\-]+']
    )]
    public function getLogContent(string $file): JsonResponse
    {
        $filePath = $this->logsDir . '/' . $file;

        if (!is_file($filePath) || !is_readable($filePath)) {
            $response = new JsonResponse(['error' => 'Log file not found or not readable'], 404);
            $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return $response;
        }

        $request = $this->requestStack->getCurrentRequest();
        $page = (int) ($request?->query->get('page', '1') ?? '1');
        $limit = (int) ($request?->query->get('limit', '100') ?? '100');
        $page = max(1, $page);
        $limit = max(1, min(1000, $limit));
        $startLine = ($page - 1) * $limit;

        $lines = [];
        $total = 0;
        try {
            $fh = new \SplFileObject($filePath, 'r');
            while (!$fh->eof()) {
                $fh->fgets();
                $total++;
            }
            $fh->rewind();
            for ($i = 0; $i < $startLine && !$fh->eof(); $i++) {
                $fh->fgets();
            }
            for ($i = 0; $i < $limit && !$fh->eof(); $i++) {
                $line = $fh->fgets();
                // @phpstan-ignore-next-line
                if ($line === false) {
                    break;
                }
                $lines[] = rtrim($line, "\r\n");
            }
            $response = new JsonResponse(['lines' => $lines, 'total' => $total]);
            $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return $response;
        } catch (\Exception $e) {
            $response = new JsonResponse(['error' => 'An error occurred while reading the log file: ' . $e->getMessage()], 500);
            $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return $response;
        }
    }

    #[Route(
        path: '/api/_action/infoplus/logs/{file}/download',
        name: 'api.infoplus.logs.file.download',
        methods: ['GET'],
        requirements: ['file' => '[A-Za-z0-9._\-]+']
    )]
    public function downloadLogFile(string $file): StreamedResponse|JsonResponse
    {
        if (!preg_match('/^[A-Za-z0-9._\-]+$/', $file)) {
            $response = new JsonResponse(['error' => 'Invalid file name'], 400);
            $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return $response;
        }

        $filePath = $this->logsDir . '/' . $file;
        $realPath = realpath($filePath);
        $logsRealPath = realpath($this->logsDir) ?: $this->logsDir;

        if ($realPath === false || !str_starts_with($realPath, $logsRealPath . DIRECTORY_SEPARATOR)) {
            $response = new JsonResponse(['error' => 'Invalid path'], 400);
            $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return $response;
        }

        if (!is_file($realPath) || !is_readable($realPath)) {
            $response = new JsonResponse(['error' => 'Log file not found or not readable'], 404);
            $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return $response;
        }

        try {
            $response = new StreamedResponse(function () use ($realPath) {
                readfile($realPath);
            });
            $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
            $disposition = $response->headers->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                $file
            );
            $response->headers->set('Content-Disposition', $disposition);
            return $response;
        } catch (\Exception $e) {
            $response = new JsonResponse(['error' => 'Download failed: ' . $e->getMessage()], 500);
            $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            return $response;
        }
    }
}
