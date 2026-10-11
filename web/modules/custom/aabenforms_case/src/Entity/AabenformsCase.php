<?php

declare(strict_types=1);

namespace Drupal\aabenforms_case\Entity;

use Drupal\aabenforms_case\AabenformsCaseInterface;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Form\DeleteMultipleForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\RevisionableContentEntityBase;
use Drupal\Core\Entity\RevisionLogEntityTrait;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\aabenforms_case\AabenformsCaseAccessControlHandler;
use Drupal\aabenforms_case\AabenformsCaseListBuilder;

/**
 * Defines the ÅbenForms Case (sag) entity.
 *
 * A case is the persistent record of a piece of municipal casework. It is
 * opened from a webform submission (via the aabenforms_case_open ECA action),
 * carries a lawful lifecycle status and a deadline (frist) clock, and is
 * revisionable so that every status transition leaves an auditable revision
 * with a log message (who/when/why) - the compliance backbone.
 *
 * Privacy by design: the case references its submission and never copies the
 * raw CPR. The CPR stays encrypted at rest on the submission
 * (aabenforms_core_webform_submission_presave + CprAccess).
 */
#[ContentEntityType(
  id: 'aabenforms_case',
  label: new TranslatableMarkup('Case'),
  label_collection: new TranslatableMarkup('Cases'),
  label_singular: new TranslatableMarkup('case'),
  label_plural: new TranslatableMarkup('cases'),
  label_count: [
    'singular' => '@count case',
    'plural' => '@count cases',
  ],
  handlers: [
    'list_builder' => AabenformsCaseListBuilder::class,
    'access' => AabenformsCaseAccessControlHandler::class,
    'form' => [
      'default' => ContentEntityForm::class,
      'edit' => ContentEntityForm::class,
      'delete' => ContentEntityDeleteForm::class,
      'delete-multiple-confirm' => DeleteMultipleForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  base_table: 'aabenforms_case',
  revision_table: 'aabenforms_case_revision',
  admin_permission: 'administer aabenforms_case',
  entity_keys: [
    'id' => 'id',
    'revision' => 'vid',
    'uuid' => 'uuid',
    'label' => 'title',
  ],
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ],
  links: [
    'collection' => '/admin/aabenforms/cases',
    'edit-form' => '/admin/aabenforms/cases/{aabenforms_case}/edit',
    'delete-form' => '/admin/aabenforms/cases/{aabenforms_case}/delete',
  ],
)]
class AabenformsCase extends RevisionableContentEntityBase implements AabenformsCaseInterface {

  use EntityChangedTrait;
  use RevisionLogEntityTrait;

