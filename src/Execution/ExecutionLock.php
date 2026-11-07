<?php

declare(strict_types=1);

namespace NeuronInteraction\Execution;

use LogicException;
use NeuronInteraction\Exception\ExecutionBusyException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/** @internal Long model/tool calls cannot safely renew expiring leases. */
final class ExecutionLock
{
    public static function acquire(LockFactory $factory, string $key, bool $blocking = false): LockInterface
    {
        $lock = $factory->createLock('neuron-interaction.' . $key);
        if (!$lock->acquire($blocking)) {
            throw new ExecutionBusyException('The execution is already active.');
        }
        if ($lock->getRemainingLifetime() !== null) {
            $lock->release();
            throw new LogicException('Execution requires a non-expiring Symfony lock store, such as SemaphoreStore or PostgreSqlStore.');
        }
        return $lock;
    }
}
