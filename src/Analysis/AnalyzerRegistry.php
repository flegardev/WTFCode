<?php

declare(strict_types=1);

final class AnalyzerRegistry
{
    /** @var array<string, AnalyzerProviderInterface> */
    private array $providers = [];

    /** @param array<int, AnalyzerProviderInterface>|null $providers */
    public function __construct(?array $providers = null)
    {
        foreach ($providers ?? [new NativeAnalyzerProvider()] as $provider) {
            $this->register($provider);
        }
    }

    public function register(AnalyzerProviderInterface $provider): void
    {
        $id = strtolower(trim($provider->id()));
        if ($id === '' || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $id) !== 1) {
            throw new InvalidArgumentException('Analyzer provider IDs must be stable lowercase identifiers.');
        }
        if (isset($this->providers[$id])) {
            throw new LogicException('Analyzer provider already registered: ' . $id);
        }
        $this->providers[$id] = $provider;
    }

    /** @return array<int, AnalyzerProviderInterface> */
    public function all(): array
    {
        return array_values($this->providers);
    }

    public function get(string $id): ?AnalyzerProviderInterface
    {
        return $this->providers[strtolower($id)] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    public function status(): array
    {
        $status = [];
        foreach ($this->providers as $provider) {
            try {
                $health = $provider->healthCheck()->toArray();
            } catch (Throwable $exception) {
                $health = ['status' => 'failed', 'message' => 'Health check failed independently: ' . get_class($exception), 'version' => null];
            }
            $status[] = [
                'id' => $provider->id(),
                'version' => $provider->version(),
                'languages' => $provider->supportedLanguages(),
                'capabilities' => $provider->capabilities(),
                'available' => $provider->isAvailable(),
                'health' => $health,
            ];
        }
        return $status;
    }
}
