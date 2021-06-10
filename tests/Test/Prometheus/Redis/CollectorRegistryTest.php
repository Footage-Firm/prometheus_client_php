<?php

declare(strict_types=1);

namespace Test\Prometheus\Redis;

use Prometheus\Storage\RedisStorage;
use Test\Prometheus\AbstractCollectorRegistryTest;

/**
 * @requires extension redis
 */
class CollectorRegistryTest extends AbstractCollectorRegistryTest
{
    public function configureAdapter(): void
    {
        $this->adapter = new RedisStorage(['host' => REDIS_HOST]);
        $this->adapter->wipeStorage();
    }
}
