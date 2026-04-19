<?php

use CMS\Helpers;

// Required variables (set by the including page before require):
//   int    $page              — current page number
//   int    $totalPages        — total number of pages
//   int    $_paginTotal       — total item count (for the "N items" label)
//   string $_paginLabel       — singular item noun, e.g. 'post' or 'page'
//   string $_paginationBase   — base URL with trailing '?' or '?foo=bar&'
?>
<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?>
        <a href="<?= Helpers::e($_paginationBase) ?>page=<?= $page - 1 ?>" class="btn btn--sm btn--secondary">&larr; Prev</a>
    <?php else: ?>
        <span class="btn btn--sm btn--secondary btn--disabled">&larr; Prev</span>
    <?php endif; ?>

    <span class="pagination__info">
        Page <?= $page ?> of <?= $totalPages ?>
        &nbsp;&middot;&nbsp;
        <?= number_format($_paginTotal) ?> <?= Helpers::e($_paginLabel) ?><?= $_paginTotal !== 1 ? 's' : '' ?>
    </span>

    <?php if ($page < $totalPages): ?>
        <a href="<?= Helpers::e($_paginationBase) ?>page=<?= $page + 1 ?>" class="btn btn--sm btn--secondary">Next &rarr;</a>
    <?php else: ?>
        <span class="btn btn--sm btn--secondary btn--disabled">Next &rarr;</span>
    <?php endif; ?>
</div>
<?php endif; ?>
