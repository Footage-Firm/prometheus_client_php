<?php

declare(strict_types=1);

namespace Prometheus\Storage;

use InvalidArgumentException;
use Prometheus\Counter;
use Prometheus\Exception\StorageException;
use Prometheus\Gauge;
use Prometheus\Histogram;
use Prometheus\MetricFamilySamples;

class Redis implements Adapter
{
    const PROMETHEUS_METRIC_KEYS_SUFFIX = '_METRIC_KEYS';

    private static string $prefix = 'PROMETHEUS_';

    private \Illuminate\Support\Facades\Redis $redis;

    public function __construct()
    {
        $this->redis = new \Redis();
    }

    /**
     * @param string $prefix
     */
    public static function setPrefix(string $prefix): void
    {
        self::$prefix = $prefix;
    }

    /**
     * @inheritDoc
     */
    public function wipeStorage(): void
    {
    
        $searchPattern = "";
        $searchPattern .= self::$prefix;
        $searchPattern .= '*';
        $this->redis::eval(
            <<<'LUA' return redis.call('del', unpack(redis.call('keys', ARGV[1]))) LUA,
            0,
            $searchPattern
        );
    }

    /**
     * @return MetricFamilySamples[]
     */
    public function collect(): array
    {
        
        $metrics = $this->collectHistograms();
        $metrics = array_merge($metrics, $this->collectGauges());
        $metrics = array_merge($metrics, $this->collectCounters());
        $this->wipeStorage();
        return array_map(
            function (array $metric): MetricFamilySamples {
                return new MetricFamilySamples($metric);
            },
            $metrics
        );
    }


    /**
     * @param mixed[] $data
     */
    public function updateHistogram(array $data): void
    {
        
        $bucketToIncrease = '+Inf';
        foreach ($data['buckets'] as $bucket) {
            if ($data['value'] <= $bucket) {
                $bucketToIncrease = $bucket;
                break;
            }
        }
        $metaData = $data;
        unset($metaData['value'], $metaData['labelValues']);

        $this->redis::eval(
            <<<'LUA'
local result = redis.call('hIncrByFloat', KEYS[1], ARGV[1], ARGV[3])
redis.call('hIncrBy', KEYS[1], ARGV[2], 1)
if tonumber(result) >= tonumber(ARGV[3]) then
    redis.call('hSet', KEYS[1], '__meta', ARGV[4])
    redis.call('sAdd', KEYS[2], KEYS[1])
end
return result
LUA
            ,2,
            
                $this->toMetricKey($data),
                self::$prefix . Histogram::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX,
                json_encode(['b' => 'sum', 'labelValues' => $data['labelValues']]),
                json_encode(['b' => $bucketToIncrease, 'labelValues' => $data['labelValues']]),
                $data['value'],
                json_encode($metaData),
        );
    }

    /**
     * @param mixed[] $data
     */
    public function updateGauge(array $data): void
    {
        
        $metaData = $data;
        unset($metaData['value'], $metaData['labelValues'], $metaData['command']);
        $this->redis::eval(
            <<<'LUA'
local result = redis.call(ARGV[1], KEYS[1], ARGV[2], ARGV[3])

if ARGV[1] == 'hSet' then
    if result == 1 then
        redis.call('hSet', KEYS[1], '__meta', ARGV[4])
        redis.call('sAdd', KEYS[2], KEYS[1])
    end
else
    if result == ARGV[3] then
        redis.call('hSet', KEYS[1], '__meta', ARGV[4])
        redis.call('sAdd', KEYS[2], KEYS[1])
    end
end
LUA
            ,2,
            
                $this->toMetricKey($data),
                self::$prefix . Gauge::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX,
                $this->getRedisCommand($data['command']),
                json_encode($data['labelValues']),
                $data['value'],
                json_encode($metaData),
            
        );
    }

    /**
     * @param mixed[] $data
     */
    public function updateCounter(array $data): void
    {
        
        $metaData = $data;
        unset($metaData['value'], $metaData['labelValues'], $metaData['command']);
        $this->redis::eval( <<<'LUA'
    local counter = redis.call("incr", KEYS[1])

    if counter > 5 then
        redis.call("incr", KEYS[2])
    end

    return counter
LUA, 2, 'first-counter', 'second-counter');
    }

