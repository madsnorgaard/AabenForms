<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_core\Unit\Http;

use Drupal\aabenforms_core\Http\SessionBearer;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @coversDefaultClass \Drupal\aabenforms_core\Http\SessionBearer
 * @group aabenforms_core
 */
final class SessionBearerTest extends UnitTestCase {

  /**
   * Only a well-formed Authorization header yields a session id.
   */
  public function testFromRequest(): void {
    $request = Request::create('/api/x');
    $request->headers->set('Authorization', 'Bearer wf_abc12345');
    $this->assertSame('wf_abc12345', SessionBearer::fromRequest($request));

    $this->assertSame('', SessionBearer::fromRequest(Request::create('/api/x')));
    $this->assertSame('', SessionBearer::fromRequest(Request::create('/api/x', 'GET', ['session' => 'wf_abc12345'])), 'The query string is never read');

    foreach (['Basic wf_abc12345', 'Bearer short', 'Bearer wf_abc 12345', 'Bearer wf_abc<12345>'] as $bad) {
      $request = Request::create('/api/x');
      $request->headers->set('Authorization', $bad);
      $this->assertSame('', SessionBearer::fromRequest($request), $bad);
    }
  }

}
