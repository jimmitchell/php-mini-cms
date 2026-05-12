<?php
// View partial for Tools → Import media.
use CMS\Helpers;
?>
<div class="panel" style="max-width:560px">
    <h2>Download external images</h2>
    <p>Scan every post for <code>&lt;img&gt;</code> URLs that point off-site, download the file into your media library, and rewrite the post HTML to use the local URL. Safe to re-run — already-downloaded URLs are skipped.</p>

    <table class="data-table" style="margin-bottom:1.25rem">
        <tbody>
            <tr><td>Posts containing <code>&lt;img&gt;</code></td><td style="text-align:right"><?= $postsWithImg ?></td></tr>
            <tr><td>Posts with external image URLs</td><td style="text-align:right"><?= $postsAffected ?></td></tr>
            <tr><td>Distinct external image URLs</td><td style="text-align:right"><?= $distinctExternal ?></td></tr>
            <tr><td>Media items previously imported by URL</td><td style="text-align:right"><?= $rehostedCount ?></td></tr>
        </tbody>
    </table>

    <?php if ($distinctExternal === 0): ?>
        <p style="color:var(--muted, #666);margin:0">Nothing to do — no external image URLs found.</p>
    <?php else: ?>
        <form method="post" action="/admin/tools.php?tab=import-media"
              onsubmit="return confirm('This may take several minutes for a large backlog. Continue?');">
            <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
            <p style="font-size:.875rem;color:var(--muted, #666);margin:0 0 1rem">
                Long-running operation. Behind a reverse proxy, ensure <code>proxy_read_timeout</code> /
                <code>fastcgi_read_timeout</code> are large enough (default 60s is not). Failures
                are logged and skipped — re-run to retry only those.
            </p>
            <button type="submit" class="btn">Download external images now</button>
        </form>
    <?php endif; ?>
</div>
