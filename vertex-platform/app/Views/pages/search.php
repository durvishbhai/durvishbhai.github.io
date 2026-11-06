<?php /** @var string $q */ /** @var list<array{label:string,total:int,more:string,items:list<array{title:string,sub:string,url:string}>}> $groups */ /** @var bool $tooShort */ ?>
<p class="eyebrow">Search</p>
<h1>Search</h1>
<form method="get" action="/search" class="toolbar" role="search"><div class="field"><label for="q">Search everything you can open</label><input id="q" name="q" type="search" maxlength="100" value="<?= e($q) ?>" autofocus placeholder="Name, reference, email, title"></div><div class="field"><button class="btn btn-primary" type="submit">Search</button></div></form>
<?php if ($tooShort) : ?><p class="muted">Type at least 2 characters.</p>
<?php elseif ($q !== '' && $groups === []) : ?><p class="muted">Nothing found for &ldquo;<?= e($q) ?>&rdquo; in the areas you can open.</p><?php endif; ?>
<?php foreach ($groups as $g) : ?>
    <div class="panel"><h2><?= e($g['label']) ?> <span class="muted">(<?= (int) $g['total'] ?>)</span></h2>
        <ul class="plain"><?php foreach ($g['items'] as $it) : ?><li><a href="<?= e($it['url']) ?>"><strong><?= e($it['title']) ?></strong></a><?php if ($it['sub'] !== '') : ?><br><span class="muted"><?= e($it['sub']) ?></span><?php endif; ?></li><?php endforeach; ?></ul>
        <?php if ($g['total'] > count($g['items'])) : ?><p><a href="<?= e($g['more']) ?>">See all <?= (int) $g['total'] ?></a></p><?php endif; ?></div>
<?php endforeach; ?>
