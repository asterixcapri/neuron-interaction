<?php

declare(strict_types=1);

namespace NeuronInteraction\Tests\Storage;

use NeuronInteraction\Storage\EntryStorageInterface;
use NeuronInteraction\Storage\InMemoryEntryStorage;

final class InMemoryEntryStorageTest extends EntryStorageContract
{
    protected function storage(): EntryStorageInterface
    {
        return new InMemoryEntryStorage();
    }
}
