<?php

declare(strict_types=1);

namespace Drupal\aabenforms_digital_post\Memo;

use DigitalPost\MeMo\AdditionalDocument;
use DigitalPost\MeMo\File;
use DigitalPost\MeMo\MainDocument;
use DigitalPost\MeMo\Message;
use DigitalPost\MeMo\MessageBody;
use DigitalPost\MeMo\MessageHeader;
use DigitalPost\MeMo\Recipient as MeMoRecipient;
use DigitalPost\MeMo\Sender as MeMoSender;
use Drupal\aabenforms_digital_post\DigitalPost\Attachment;
use Drupal\aabenforms_digital_post\DigitalPost\DigitalPost;
use Drupal\aabenforms_digital_post\DigitalPost\Recipient;
use Drupal\aabenforms_digital_post\DigitalPost\Sender;
use ItkDev\Serviceplatformen\Service\SF1601\Serializer;
use ItkDev\Serviceplatformen\Service\SF1601\SF1601;

/**
 * Builds real SF1601 MeMo messages from the module's DigitalPost DTO.
 *
 * MeMo is the national Digital Post message format. This builder maps the
 * transport-agnostic DigitalPost DTO onto the value objects shipped by
 * itk-dev/serviceplatformen (lib/DigitalPost/MeMo/*) and serialises them to
 * XML with the library's Serializer - a pure, in-process operation that needs
 * NO certificate and NO network. The same builder is the single source of
 * truth for every transport: the fake_db logger stores the XML as evidence,
 * the WireMock transport POSTs it, and a future live client passes the Message
 * object straight to SF1601::kombiPostAfsend().
 *
 * Construction mirrors the reference implementation
 * OS2Forms os2forms_digital_post MeMoHelper::buildMessage() and the library's
 * own KombiPostAfsendCommand::buildMessage().
 */
class MemoBuilder {

  /**
   * Default MeMo version (1.2; 1.1 also supported by the library).
   */
  protected const DEFAULT_MEMO_VERSION = 1.2;

  /**
   * Builds the MeMo Message object for a Digital Post.
   *
   * This is the reuse seam: a live SF1601 client passes the returned object to
   * kombiPostAfsend() unchanged.
   *
   * @param \Drupal\aabenforms_digital_post\DigitalPost\DigitalPost $post
   *   The Digital Post to render.
   *
   * @return \DigitalPost\MeMo\Message
   *   The MeMo message value object.
   */
  public function buildMessage(DigitalPost $post): Message {
    return $this->withoutVendorDeprecations(fn () => $this->doBuildMessage($post));
  }

  /**
   * Builds the MeMo Message (see buildMessage()).
   */
  protected function doBuildMessage(DigitalPost $post): Message {
    $header = (new MessageHeader())
      ->setMessageType(SF1601::MESSAGE_TYPE_DIGITAL_POST)
      ->setMessageUUID(Serializer::createUuid())
      ->setMessageID(Serializer::createUuid())
      ->setLabel($post->subject)
      ->setMandatory(FALSE)
      ->setLegalNotification(FALSE)
      ->setSender($this->buildSender($post->sender))
      ->setRecipient($this->buildRecipient($post->recipient));

    // MeMo has no free-text body element - the message body is carried as a
    // document. Render the (HTML) body as the main document.
    $mainDocument = (new MainDocument())
      ->setFile([$this->buildBodyFile($post)]);

    $body = (new MessageBody())
      ->setCreatedDateTime(new \DateTime())
      ->setMainDocument($mainDocument);

    foreach ($post->attachments as $attachment) {
      $body->addToAdditionalDocument($this->buildAttachmentDocument($attachment));
    }

    $version = (float) ($post->meta['memo_version'] ?? self::DEFAULT_MEMO_VERSION);

    return (new Message())
      ->setMemoVersion($version)
      ->setMessageHeader($header)
      ->setMessageBody($body);
  }

  /**
   * Serialises a MeMo Message to an XML string (no cert, no network).
   */
  public function serializeMessage(Message $message): string {
    return $this->withoutVendorDeprecations(fn () => (new Serializer())->serialize($message));
  }

  /**
   * Runs a callback with E_DEPRECATED silenced.
   *
   * The vendored itk-dev/serviceplatformen MeMo classes emit PHP 8.4
   * implicit-nullable deprecations at load time. Silence them here so callers
   * (and tests) get clean output; restore the prior level afterwards.
   */
  protected function withoutVendorDeprecations(callable $fn): mixed {
    $previous = error_reporting();
    error_reporting($previous & ~E_DEPRECATED);
    try {
      return $fn();
    }
    finally {
      error_reporting($previous);
    }
  }

  /**
   * Builds the full SF1601 kombi_request wire payload for a Digital Post.
   *
   * Mirrors SF1601::buildKombiRequestDocument() (the KombiValgKode wrapper
   * around the MeMo Message) without invoking the certificate-signed SOAP call.
   * This is the exact XML a live send would post, so it is the most faithful
   * artifact to store as evidence or to send to a mock endpoint.
   */
  public function buildKombiRequestXml(DigitalPost $post): string {
    $messageXml = $this->serializeMessage($this->buildMessage($post));
    // Drop the inner XML declaration so the Message nests inside the wrapper.
    $messageXml = preg_replace('/^\s*<\?xml[^>]*\?>\s*/', '', $messageXml);
    $kombiValgKode = htmlspecialchars($post->type, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    return '<kombi_request><KombiValgKode>' . $kombiValgKode . '</KombiValgKode>'
      . $messageXml . '</kombi_request>';
  }

  /**
   * Maps the DTO sender to a MeMo Sender.
   *
   * MeMo requires a non-empty sender label; the DTO name is optional, so fall
   * back to the CVR when it is empty.
   */
  protected function buildSender(Sender $sender): MeMoSender {
    $label = $sender->name !== '' ? $sender->name : $sender->cvr;
    return (new MeMoSender())
      ->setIdType('CVR')
      ->setSenderID($sender->cvr)
      ->setLabel($label);
  }

  /**
   * Maps the DTO recipient to a MeMo Recipient (idType uppercased).
   */
  protected function buildRecipient(Recipient $recipient): MeMoRecipient {
    return (new MeMoRecipient())
      ->setIdType(strtoupper($recipient->type))
      ->setRecipientID($recipient->identifier);
  }

  /**
   * Renders the Digital Post body as the MeMo main-document File.
   */
  protected function buildBodyFile(DigitalPost $post): File {
    return (new File())
      ->setEncodingFormat('text/html')
      ->setLanguage('da')
      ->setFilename('besked.html')
      // Raw bytes; the serializer base64-encodes the content element.
      ->setContent($post->body);
  }

  /**
   * Maps an attachment to a MeMo AdditionalDocument.
   */
  protected function buildAttachmentDocument(Attachment $attachment): AdditionalDocument {
    $file = (new File())
      ->setEncodingFormat($attachment->mimeType)
      ->setLanguage('da')
      ->setFilename($attachment->filename)
      ->setContent($attachment->bytes());
    return (new AdditionalDocument())
      ->setLabel($attachment->filename)
      ->setFile([$file]);
  }

}
