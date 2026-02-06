<?php

declare(strict_types=1);

namespace Eznix86\AI\Memory\Tools;

use Eznix86\AI\Memory\Services\MemoryManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * @property array<string, mixed> $context
 */
class RecallMemory implements Tool
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        protected array $context = [],
        protected ?int $limit = null,
    ) {}

    /**
     * Set the context for memory operations.
     *
     * @param  array<string, mixed>  $context
     */
    public function context(array $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Set the maximum number of memories to recall.
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Recall relevant memories from previous conversations using semantic search. Use this to retrieve context about user preferences, past interactions, or stored knowledge.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $limit = $this->limit ?? config('memory.recall_limit', 10);

        $memoryManager = app(MemoryManager::class);

        $memories = $memoryManager->recall(
            $request['query'],
            $this->context,
            $limit,
        );

        if ($memories->isEmpty()) {
            return 'No relevant memories found.';
        }

        return $memories->map(fn ($memory): string => "- {$memory->content}")->implode("\n");
    }

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The search query to find relevant memories.')
                ->required(),
        ];
    }
}
