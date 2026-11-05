<?php

namespace App\Services\Integrations;

use Symfony\Component\Process\Process;
use XMLReader;
use ZipArchive;

class DocumentExtractor
{
    public function extract(string $path, string $mime): array
    {
        try {
            $text = $mime === 'application/pdf' ? $this->pdf($path) : $this->docx($path);

            return ['status' => 'extracted', 'text' => $text];
        } catch (\Throwable $e) {
            $code = in_array($e->getMessage(), ['unsafe_document', 'document_limits', 'parser_unavailable', 'extraction_failed'], true) ? $e->getMessage() : 'extraction_failed';

            return ['status' => 'unable_to_determine', 'error_code' => $code, 'text' => ''];
        }
    }

    private function docx(string $path): string
    {
        $previousErrors = libxml_use_internal_errors(true);
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
            throw new \RuntimeException('unsafe_document');
        }
        try {
            if ($zip->numFiles > 128 || $zip->locateName('[Content_Types].xml') === false || $zip->locateName('_rels/.rels') === false || $zip->locateName('word/document.xml') === false) {
                throw new \RuntimeException('unsafe_document');
            }
            $types = $zip->getFromName('[Content_Types].xml', 2097153);
            if (! is_string($types) || ! str_contains($types, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml')) {
                throw new \RuntimeException('unsafe_document');
            }
            $total = 0;
            $deadline = microtime(true) + 5;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $s = $zip->statIndex($i);
                $name = $s['name'];
                $decoded = rawurldecode($name);
                $total += $s['size'];
                if (strlen($name) > 200 || preg_match('#(?:^/|\\\\|\x00|:|(?:^|/)\.\.(?:/|$)|vbaProject|/embeddings/|/activeX/|\.exe$|\.bin$|\.js$)#i', $decoded) || ($s['encryption_method'] ?? 0) !== 0) {
                    throw new \RuntimeException('unsafe_document');
                }
                if ($total > 16 * 1024 * 1024 || $s['size'] > 2 * 1024 * 1024 || ($s['size'] > 0 && ($s['comp_size'] < 1 || $s['size'] / $s['comp_size'] > 100)) || microtime(true) > $deadline) {
                    throw new \RuntimeException('document_limits');
                }
                if (str_ends_with($name, '.rels') || str_ends_with($name, '.xml')) {
                    $xml = $zip->getFromIndex($i);
                    if (! is_string($xml) || ! mb_check_encoding($xml, 'UTF-8') || str_contains($xml, "\0") || preg_match('/<!DOCTYPE|<!ENTITY|macroEnabled/i', $xml)) {
                        throw new \RuntimeException('unsafe_document');
                    }
                    $check = new XMLReader;
                    if (! $check->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
                        throw new \RuntimeException('unsafe_document');
                    }
                    try {
                        while ($check->read()) {
                            $target = $check->getAttribute('Target');
                            if (in_array($check->nodeType, [XMLReader::DOC_TYPE, XMLReader::ENTITY_REF], true) || strcasecmp((string) $check->getAttribute('TargetMode'), 'External') === 0 || (is_string($target) && preg_match('#(?:^[\\\\/]|:)#', $target)) || microtime(true) > $deadline) {
                                throw new \RuntimeException('unsafe_document');
                            }
                        }
                        if (libxml_get_errors() !== []) {
                            throw new \RuntimeException('unsafe_document');
                        }
                    } finally {
                        $check->close();
                    }
                }
            }
            $xml = $zip->getFromName('word/document.xml');
            $reader = new XMLReader;
            if (! is_string($xml) || ! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new \RuntimeException('extraction_failed');
            }
            $text = '';
            $nodes = 0;
            try {
                while ($reader->read()) {
                    if (++$nodes > 100000 || microtime(true) > $deadline || strlen($text) > 100000) {
                        throw new \RuntimeException('document_limits');
                    }
                    if (in_array($reader->nodeType, [XMLReader::DOC_TYPE, XMLReader::ENTITY_REF], true)) {
                        throw new \RuntimeException('unsafe_document');
                    }
                    if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't' && $reader->namespaceURI === 'http://schemas.openxmlformats.org/wordprocessingml/2006/main') {
                        $text .= $reader->readString();
                    }
                    if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->localName === 'p') {
                        $text .= "\n";
                    }
                }
            } finally {
                $reader->close();
            }

            return trim($text);
        } finally {
            $zip->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function pdf(string $path): string
    {
        $runtime = config('email_integrations.documents.container_runtime');
        $image = config('email_integrations.documents.pdf_image');
        if (! is_string($runtime) || ! is_file($runtime) || ! in_array(strtolower(pathinfo($runtime, PATHINFO_FILENAME)), ['podman', 'docker'], true) || ! is_string($image) || ! preg_match('#^[a-zA-Z0-9./:_-]+@sha256:[a-f0-9]{64}$#D', $image)) {
            throw new \RuntimeException('parser_unavailable');
        }
        $name = 'flowmv-document-'.bin2hex(random_bytes(12));
        $process = new Process([$runtime, 'run', '--rm', '--pull=never', '--name', $name, '--network=none', '--read-only', '--memory=128m', '--memory-swap=128m', '--cpus=1', '--pids-limit=32', '--cap-drop=ALL', '--security-opt=no-new-privileges', '--user=65534:65534', '--tmpfs=/tmp:rw,noexec,nosuid,size=16m', '--mount', 'type=bind,src='.dirname($path).',dst=/input,readonly', $image, 'pdftotext', '-q', '-f', '1', '-l', '30', '-layout', '-enc', 'UTF-8', '/input/'.basename($path), '-']);
        $process->setTimeout(8);
        $text = '';
        try {
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                $text .= $process->getIncrementalOutput();
                $process->clearOutput();
                $process->clearErrorOutput();
                if (strlen($text) > 100000) {
                    throw new \RuntimeException('document_limits');
                }
                usleep(20000);
            }
            $text .= $process->getIncrementalOutput();
            if (! $process->isSuccessful() || strlen($text) > 100000 || ! mb_check_encoding($text, 'UTF-8')) {
                throw new \RuntimeException('extraction_failed');
            }

            return trim($text);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
            try {
                (new Process([$runtime, 'rm', '--force', $name]))->setTimeout(5)->run();
            } catch (\Throwable) {
            }
        }
    }
}