  /**
   * {@inheritdoc}
   */
  public function getCaseType(): string {
    return (string) $this->get('case_type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setStatus(string $status): static {
    $this->set('status', $status);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFristDue(): ?int {
    $value = $this->get('frist_due')->value;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getModtagelsesdato(): ?int {
    $value = $this->get('modtagelsesdato')->value;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getSubmissionId(): ?string {
    $target = $this->get('submission_ref')->target_id;
    return $target === NULL ? NULL : (string) $target;
  }

  /**
   * {@inheritdoc}
   */
  public function getSubjectCprHash(): ?string {
    $value = $this->get('subject_cpr_hash')->value;
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setSubjectCprHash(?string $hash): static {
    $this->set('subject_cpr_hash', $hash === '' ? NULL : $hash);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getAfgoerelseDato(): ?int {
    $value = $this->get('afgoerelse_dato')->value;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   *
   * Enforces the lawful lifecycle at the storage layer so no save path - not
   * the admin edit form, JSON:API, Views Bulk Operations, nor a stray
   * programmatic write - can reach a state the ECA actions would refuse. A
   * closed case is immutable, an illegal transition throws, and every mutation
   * is forced to a new audited revision (the compliance backbone must never
   * silently overwrite history via a default entity-form save).
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    // Nothing to guard on the founding save.
    if ($this->isNew()) {
      return;
    }

    $original = $this->originalStatus();
    if ($original !== NULL) {
      // A closed case is terminal: no field on it may change.
      if ($original === 'lukket') {
        throw new EntityStorageException(sprintf('Sag %s er lukket og kan ikke ændres.', (string) $this->id()));
      }
      $new = $this->getStatus();
      if ($new !== $original && !in_array($new, self::allowedTransitions()[$original] ?? [], TRUE)) {
        throw new EntityStorageException(sprintf('Ulovlig statusovergang på sag %s: %s → %s.', (string) $this->id(), $original, $new));
      }
    }

    // Every change to a case is an audited revision. ECA actions set a
    // meaningful log message; a bare entity-form save gets a default one.
    $this->setNewRevision(TRUE);
    if ((string) ($this->getRevisionLogMessage() ?? '') === '') {
      $this->setRevisionLogMessage('Sag opdateret.');
    }
    $this->setRevisionCreationTime(\Drupal::time()->getRequestTime());
    $this->setRevisionUserId((int) \Drupal::currentUser()->id());
  }

  /**
   * Reads the persisted status from before this save, if available.
   *
   * @return string|null
   *   The original status, or NULL when there is no loaded original.
   */
  protected function originalStatus(): ?string {
    $original = NULL;
    if (method_exists($this, 'getOriginal')) {
      // Drupal >= 11.2.
      $original = $this->getOriginal();
    }
    elseif (isset($this->original)) {
      // @phpstan-ignore-line - deprecated property fallback for Drupal < 11.2.
      $original = $this->original;
    }
    return $original instanceof AabenformsCaseInterface ? $original->getStatus() : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    /** @var \Drupal\Core\Field\BaseFieldDefinition[] $fields */
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Title'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['case_type'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Case type'))
      ->setDescription(new TranslatableMarkup('The casework area, e.g. underretning, friplads.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 64)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setDescription(new TranslatableMarkup('Lawful case lifecycle state.'))
      ->setRevisionable(TRUE)
      ->setRequired(TRUE)
      ->setDefaultValue('modtaget')
      ->setSetting('allowed_values', self::statusOptions())
      ->setDisplayConfigurable('view', TRUE)
      // Status is only ever changed through the lawful, audited transition
      // actions (aabenforms_case_transition / _decide / _appeal / _sf2900),
      // never freely via the entity form - preSave() enforces this.
      ->setDisplayConfigurable('form', FALSE);

    $fields['submission_ref'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Webform submission'))
      ->setDescription(new TranslatableMarkup('The submission this case was opened from. The raw CPR stays encrypted on the submission and is never copied here.'))
      ->setSetting('target_type', 'webform_submission')
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['kle_emne'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('KLE-emne (UUID)'))
      ->setDescription(new TranslatableMarkup('Classification (SF1510 Klassifikation) UUID used for journalising and SF2900 routing.'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 64)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['handlekommune'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Handling municipality'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 64)
      ->setDisplayConfigurable('form', TRUE);

    $fields['betalingskommune'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Paying municipality'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 64)
      ->setDisplayConfigurable('form', TRUE);

    $fields['modtagelsesdato'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Receipt date'))
      ->setDescription(new TranslatableMarkup('When the case was received - the start of the RSL §3 deadline clock.'))
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['frist_due'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Deadline (frist) due'))
      ->setDescription(new TranslatableMarkup('Computed by the FristClock from the receipt date and the per-area deadline.'))
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['partshoering_state'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Partshøring state'))
      ->setRevisionable(TRUE)
      ->setDefaultValue('ikke_paakraevet')
      ->setSetting('allowed_values', [
        'ikke_paakraevet' => 'Ikke påkrævet',
        'afventer' => 'Afventer svar',
        'afsluttet' => 'Afsluttet',
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['afgoerelse_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Decision type'))
      ->setRevisionable(TRUE)
      ->setSetting('allowed_values', [
        'medhold' => 'Medhold',
        'delvist' => 'Delvist medhold',
        'afslag' => 'Afslag',
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['klagefrist'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Appeal deadline (klagefrist)'))
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('view', TRUE);

    $fields['afgoerelse_dato'] = static::afgoerelseDatoFieldDefinition();

    $fields['delivery_channel'] = static::deliveryChannelFieldDefinition();

    $fields['subject_cpr_hash'] = static::subjectCprHashFieldDefinition();

    $fields['journal_ref'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Journal reference'))
      ->setDescription(new TranslatableMarkup('Reference returned by the Sags- og Dokumentindeks (SF1470) registration.'))
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 128)
      ->setDisplayConfigurable('view', TRUE);

    $fields['assigned_to'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Assigned caseworker'))
      ->setSetting('target_type', 'user')
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDisplayConfigurable('view', TRUE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    // Revision log fields (revision_user, revision_created, revision_log_message).
    $fields += static::revisionLogBaseFieldDefinitions($entity_type);

    return $fields;
  }

  /**
   * The decision-date field, shared by baseFieldDefinitions() and updates.
   *
   * Stamped by aabenforms_case_decide when the afgoerelse is recorded. The
   * klagefrist still runs from notification (SetKlagefristAction), this is
   * the date of the decision itself, shown to the citizen when they pick a
   * case to appeal.
   */
  public static function afgoerelseDatoFieldDefinition(): BaseFieldDefinition {
    return BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Decision date (afgørelsesdato)'))
      ->setDescription(new TranslatableMarkup('When the decision was recorded on the case.'))
      ->setRevisionable(TRUE)
      ->setDisplayConfigurable('view', TRUE);
  }

  /**
   * The delivery-channel field, shared by baseFieldDefinitions() and updates.
   *
   * How the decision letter actually went out: digital (Digital Post),
   * physical (printed letter, the citizen is fritaget for Digital Post) or
   * digital_and_physical (registration status unknown, both sent). Written by
   * the Digital Post send action; read by aabenforms_case_set_klagefrist,
   * because a physical letter has a later assumed receipt date. Revisionable,
   * so Sagsspor shows when it was recorded.
   */
  public static function deliveryChannelFieldDefinition(): BaseFieldDefinition {
    return BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Delivery channel'))
      ->setDescription(new TranslatableMarkup('How the decision letter was sent: digital, physical letter, or both.'))
      ->setRevisionable(TRUE)
      ->setSetting('allowed_values', [
        'digital' => 'Digital Post',
        'physical' => 'Fysisk brev',
        'digital_and_physical' => 'Digital Post og fysisk brev',
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', FALSE);
  }

  /**
   * The subject-CPR-hash field, shared by baseFieldDefinitions() and updates.
   *
   * Privacy rationale: a citizen must be able to find "my cases" (the klage
   * form offers the decided cases to appeal), but the case entity never
   * stores the CPR (it stays AES-encrypted on the submission, which cannot
   * be queried in SQL). So the case carries only a SHA-256 of the bare
   * 10-digit CPR, the same function the audit log applies, used purely as a
   * lookup key: the hash is never shown, never decrypted, and cannot be
   * turned back into the CPR by the platform. Note the honest limit: the
   * CPR space is small enough that an attacker holding the hash column
   * could enumerate it offline, so the column is personal data under GDPR
   * and is protected like the rest of the table (not like a public value).
   * A keyed HMAC would close that gap but would also break parity with the
   * existing audit-log hashes; revisit together when the audit log moves to
   * a keyed hash.
   *
   * Not revisionable: the subject of a case never changes, and a per-revision
   * copy would only multiply the hashed identifier at rest.
   */
  public static function subjectCprHashFieldDefinition(): BaseFieldDefinition {
    return BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Subject CPR hash'))
      ->setDescription(new TranslatableMarkup('SHA-256 of the applicant CPR, lookup key only. The raw CPR stays encrypted on the submission.'))
      ->setSetting('max_length', 64)
      ->setRevisionable(FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayConfigurable('form', FALSE);
  }

  /**
   * The allowed case lifecycle states.
   *
   * @return array<string, string>
   *   Machine name => human label.
   */
  public static function statusOptions(): array {
    return [
      'modtaget' => 'Modtaget',
      'oplyst' => 'Oplyst',
      'partshoering' => 'Partshøring',
      'afgoerelse' => 'Afgørelse',
      'paaklaget' => 'Påklaget',
      'lukket' => 'Lukket',
    ];
  }

  /**
   * The lawful forward transitions allowed from each state.
   *
   * @return array<string, string[]>
   *   Source state => list of allowed target states.
   */
  public static function allowedTransitions(): array {
    return [
      'modtaget' => ['oplyst', 'lukket'],
      'oplyst' => ['partshoering', 'afgoerelse', 'lukket'],
      'partshoering' => ['afgoerelse', 'lukket'],
      'afgoerelse' => ['paaklaget', 'lukket'],
      'paaklaget' => ['afgoerelse', 'lukket'],
      'lukket' => [],
    ];
  }

}
