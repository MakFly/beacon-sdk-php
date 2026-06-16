<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Symfony;

use KevStudios\Beacon\Symfony\DependencyInjection\BeaconExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class BeaconBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new BeaconExtension();
    }
}
