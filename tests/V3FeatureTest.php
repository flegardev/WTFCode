<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function feature_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$path = 'src/CheckoutService.php';
$content = <<<'PHP'
<?php
final class CheckoutService {
  public function checkout(array $input): array {
    authorize($input);
    if (!validate($input)) { throw new RuntimeException('invalid'); }
    $db->prepare('INSERT INTO orders (user_id) VALUES (?)');
    $response = fetch('https://api.stripe.com/v1/payment_intents', ['method' => 'POST']);
    return ['success' => true, 'response' => $response];
  }
}
PHP;
$file = ['path' => $path, 'language' => 'PHP', 'content' => $content, 'lines' => 10];
$base = (new NativeAnalyzerProvider())->analyze(new AnalysisRequest(__DIR__, [$file], AnalysisProfile::QUICK));
$baseGraph = $base->graph;
$graph = (new ProductIntelligence())->enrich($baseGraph, [$file]);

$operations = array_values(array_filter($graph['relationships'], static fn (array $edge): bool => ($edge['metadata']['database_operation'] ?? null) === 'CREATE'));
feature_assert($operations !== [] && ($operations[0]['type'] ?? '') === 'creates_in_table', 'INSERT must be classified as a CREATE operation');
$controls = array_unique(array_filter(array_column(array_column($graph['relationships'], 'metadata'), 'control_flow')));
foreach (['authorization', 'validation', 'condition', 'throw', 'return', 'success'] as $expected) feature_assert(in_array($expected, $controls, true), 'Control-flow enrichment must detect ' . $expected);
$services = array_values(array_filter($graph['symbols'], static fn (array $symbol): bool => ($symbol['type'] ?? '') === 'external_service' && ($symbol['name'] ?? '') === 'Stripe'));
feature_assert($services !== [], 'Runtime Stripe use must create a service boundary');
$featureLabels = array_column($graph['features'], 'label');
feature_assert(in_array('Checkout', $featureLabels, true), 'Checkout needs multi-signal feature detection');
feature_assert(in_array('Payments', $featureLabels, true), 'Payments needs multi-signal feature detection');
feature_assert(($graph['stats']['database_operations'] ?? 0) >= 1, 'Product intelligence stats must count database operations');

$docs = [['path' => 'README.md', 'language' => 'Markdown', 'content' => 'Stripe OpenAI Docker Kubernetes checkout payments', 'lines' => 1]];
$docsResult = (new NativeAnalyzerProvider())->analyze(new AnalysisRequest(__DIR__, $docs, AnalysisProfile::QUICK));
$docsBaseGraph = $docsResult->graph;
$docsGraph = (new ProductIntelligence())->enrich($docsBaseGraph, $docs);
feature_assert(array_filter($docsGraph['symbols'], static fn (array $symbol): bool => ($symbol['type'] ?? '') === 'external_service') === [], 'README text alone must not prove an external service');

echo "WTFCode V3 feature intelligence checks passed.\n";
