<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\Actions\ZipDocuments;

use N1ebieski\KSEFClient\Actions\AbstractAction;

final class ZipDocumentsAction extends AbstractAction
{
    /**
     * @param array<int, string> $documents
     */
    public function __construct(
        public readonly array $documents
    ) {
    }
}
