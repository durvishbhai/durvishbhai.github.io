<?php /** @var list<string> $errors The problems with the form, shown inside the form itself so they are never off-screen. The page script fills the same box before the form is sent. */ ?>
<div class="form-errors" data-form-errors tabindex="-1" role="alert"<?= $errors === [] ? ' hidden' : '' ?>>
    <strong>Your application was not sent yet.</strong>
    <ul><?php foreach ($errors as $err) : ?><li><?= e((string) $err) ?></li><?php endforeach; ?></ul>
</div>
