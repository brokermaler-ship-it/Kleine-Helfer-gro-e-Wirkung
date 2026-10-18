<?php

declare(strict_types=1);

namespace Emc\Tests\Unit\Core;

use Emc\Core\View;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    public function test_e_escapet_html(): void
    {
        $this->assertSame('&lt;b&gt;&quot;x&quot;&#039;', e('<b>"x"\''));
        $this->assertSame('', e(null));
    }

    public function test_render_mit_layout(): void
    {
        $view = new View(__DIR__ . '/../../fixtures/templates');
        $this->assertSame('[Hallo Lok]', $view->render('seite', ['name' => 'Lok'], 'layout'));
    }

    public function test_render_ohne_layout(): void
    {
        $view = new View(__DIR__ . '/../../fixtures/templates');
        $this->assertSame('Hallo Zug', $view->render('seite', ['name' => 'Zug'], null));
    }
}
