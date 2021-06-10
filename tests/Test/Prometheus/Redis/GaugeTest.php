<?php

declare(strict_types=1);

namespace Test\Prometheus\Redis;

use Prometheus\Storage\RedisStorage;
use Test\Prometheus\AbstractGaugeTest;

/**
 * See https://prometheus.io/docs/instrumenting/exposition_formats/
 * @requires extension redis
 */
class GaugeTest extends AbstractGaugeTest
{
    public function configureAdapter(): void
    {
        $this->adapter = new RedisStorage(['host' => REDIS_HOST]);
        $this->adapter->wipeStorage();
    }
}
