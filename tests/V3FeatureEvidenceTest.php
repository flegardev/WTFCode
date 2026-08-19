<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

function feature_evidence_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @param array<int, array<string, mixed>> $files @return array<string, mixed> */
function feature_evidence_graph(array $files): array
{
    $base = (new NativeAnalyzerProvider())->analyze(new AnalysisRequest(__DIR__, $files, AnalysisProfile::QUICK));
    $graph = $base->graph;
    return (new ProductIntelligence())->enrich($graph, $files);
}

$nonRuntime = [
    ['path' => 'tests/fixtures/fake-billing.ts', 'language' => 'TypeScript', 'content' => "router.post('/billing', fakeBilling);\nnew StripeClient().invoices.create();", 'lines' => 2],
    ['path' => 'src/Detector.php', 'language' => 'PHP', 'content' => '<?php final class Detector implements AnalyzerProviderInterface { public function analyze(AnalysisRequest $request): AnalyzerResult { $patterns = ["client stripe", "payments", "billing"]; preg_match($patterns[0], $source); $graph->addSymbol([]); } }', 'lines' => 1],
    ['path' => 'README.md', 'language' => 'Markdown', 'content' => 'Authentication login sessions and Stripe billing are example capabilities.', 'lines' => 1],
];
$nonRuntimeGraph = feature_evidence_graph($nonRuntime);
$nonRuntimeLabels = array_column($nonRuntimeGraph['features'] ?? [], 'label');
feature_evidence_assert(!in_array('Billing', $nonRuntimeLabels, true), 'A billing fixture must not create a production Billing feature');
feature_evidence_assert(!in_array('Payments', $nonRuntimeLabels, true), 'A detector Stripe example must not create a production Payments feature');
feature_evidence_assert(!in_array('Login', $nonRuntimeLabels, true), 'README authentication prose must not create runtime Login evidence');
feature_evidence_assert(array_filter($nonRuntimeGraph['symbols'], static fn (array $symbol): bool => ($symbol['type'] ?? '') === 'external_service') === [], 'Non-runtime Stripe examples must not create a service boundary');

$catalogFiles = [
    ['path' => 'templates/ui/chat.json', 'language' => 'JSON', 'content' => '{"name":"ui-chat","repo":"templates/chat"}', 'lines' => 1],
];
$catalogGraph = [
    'symbols' => [['key' => 'catalog-module', 'path' => 'templates/ui/chat.json', 'language' => 'JSON', 'type' => 'module', 'name' => 'chat', 'qualified_name' => 'templates/ui/chat.json', 'parent_key' => null, 'signature' => null, 'visibility' => 'unknown', 'exported' => false, 'start_line' => 1, 'end_line' => 1, 'confidence' => 'high', 'metadata' => []]],
    'relationships' => [], 'routes' => [], 'stats' => [],
];
$catalogGraph = (new ProductIntelligence())->enrich($catalogGraph, $catalogFiles);
feature_evidence_assert(!in_array('AI chat', array_column($catalogGraph['features'] ?? [], 'label'), true), 'Catalogue JSON named chat must not become a runtime AI feature');

$serviceWorkerGraph = feature_evidence_graph([
    ['path' => 'src/serviceWorker.js', 'language' => 'JavaScript', 'content' => "export function registration() { return navigator.serviceWorker.register('/sw.js'); }", 'lines' => 1],
]);
feature_evidence_assert(!in_array('Registration', array_column($serviceWorkerGraph['features'] ?? [], 'label'), true), 'Service-worker registration must not become user Registration');

$planningGraph = feature_evidence_graph([
    ['path' => 'src/migrations.py', 'language' => 'Python', 'content' => "def migration_plan():\n    plan = []\n    return plan", 'lines' => 3],
]);
feature_evidence_assert(!in_array('Billing', array_column($planningGraph['features'] ?? [], 'label'), true), 'Generic planning code must not become Billing');

$technicalTermsGraph = feature_evidence_graph([
    ['path' => 'src/TechnicalUtilities.ts', 'language' => 'TypeScript', 'content' => "export function session() { return query('email'); }", 'lines' => 1],
]);
$technicalLabels = array_column($technicalTermsGraph['features'] ?? [], 'label');
feature_evidence_assert(!in_array('Login', $technicalLabels, true) && !in_array('Search', $technicalLabels, true) && !in_array('Notifications', $technicalLabels, true), 'Generic session, query, and email terms must not become product features');

$runtime = [
    ['path' => 'routes/billing.ts', 'language' => 'TypeScript', 'content' => "import { BillingService } from '../src/BillingService';\nrouter.post('/billing/invoices', BillingService.createInvoice);", 'lines' => 2],
    ['path' => 'src/BillingService.ts', 'language' => 'TypeScript', 'content' => "export class BillingService {\n  createInvoice() {\n    const stripe = new StripeClient();\n    return stripe.invoices.create({ customer: 'runtime' });\n  }\n}", 'lines' => 6],
];
$runtimeGraph = feature_evidence_graph($runtime);
$runtimeLabels = array_column($runtimeGraph['features'] ?? [], 'label');
feature_evidence_assert(in_array('Billing', $runtimeLabels, true), 'A runtime billing route and service must create Billing');
feature_evidence_assert(in_array('Payments', $runtimeLabels, true), 'A runtime Stripe client invocation must contribute Payments evidence');
$stripe = array_values(array_filter($runtimeGraph['symbols'], static fn (array $symbol): bool => ($symbol['type'] ?? '') === 'external_service' && ($symbol['name'] ?? '') === 'Stripe'));
feature_evidence_assert($stripe !== [], 'A runtime Stripe client invocation must create a runtime service boundary');

$self = (new RepoScanner())->inspect(dirname(__DIR__), AnalysisProfile::QUICK);
$selfLabels = array_column($self['symbol_graph']['features'] ?? [], 'label');
foreach (['Login', 'Repository import', 'Repository scan', 'Feature tracing', 'Safe prompt generation', 'Git comparison'] as $expected) {
    feature_evidence_assert(in_array($expected, $selfLabels, true), 'WTFCode runtime feature ranking must retain ' . $expected);
}
feature_evidence_assert(!in_array('Billing', $selfLabels, true), 'WTFCode fixture and analyzer text must not fabricate Billing');
feature_evidence_assert(!in_array('Payments', $selfLabels, true), 'WTFCode fixture and analyzer text must not fabricate Payments');

echo "WTFCode V3 runtime feature-evidence checks passed.\n";
