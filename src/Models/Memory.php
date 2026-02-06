<?php

declare(strict_types=1);

namespace Eznix86\AI\Memory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $content
 * @property array<float> $embedding
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Memory extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'content',
        'embedding',
    ];

    /**
     * Get the table associated with the model.
     */
    #[\Override]
    public function getTable(): string
    {
        return config('memory.table', 'memories');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'content' => 'string',
        ];
    }
}
