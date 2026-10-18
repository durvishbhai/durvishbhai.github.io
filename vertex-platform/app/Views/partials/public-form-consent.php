<?php $policy = \Vertex\Services\SiteContent::publishedBySlug('policies', 'privacy-policy'); ?>
<label class="check"><input type="checkbox" name="consent" value="1" required>
    <span>I agree that <?= e(\Vertex\Services\SiteText::company()) ?> may contact me about this request and keep the details I have entered for that purpose<?= $policy ? ', as described in the <a href="/privacy-policy">Privacy Policy</a>' : '' ?>.</span></label>
<p class="muted">We only use these details to respond to your request.</p>
