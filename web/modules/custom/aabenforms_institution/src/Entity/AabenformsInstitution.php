<?php

declare(strict_types=1);

namespace Drupal\aabenforms_institution\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the ÅbenForms Institution registry entity.
 *
 * One row per education institution, keyed by the sector-wide 6-digit
 * institutionsnummer from Institutionsregisteret (the join key used by
 * Unilogin, STIL webservices, Aula and the student administration systems).
 * The registry powers school pickers, submission routing to the right
 * school leader, and district logic in flows.
 *
 * Deliberate boundary: this entity models INSTITUTIONS only. Pupils,
 * classes, groups and year rollover live in STIL SkoleGrunddata (or
 * OS2skoledata where a municipality runs it) and are not mirrored here.
 */
#[ContentEntityType(
  id: 'aabenforms_institution',
  label: new TranslatableMarkup('Institution'),
  label_collection: new TranslatableMarkup('Institutions'),
  label_singular: new TranslatableMarkup('institution'),
  label_plural: new TranslatableMarkup('institutions'),
  label_count: [
    'singular' => '@count institution',
    'plural' => '@count institutions',
  ],
  handlers: [
    'form' => [
      'default' => ContentEntityForm::class,
      'edit' => ContentEntityForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  base_table: 'aabenforms_institution',
  admin_permission: 'administer aabenforms_institution',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'name',
  ],
  links: [
    'canonical' => '/admin/aabenforms/institutions/{aabenforms_institution}',
    'add-form' => '/admin/aabenforms/institutions/add',
    'edit-form' => '/admin/aabenforms/institutions/{aabenforms_institution}/edit',
    'delete-form' => '/admin/aabenforms/institutions/{aabenforms_institution}/delete',
  ],
)]
class AabenformsInstitution extends ContentEntityBase {

  use EntityChangedTrait;

  /**
   * Institution type: folkeskole and other schools.
   */
  public const TYPE_SKOLE = 'skole';

  /**
   * Institution type: daycare (dagtilbud).
   */
  public const TYPE_DAGTILBUD = 'dagtilbud';

  /**
   * Institution type: leisure/youth (fritids- og ungdomstilbud).
   */
  public const TYPE_FU = 'fu';

  /**
   * Institution type: municipal administrative unit (e.g. PPR, forvaltning).
   */
  public const TYPE_FORVALTNING = 'forvaltning';

  /**
   * Returns the 6-digit institutionsnummer.
   */
  public function getInstitutionNumber(): string {
    return (string) $this->get('institution_number')->value;
  }

  /**
   * Returns the institution type (one of the TYPE_* constants).
   */
  public function getType(): string {
    return (string) $this->get('type')->value;
  }

  /**
   * Returns the school district name, or ''.
   */
  public function getDistrict(): string {
    return (string) $this->get('district')->value;
  }

  /**
   * Returns the leader's (skoleleder) email, or ''.
   */
  public function getLeaderEmail(): string {
    return (string) $this->get('leader_email')->value;
  }

  /**
   * Returns the leader's display name, or ''.
   */
  public function getLeaderName(): string {
    return (string) $this->get('leader_name')->value;
  }

  /**
   * Whether the institution is active (not closed).
   */
  public function isActive(): bool {
    return (bool) $this->get('active')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Name'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 0])
      ->setDisplayConfigurable('view', TRUE);

    $fields['institution_number'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Institution number'))
      ->setDescription(new TranslatableMarkup('The 6-digit institutionsnummer from Institutionsregisteret.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 6)
      ->addConstraint('UniqueField')
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 1])
      ->setDisplayConfigurable('view', TRUE);

    $fields['type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Type'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::TYPE_SKOLE)
      ->setSetting('allowed_values', [
        self::TYPE_SKOLE => new TranslatableMarkup('School'),
        self::TYPE_DAGTILBUD => new TranslatableMarkup('Daycare'),
        self::TYPE_FU => new TranslatableMarkup('Leisure and youth'),
        self::TYPE_FORVALTNING => new TranslatableMarkup('Administrative unit'),
      ])
      ->setDisplayOptions('form', ['type' => 'options_select', 'weight' => 2]);

    $fields['district'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('District'))
      ->setDescription(new TranslatableMarkup('School district (skoledistrikt) the institution belongs to.'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 3]);

    $fields['leader_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Leader name'))
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', ['type' => 'string_textfield', 'weight' => 4]);

    $fields['leader_email'] = BaseFieldDefinition::create('email')
      ->setLabel(new TranslatableMarkup('Leader email'))
      ->setDescription(new TranslatableMarkup('Review tasks for this institution are routed here.'))
      ->setDisplayOptions('form', ['type' => 'email_default', 'weight' => 5]);

    $fields['parent_institution'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Parent unit'))
      ->setDescription(new TranslatableMarkup('The administrative unit this institution reports to (e.g. the school department), forming the routing hierarchy.'))
      ->setSetting('target_type', 'aabenforms_institution')
      ->setDisplayOptions('form', ['type' => 'entity_reference_autocomplete', 'weight' => 6]);

    $fields['active'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Active'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', ['type' => 'boolean_checkbox', 'weight' => 7]);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

}
