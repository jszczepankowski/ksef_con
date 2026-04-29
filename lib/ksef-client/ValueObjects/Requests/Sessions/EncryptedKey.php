<?php

declare(strict_types=1);

namespace N1ebieski\KSEFClient\ValueObjects\Requests\Sessions;

use N1ebieski\KSEFClient\Support\AbstractValueObject;

final class EncryptedKey extends AbstractValueObject
{
    public function __construct(
        public readonly string $key,
        public readonly string $iv
    ) {
    }

    public static function from(string $key, string $iv): self
    {
        return new self($key, $iv);
    }
}