    /**
     * @return mixed[]
     */
    private function collectHistograms(): array
    {
        $keys = $this->redis::smembers(self::$prefix . Histogram::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX);
        sort($keys);
        $histograms = [];
        foreach ($keys as $key) {
            $raw = $this->redis::hgetall($key);
            $histogram = json_decode($raw['__meta'], true);
            unset($raw['__meta']);
            $histogram['samples'] = [];

            // Add the Inf bucket so we can compute it later on
            $histogram['buckets'][] = '+Inf';

            $allLabelValues = [];
            foreach (array_keys($raw) as $k) {
                $d = json_decode($k, true);
                if ($d['b'] == 'sum') {
                    continue;
                }
                $allLabelValues[] = $d['labelValues'];
            }

            // We need set semantics.
            // This is the equivalent of array_unique but for arrays of arrays.
            $allLabelValues = array_map("unserialize", array_unique(array_map("serialize", $allLabelValues)));
            sort($allLabelValues);

            foreach ($allLabelValues as $labelValues) {
                // Fill up all buckets.
                // If the bucket doesn't exist fill in values from
                // the previous one.
                $acc = 0;
                foreach ($histogram['buckets'] as $bucket) {
                    $bucketKey = json_encode(['b' => $bucket, 'labelValues' => $labelValues]);
                    if (!isset($raw[$bucketKey])) {
                        $histogram['samples'][] = [
                            'name' => $histogram['name'] . '_bucket',
                            'labelNames' => ['le'],
                            'labelValues' => array_merge($labelValues, [$bucket]),
                            'value' => $acc,
                        ];
                    } else {
                        $acc += $raw[$bucketKey];
                        $histogram['samples'][] = [
                            'name' => $histogram['name'] . '_bucket',
                            'labelNames' => ['le'],
                            'labelValues' => array_merge($labelValues, [$bucket]),
                            'value' => $acc,
                        ];
                    }
                }

                // Add the count
                $histogram['samples'][] = [
                    'name' => $histogram['name'] . '_count',
                    'labelNames' => [],
                    'labelValues' => $labelValues,
                    'value' => $acc,
                ];

                // Add the sum
                $histogram['samples'][] = [
                    'name' => $histogram['name'] . '_sum',
                    'labelNames' => [],
                    'labelValues' => $labelValues,
                    'value' => $raw[json_encode(['b' => 'sum', 'labelValues' => $labelValues])],
                ];
            }
            $histograms[] = $histogram;
        }
        return $histograms;
    }

    /**
     * @return mixed[]
     */
    private function collectGauges(): array
    {
        $keys = $this->redis::smembers(self::$prefix . Gauge::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX);
        sort($keys);
        $gauges = [];
        foreach ($keys as $key) {
            $raw = $this->redis::hgetall( $key);
            $gauge = json_decode($raw['__meta'], true);
            unset($raw['__meta']);
            $gauge['samples'] = [];
            foreach ($raw as $k => $value) {
                $gauge['samples'][] = [
                    'name' => $gauge['name'],
                    'labelNames' => [],
                    'labelValues' => json_decode($k, true),
                    'value' => $value,
                ];
            }
            usort($gauge['samples'], function ($a, $b): int {
                return strcmp(implode("", $a['labelValues']), implode("", $b['labelValues']));
            });
            $gauges[] = $gauge;
        }
        return $gauges;
    }

    /**
     * @return mixed[]
     */
    private function collectCounters(): array
    {
        $keys = $this->redis::smembers(self::$prefix . Counter::TYPE . self::PROMETHEUS_METRIC_KEYS_SUFFIX);
        sort($keys);
        $counters = [];
        foreach ($keys as $key) {
            $raw = $this->redis::hgetall($key);
            $counter = json_decode($raw['__meta'], true);
            unset($raw['__meta']);
            $counter['samples'] = [];
            foreach ($raw as $k => $value) {
                $counter['samples'][] = [
                    'name' => $counter['name'],
                    'labelNames' => [],
                    'labelValues' => json_decode($k, true),
                    'value' => $value,
                ];
            }
            usort($counter['samples'], function ($a, $b): int {
                return strcmp(implode("", $a['labelValues']), implode("", $b['labelValues']));
            });
            $counters[] = $counter;
        }
        return $counters;
    }

    /**
     * @param int $cmd
     * @return string
     */
    private function getRedisCommand(int $cmd): string
    {
        switch ($cmd) {
            case Adapter::COMMAND_INCREMENT_INTEGER:
                return 'HINCYBY';
            case Adapter::COMMAND_INCREMENT_FLOAT:
                return 'HINCRBYFLOAT';
            case Adapter::COMMAND_SET:
                return 'HSET';
            default:
                throw new InvalidArgumentException("Unknown command");
        }
    }

    /**
     * @param mixed[] $data
     * @return string
     */
    private function toMetricKey(array $data): string
    {
        return implode(':', [self::$prefix, $data['type'], $data['name']]);
    }
}
