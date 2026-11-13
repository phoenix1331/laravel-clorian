<?php

declare(strict_types=1);

namespace Phoenix1331\Clorian\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Phoenix1331\Clorian\Models\SubjectStatus;
use Phoenix1331\Clorian\Models\Weighing;

/**
 * The optional clorian_status column on an app's own table: written after
 * every weighing and review when clorian.status_column.enabled is on and
 * the table has the column. Written with a plain query, so no model events
 * fire and updated_at is left alone.
 *
 * @internal
 */
final class StatusColumn
{
    public const string DEFAULT_NAME = 'clorian_status';

    /** @var array<string, bool> connection.table => has the column */
    private array $tables = [];

    public function __construct(private readonly Repository $config) {}

    /**
     * The column name when the feature is on and the model's table has it.
     */
    public function for(Model $model): ?string
    {
        if ($this->config->get('clorian.status_column.enabled') !== true) {
            return null;
        }

        $name = $this->config->get('clorian.status_column.name');
        $name = is_string($name) && $name !== '' ? $name : self::DEFAULT_NAME;
        $key = $model->getConnectionName().'.'.$model->getTable().'.'.$name;

        $this->tables[$key] ??= $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $name);

        return $this->tables[$key] ? $name : null;
    }

    /**
     * Write the status of the subject's latest weighing.
     */
    public function sync(Model $subject): void
    {
        $column = $this->for($subject);

        if ($column === null) {
            return;
        }

        $latest = Weighing::query()->whereMorphedTo('subject', $subject)->with('answers')->latest('id')->first();
        $status = $latest === null ? null : SubjectStatus::of($latest)->value;

        $subject->newQueryWithoutScopes()->whereKey($subject->getKey())->toBase()->update([$column => $status]);

        // Keep the instance in step without marking it dirty.
        $subject->setAttribute($column, $status);
        $subject->syncOriginalAttribute($column);
    }
}
