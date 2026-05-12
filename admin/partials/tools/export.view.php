<?php
// View partial for Tools → Export.
use CMS\Helpers;
?>
<div class="panel" style="max-width:520px">
    <h2>WordPress XML (WXR)</h2>
    <p>Download all posts and pages in WordPress eXtended RSS format. You can import this file into any WordPress site using <strong>Tools → Import → WordPress</strong>.</p>

    <table class="data-table" style="margin-bottom:1.25rem">
        <tbody>
            <tr><td>Published posts</td><td style="text-align:right"><?= $postCount ?></td></tr>
            <tr><td>Post drafts &amp; scheduled</td><td style="text-align:right"><?= $draftCount ?></td></tr>
            <tr><td>Published pages</td><td style="text-align:right"><?= $exportPageCount ?></td></tr>
            <tr><td>Page drafts</td><td style="text-align:right"><?= $pageDraftCount ?></td></tr>
            <tr><td>Categories</td><td style="text-align:right"><?= $catCount ?></td></tr>
            <tr><td>Tags</td><td style="text-align:right"><?= $tagCount ?></td></tr>
        </tbody>
    </table>

    <form method="post" action="/admin/tools.php?tab=export">
        <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
        <div style="margin-bottom:1rem">
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                <input type="checkbox" name="include_drafts" value="1">
                Include drafts and scheduled posts &amp; page drafts
            </label>
        </div>
        <button type="submit" class="btn">
            Download export file
        </button>
    </form>
</div>
