<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Symfony;

interface UserContextProviderInterface
{
    /** Returns a stable pseudonymous identifier, never the raw login or email. */
    public function userId(): ?string;
}
