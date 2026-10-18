<?php /** @var \Emc\Core\Csrf $csrf */ ?>
<input type="hidden" name="_csrf" value="<?= e($csrf->token()) ?>">
