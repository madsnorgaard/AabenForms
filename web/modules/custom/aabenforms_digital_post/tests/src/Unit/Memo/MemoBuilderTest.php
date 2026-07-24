<?php

declare(strict_types=1);

namespace Drupal\Tests\aabenforms_digital_post\Unit\Memo;

use Drupal\aabenforms_digital_post\DigitalPost\Attachment;
use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Recipient;
use Drupal\aabenforms_digital_post\DigitalPost\Sender;
use Drupal\aabenforms_digital_post\Memo\MemoBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * Tests that MemoBuilder produces valid SF1601 MeMo XML.
 *
 * @coversDefaultClass \Drupal\aabenforms_digital_post\Memo\MemoBuilder
 * @group aabenforms_digital_post
 */
class MemoBuilderTest extends UnitTestCase {

  private const NS = 'https://DigitalPost.dk/MeMo-1';

  /**
   * A Digital Post maps to a well-formed kombi_request with a MeMo Message.
   */
  public function testBuildsValidMemoKombiRequest(): void {
    $post = new DigitalPost(
      Recipient::cpr('2506924015'),
      new Sender('12345678', 'Test Kommune'),
      'Afgørelse: merudgiftsydelse',
      '<p>Din ansøgning er imødekommet.</p>',
      [],
      DigitalPost::TYPE_DIGITAL_POST,
      ['memo_version' => 1.2],
    );

    $xml = (new MemoBuilder())->buildKombiRequestXml($post);

    $dom = new \DOMDocument();
    $this->assertTrue($dom->loadXML($xml), 'MeMo XML is well-formed.');
    $this->assertSame('kombi_request', $dom->documentElement->nodeName);

    $xpath = new \DOMXPath($dom);
    $xpath->registerNamespace('memo', self::NS);

    $this->assertSame('Digital Post', $this->text($xpath, '//KombiValgKode'));
    $this->assertSame('DIGITALPOST', $this->text($xpath, '//memo:messageType'));
    $this->assertSame('Afgørelse: merudgiftsydelse', $this->text($xpath, '//memo:MessageHeader/memo:label'));
    $this->assertSame('12345678', $this->text($xpath, '//memo:senderID'));
    $this->assertSame('CVR', $this->text($xpath, '//memo:Sender/memo:idType'));
    $this->assertSame('Test Kommune', $this->text($xpath, '//memo:Sender/memo:label'));
    $this->assertSame('2506924015', $this->text($xpath, '//memo:recipientID'));
    $this->assertSame('CPR', $this->text($xpath, '//memo:Recipient/memo:idType'));
    $this->assertSame('text/html', $this->text($xpath, '//memo:encodingFormat'));
    $this->assertSame('besked.html', $this->text($xpath, '//memo:filename'));
    // The HTML body is carried as base64 in the main document's File content.
    $this->assertSame(
      base64_encode('<p>Din ansøgning er imødekommet.</p>'),
      $this->text($xpath, '//memo:content'),
    );
  }

  /**
   * An empty sender name falls back to the CVR (MeMo requires a label).
   */
  public function testSenderLabelFallsBackToCvr(): void {
    $post = new DigitalPost(
      Recipient::cvr('12345678'),
      new Sender('87654321'),
      'Emne',
      '<p>Body</p>',
    );

    $xml = (new MemoBuilder())->buildKombiRequestXml($post);
    $xpath = new \DOMXPath($this->load($xml));
    $xpath->registerNamespace('memo', self::NS);

    $this->assertSame('87654321', $this->text($xpath, '//memo:Sender/memo:label'));
    $this->assertSame('CVR', $this->text($xpath, '//memo:Recipient/memo:idType'));
  }

  /**
   * Attachments become AdditionalDocument entries.
   */
  public function testAttachmentBecomesAdditionalDocument(): void {
    $post = new DigitalPost(
      Recipient::cpr('2506924015'),
      new Sender('12345678', 'Test Kommune'),
      'Emne',
      '<p>Body</p>',
      [Attachment::fromBytes('PDFDATA', 'bilag.pdf', 'application/pdf')],
    );

    $xml = (new MemoBuilder())->buildKombiRequestXml($post);
    $xpath = new \DOMXPath($this->load($xml));
    $xpath->registerNamespace('memo', self::NS);

    $this->assertSame('bilag.pdf', $this->text($xpath, '//memo:AdditionalDocument/memo:File/memo:filename'));
    $this->assertSame('application/pdf', $this->text($xpath, '//memo:AdditionalDocument/memo:File/memo:encodingFormat'));
  }

  /**
   * Loads XML into a DOMDocument, asserting well-formedness.
   */
  private function load(string $xml): \DOMDocument {
    $dom = new \DOMDocument();
    $this->assertTrue($dom->loadXML($xml));
    return $dom;
  }

  /**
   * Returns the trimmed text content of the first node matching an XPath.
   */
  private function text(\DOMXPath $xpath, string $expression): string {
    $node = $xpath->query($expression)->item(0);
    return $node ? trim($node->textContent) : '';
  }

}
