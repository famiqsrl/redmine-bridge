<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Symfony;

use Famiq\RedmineBridge\Symfony\DependencyInjection\RedmineBridgeExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class RedmineBridgeBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new RedmineBridgeExtension();
    }
}
