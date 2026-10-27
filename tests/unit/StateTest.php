<?php

declare(strict_types=1);

namespace coyshdigital\smallchange\tests\unit;

use Codeception\Test\Unit;
use coyshdigital\smallchange\db\Table;
use coyshdigital\smallchange\Plugin;
use yii\db\Query;

final class StateTest extends Unit
{
    public function testAValueIsMissingUntilSet(): void
    {
        $this->assertNull(Plugin::getInstance()->state->get('endpoint', 'test'));
    }

    public function testSettingTwiceKeepsOneRowPerNameAndMode(): void
    {
        $state = Plugin::getInstance()->state;

        $state->set('endpoint', 'test', 'we_1');
        $state->set('endpoint', 'test', 'we_2');
        $state->set('endpoint', 'live', 'we_3');

        $this->assertSame('we_2', $state->get('endpoint', 'test'));
        $this->assertSame('we_3', $state->get('endpoint', 'live'));
        $this->assertSame(2, (int) (new Query())->from(Table::STATE)->where(['name' => 'endpoint'])->count());
    }

    public function testAValueCanBeCleared(): void
    {
        $state = Plugin::getInstance()->state;

        $state->set('endpoint', 'test', 'we_1');
        $state->set('endpoint', 'test', null);

        $this->assertNull($state->get('endpoint', 'test'));
    }
}
