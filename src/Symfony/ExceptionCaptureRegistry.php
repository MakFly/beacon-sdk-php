<?php

declare(strict_types=1);

namespace KevStudios\Beacon\Symfony;

use Throwable;
use WeakMap;

/**
 * Coordinates exception capture paths without retaining Throwable instances.
 *
 * @internal
 */
final class ExceptionCaptureRegistry
{
    /** @var WeakMap<Throwable, true> */
    private WeakMap $kernelExceptions;

    /** @var WeakMap<Throwable, true> */
    private WeakMap $monologExceptions;

    public function __construct()
    {
        $this->kernelExceptions = new WeakMap();
        $this->monologExceptions = new WeakMap();
    }

    public function markKernelException(Throwable $throwable): void
    {
        $this->kernelExceptions[$throwable] = true;
    }

    public function claimMonologException(Throwable $throwable): bool
    {
        if (isset($this->kernelExceptions[$throwable]) || isset($this->monologExceptions[$throwable])) {
            return false;
        }

        $this->monologExceptions[$throwable] = true;

        return true;
    }
}
