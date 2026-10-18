<?php
/** @var list<array{typ:string,text:string,link:string,linktext:string}> $betriebshinweise */
foreach (($betriebshinweise ?? []) as $hinweis): ?>
    <div class="alert alert-<?= e($hinweis['typ']) ?>" role="alert">
        <?= e($hinweis['text']) ?>
        <a class="alert-link" href="<?= e(url($hinweis['link'])) ?>"><?= e($hinweis['linktext']) ?></a>
    </div>
<?php endforeach; ?>
