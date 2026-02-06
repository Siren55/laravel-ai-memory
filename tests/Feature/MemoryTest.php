<?php

declare(strict_types=1);

use Eznix86\AI\Memory\Facades\AgentMemory;
use Eznix86\AI\Memory\Models\Memory as MemoryModel;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\RankedDocument;

test('can store memories with user context', function (): void {
    AgentMemory::fake();

    $memory = AgentMemory::store('User prefers dark mode', ['user_id' => 'user-123']);

    expect($memory)
        ->toBeInstanceOf(MemoryModel::class)
        ->and($memory->user_id)->toBe('user-123')
        ->and($memory->content)->toBe('User prefers dark mode')
        ->and($memory->embedding)->toBeArray()
        ->and(count($memory->embedding))->toBe(1536);

    $this->assertDatabaseHas('memories', [
        'user_id' => 'user-123',
        'content' => 'User prefers dark mode',
    ]);

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->contains('User prefers dark mode'));
});

test('can recall memories using semantic search', function (): void {
    AgentMemory::fake([
        [
            new RankedDocument(index: 0, document: 'User likes dark themes', score: 0.9),
            new RankedDocument(index: 1, document: 'Application should use dark mode', score: 0.8),
        ],
    ]);

    // Store some memories
    AgentMemory::store('User likes dark themes', ['user_id' => 'user-123']);
    AgentMemory::store('Application should use dark mode', ['user_id' => 'user-123']);

    // Recall memories
    $memories = AgentMemory::recall("What are the user's UI preferences?", ['user_id' => 'user-123'], 2);

    expect($memories)->toHaveCount(2)
        ->and($memories->first()->content)->toBe('User likes dark themes')
        ->and($memories->last()->content)->toBe('Application should use dark mode');

    Reranking::assertReranked(fn ($prompt) => $prompt->contains("What are the user's UI preferences?"));
});

test('can filter memories by user context', function (): void {
    AgentMemory::fake();

    // Store memories for different users
    AgentMemory::store('User A preference', ['user_id' => 'user-a']);
    AgentMemory::store('User B preference', ['user_id' => 'user-b']);

    // Should only recall memories for specific user
    $memoriesA = AgentMemory::recall('preference', ['user_id' => 'user-a']);

    // User A should only see their own memory
    expect($memoriesA)->toHaveCount(1)
        ->and($memoriesA->first()->content)->toBe('User A preference');
});

test('can store memories without user context', function (): void {
    AgentMemory::fake();

    $memory = AgentMemory::store('Global system setting');

    expect($memory->user_id)->toBeNull()
        ->and($memory->content)->toBe('Global system setting');
});

test('can delete specific memory', function (): void {
    AgentMemory::fake();

    $memory = AgentMemory::store('Memory to delete', ['user_id' => 'user-123']);

    $deleted = AgentMemory::forget($memory->id);

    expect($deleted)->toBeTrue();
    $this->assertDatabaseMissing('memories', ['id' => $memory->id]);
});

test('can delete all memories for user', function (): void {
    AgentMemory::fake();

    // Store memories for different users
    AgentMemory::store('Memory 1', ['user_id' => 'user-123']);
    AgentMemory::store('Memory 2', ['user_id' => 'user-123']);
    AgentMemory::store('Memory 3', ['user_id' => 'user-456']);

    $deletedCount = AgentMemory::forgetAll(['user_id' => 'user-123']);

    expect($deletedCount)->toBe(2);
    $this->assertDatabaseMissing('memories', ['user_id' => 'user-123']);
    $this->assertDatabaseHas('memories', ['user_id' => 'user-456']);
});

test('can get all memories for user', function (): void {
    AgentMemory::fake();

    // Store memories for different users
    AgentMemory::store('Memory 1', ['user_id' => 'user-123']);
    AgentMemory::store('Memory 2', ['user_id' => 'user-123']);
    AgentMemory::store('Memory 3', ['user_id' => 'user-456']);

    $memories = AgentMemory::all(['user_id' => 'user-123']);

    expect($memories)->toHaveCount(2);
});

test('recall respects limit parameter', function (): void {
    AgentMemory::fake();

    // Store multiple memories
    for ($i = 1; $i <= 5; $i++) {
        AgentMemory::store("Memory $i", ['user_id' => 'user-123']);
    }

    $memories = AgentMemory::recall('memory', ['user_id' => 'user-123'], 3);

    expect($memories)->toHaveCount(3);
});

