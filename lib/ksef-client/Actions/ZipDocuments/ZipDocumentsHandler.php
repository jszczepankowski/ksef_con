<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Actions\ZipDocuments;

use N1ebieski\KSEFClient\Actions\AbstractHandler;
use RuntimeException;
use ZipArchive;

final class ZipDocumentsHandler extends AbstractHandler
{
    public function handle(ZipDocumentsAction $action): string
    {
        $zip = new ZipArchive();

        $tempDir = sys_get_temp_dir();
        $tempFile = tempnam($tempDir, 'zip_');

        if ($tempFile === false) {
            throw new RuntimeException("Unable to create temp file in {$tempDir}.");
        }

        if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to open zip file.');
        }

        foreach ($action->documents as $index => $document) {
            $fileName = sprintf('%05d.xml', $index + 1);

            $zip->addFromString($fileName, $document);
        }

        $zip->close();

        $zipContent = file_get_contents($tempFile);

        unlink($tempFile);

        if ($zipContent === false) {
            throw new RuntimeException('Unable to read zip file.');
        }

        return $zipContent;
    }
}
