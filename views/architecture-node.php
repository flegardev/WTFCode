<?php

declare(strict_types=1);

/** @var array<string, mixed> $architectureNode */
/** @var string $architectureHref */
?>
<a class="architecture-node <?= e((string) $architectureNode['node_type']) ?>" href="<?= e($architectureHref) ?>">
    <span><?= e((string) $architectureNode['node_type']) ?> · evidence found</span>
    <strong><?= e((string) $architectureNode['label']) ?></strong>
    <p><?= e((string) $architectureNode['plain_explanation']) ?></p>
</a>
