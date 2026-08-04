<?php

// app/Concerns/HasOptimisticLocking.php

namespace App\Concerns;

use App\Exceptions\StaleModelException;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Model
 */
trait HasOptimisticLocking
{
    /**
     * Update the model only if its version still matches $expectedVersion.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws StaleModelException
     */
    public function updateWithVersion(array $attributes, int $expectedVersion): bool
    {
        $column = $this->getVersionColumn();

        $affected = $this->newQueryWithoutScopes()
            ->whereKey($this->getKey())
            ->where($column, $expectedVersion)
            ->update([
                ...$attributes,
                $column => $expectedVersion + 1,
            ]);

        if ($affected === 0) {
            throw new StaleModelException($this);
        }

        $this->refresh();

        return true;
    }

    public function getVersionColumn(): string
    {
        return defined(static::class.'::VERSION_COLUMN')
            ? static::VERSION_COLUMN
            : 'version';
    }
}