test('handles non-existent memory deletion gracefully', function (): void {
    $deleted = AgentMemory::forget(999);

    expect($deleted)->toBeFalse();
});

test('memory model uses correct table and casts', function (): void {
    $memory = new MemoryModel([
        'user_id' => 'test',
        'content' => 'test content',
        'embedding' => [0.1, 0.2, 0.3],
    ]);

    expect($memory->getTable())->toBe('memories')
        ->and($memory->getFillable())->toBe(['user_id', 'content', 'embedding'])
        ->and($memory->getCasts())->toHaveKey('embedding', 'array');
});

test('recall returns empty collection when no memories exist', function (): void {
    AgentMemory::fake();

    $memories = AgentMemory::recall('anything', ['user_id' => 'user-nonexistent']);

    expect($memories)->toBeEmpty();

    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->contains('anything'));
});

// ──────────────────────────────────────────────────────────────────
// Config Tests
// ──────────────────────────────────────────────────────────────────

test('config values have sensible defaults', function (): void {
    expect(config('memory.dimensions'))->toBe(1536)
        ->and(config('memory.similarity_threshold'))->toBe(0.5)
        ->and(config('memory.recall_limit'))->toBe(10)
        ->and(config('memory.middleware_recall_limit'))->toBe(5)
        ->and(config('memory.recall_oversample_factor'))->toBe(2)
        ->and(config('memory.table'))->toBe('memories');
});

test('memory model uses configured table name', function (): void {
    config(['memory.table' => 'custom_memories']);

    $memory = new MemoryModel;

    expect($memory->getTable())->toBe('custom_memories');

    // Reset
    config(['memory.table' => 'memories']);
});

// ──────────────────────────────────────────────────────────────────
// Full Flow Integration Tests
// ──────────────────────────────────────────────────────────────────

test('full flow: store then recall returns relevant memories', function (): void {
    AgentMemory::fake();

    // Step 1: Store multiple memories over time
    AgentMemory::store('User works at Acme Corp', ['user_id' => 'user-42']);
    AgentMemory::store('User prefers PHP over Python', ['user_id' => 'user-42']);
    AgentMemory::store('User timezone is UTC+4', ['user_id' => 'user-42']);

    // Step 2: Recall with a query
    $results = AgentMemory::recall('What company does the user work at?', ['user_id' => 'user-42']);

    // Step 3: Verify we get results
    expect($results)->not->toBeEmpty()
        ->and($results->pluck('content')->toArray())->toContain('User works at Acme Corp');

    // Step 4: Verify embedding generation was called for store + recall
    Embeddings::assertGenerated(fn (EmbeddingsPrompt $prompt): bool => $prompt->contains('User works at Acme Corp'));
});

test('full flow: store, forget, recall no longer returns deleted memory', function (): void {
    AgentMemory::fake();

    // Store
    $memory = AgentMemory::store('Outdated preference', ['user_id' => 'user-42']);

    // Forget
    AgentMemory::forget($memory->id);

    // Recall should not find the deleted memory
    $results = AgentMemory::recall('preference', ['user_id' => 'user-42']);

    expect($results)->toBeEmpty();
});

test('full flow: forgetAll clears all memories then recall returns empty', function (): void {
    AgentMemory::fake();

    AgentMemory::store('Memory A', ['user_id' => 'user-42']);
    AgentMemory::store('Memory B', ['user_id' => 'user-42']);

    AgentMemory::forgetAll(['user_id' => 'user-42']);

    $results = AgentMemory::recall('anything', ['user_id' => 'user-42']);

    expect($results)->toBeEmpty();
});

test('memories are isolated between users in full flow', function (): void {
    AgentMemory::fake();

    // Store for different users
    AgentMemory::store('Alice likes cats', ['user_id' => 'alice']);
    AgentMemory::store('Bob likes dogs', ['user_id' => 'bob']);

    // Alice should only see her memories
    $aliceMemories = AgentMemory::recall('pets', ['user_id' => 'alice']);
    expect($aliceMemories)->toHaveCount(1)
        ->and($aliceMemories->first()->content)->toBe('Alice likes cats');

    // Bob should only see his memories
    $bobMemories = AgentMemory::recall('pets', ['user_id' => 'bob']);
    expect($bobMemories)->toHaveCount(1)
        ->and($bobMemories->first()->content)->toBe('Bob likes dogs');
});
