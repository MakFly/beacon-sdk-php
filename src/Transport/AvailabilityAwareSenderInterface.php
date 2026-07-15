<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Transport;

interface AvailabilityAwareSenderInterface extends SenderInterface
{
    public function isAvailable(): bool;
}
