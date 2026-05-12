<?php
// View partial for Tools → Import. Variables come from import.handler.php.
use CMS\Helpers;
?>
<div class="panel" style="max-width:560px">
    <h2>WordPress XML (WXR)</h2>
    <p>Upload a WordPress eXtended RSS export — works with Micro.blog and any WordPress site. Imported posts are never syndicated to Mastodon or Bluesky, even when published.</p>

    <table class="data-table" style="margin-bottom:1.25rem">
        <tbody>
            <tr><td>Total posts in DB</td><td style="text-align:right"><?= $totalPosts ?></td></tr>
            <tr><td>Previously imported via WXR</td><td style="text-align:right"><?= $importedCount ?></td></tr>
            <tr><td>PHP <code>upload_max_filesize</code></td><td style="text-align:right"><?= Helpers::e($uploadMaxBytes) ?></td></tr>
            <tr><td>PHP <code>post_max_size</code></td><td style="text-align:right"><?= Helpers::e($postMaxBytes) ?></td></tr>
        </tbody>
    </table>

    <form method="post" action="/admin/tools.php?tab=import" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">

        <div style="margin-bottom:1rem">
            <label for="wxr_file" style="display:block;margin-bottom:.35rem;font-weight:600">WXR file</label>
            <input type="file" id="wxr_file" name="wxr_file" accept=".xml,application/xml,text/xml" required>
        </div>

        <div style="margin-bottom:1rem">
            <label for="kind_mode" style="display:block;margin-bottom:.35rem;font-weight:600">Post kind</label>
            <select id="kind_mode" name="kind_mode">
                <option value="auto" selected>Auto — aside if titleless, else standard</option>
                <option value="aside">All asides (titleless notes)</option>
                <option value="standard">All standard posts</option>
            </select>
        </div>

        <div style="margin-bottom:1rem">
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                <input type="checkbox" name="download_images" value="1">
                Download remote images locally (rewrites <code>&lt;img&gt;</code> URLs to <code>/media/</code>)
            </label>
            <p style="font-size:.8125rem;color:var(--muted, #666);margin:.35rem 0 0">
                Adds 1–3 s per image. Failures are logged and the original URL is left in place. You can also run this later from the <a href="/admin/tools.php?tab=import-media">Import media</a> tab.
            </p>
        </div>

        <p style="font-size:.875rem;color:var(--muted, #666);margin:0 0 1rem">
            Re-uploading the same file is safe — items already imported (matched by <code>&lt;guid&gt;</code>) are skipped.
            Trashed items and non-post items (pages, attachments) are skipped automatically.
        </p>

        <button type="submit" class="btn">Import</button>
    </form>
</div>
