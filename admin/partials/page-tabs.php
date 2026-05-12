<?php
// Expects $tabs (assoc array of slug => label), $activeTab (string), $basePath (string).
?>
<nav class="page-tabs" aria-label="Page tabs">
    <?php foreach ($tabs as $slug => $label): ?>
        <a href="<?= htmlspecialchars($basePath) ?>?tab=<?= htmlspecialchars($slug) ?>"
           class="<?= $slug === $activeTab ? 'active' : '' ?>"><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
</nav>
