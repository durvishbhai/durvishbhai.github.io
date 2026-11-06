<?php
/** @var list<string>|null $errors */
/** @var string|null $notice */
?>
<?php if (!empty($notice)) : ?>
    <div class="alert alert-ok" role="status"><p><?= e($notice) ?></p></div>
<?php endif; ?>
<?php if (!empty($errors)) : ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $err) : ?>
            <p><?= e($err) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
