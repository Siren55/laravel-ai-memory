<?php

declare(strict_types=1);

namespace Eznix86\AI\Memory\Middleware;

use Closure;
use Eznix86\AI\Memory\Services\MemoryManager;
use Laravel\Ai\Prompts\AgentPrompt;

class WithMemory
{
    /**
     * Create a new memory middleware instance.
     *
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        protected array $context = [],
        protected ?int $limit = null,
    ) {}

    /**
     * Handle an incoming agent prompt.
     */
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        if ($this->context === []) {
            return $next($prompt);
        }

        $limit = $this->limit ?? config('memory.middleware_recall_limit', 5);

        $memoryManager = app(MemoryManager::class);

        $memories = $memoryManager->recall(
            $prompt->prompt,
            $this->context,
            $limit,
        );

        if ($memories->isNotEmpty()) {
            $memoryContext = $memories->map(fn ($memory): string => "- {$memory->content}")->implode("\n");

            $prompt = $prompt->prepend("Relevant memories from previous conversations:\n{$memoryContext}");
        }

        return $next($prompt);
    }
}
