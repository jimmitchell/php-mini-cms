<?php
/**
 * Newsletter signup form partial.
 * Optional variable: $source — stored on the subscriber row (e.g. post slug).
 *
 * The banner divs are always baked in; an inline script below the form
 * unhides the correct one when ?subscribed=1 or ?subscribed=err is in the URL.
 */
$source = $source ?? '';
?>
<aside class="newsletter">
    <div class="newsletter__banner newsletter__banner--success" hidden>
        Thanks — you're subscribed.
    </div>
    <div class="newsletter__banner newsletter__banner--error" hidden>
        That didn't look like a valid email. Please try again.
    </div>
    <form class="newsletter-form" action="/subscribe.php" method="post">
        <label for="newsletter-email" class="newsletter-form__label">
            Get new posts by email
        </label>
        <div class="newsletter-form__row">
            <input type="email" id="newsletter-email" name="email"
                   required autocomplete="email"
                   placeholder="you@example.com"
                   class="newsletter-form__input">
            <button type="submit" class="newsletter-form__button">Subscribe</button>
        </div>
        <input type="text" name="website" tabindex="-1" autocomplete="off"
               class="newsletter-form__honeypot" aria-hidden="true">
        <input type="hidden" name="source" value="<?= htmlspecialchars($source, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    </form>
</aside>
<script>
(function () {
    var m = /[?&]subscribed=(1|err)/.exec(window.location.search);
    if (!m) return;
    var sel = m[1] === '1' ? '.newsletter__banner--success' : '.newsletter__banner--error';
    var el = document.querySelector(sel);
    if (el) el.hidden = false;
})();
</script>
