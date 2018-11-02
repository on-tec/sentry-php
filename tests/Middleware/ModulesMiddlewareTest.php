<?php

/*
 * This file is part of Raven.
 *
 * (c) Sentry Team
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sentry\Tests\Middleware;

use Sentry\Configuration;
use Sentry\Event;
use Sentry\Middleware\ModulesMiddleware;

class ModulesMiddlewareTest extends MiddlewareTestCase
{
    public function testInvoke()
    {
        $configuration = new Configuration(['project_root' => __DIR__ . '/../Fixtures']);
        $event = new Event($configuration);

        $middleware = new ModulesMiddleware($configuration);

        $returnedEvent = $this->assertMiddlewareInvokesNext($middleware, $event);

        $this->assertEquals(['foo/bar' => '1.2.3.0', 'foo/baz' => '4.5.6.0'], $returnedEvent->getModules());
    }
}