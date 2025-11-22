<?php

namespace Drupal\ai_report_storage\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the AI Report entity.
 *
 * @ContentEntityType(
 *   id = "ai_report",
 *   label = @Translation("AI Report"),
 *   label_collection = @Translation("AI Reports"),
 *   label_singular = @Translation("AI report"),
 *   label_plural = @Translation("AI reports"),
 *   label_count = @PluralTranslation(
 *     singular = "@count AI report",
 *     plural = "@count AI reports"
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "access" = "Drupal\ai_report_storage\AiReportAccessControlHandler",
 *     "list_builder" = "Drupal\ai_report_storage\AiReportListBuilder",
 *     "form" = {
 *       "delete" = "Drupal\ai_report_storage\Form\AiReportDeleteForm",
 *     },
 *   },
 *   base_table = "ai_report",
 *   admin_permission = "administer ai reports",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "owner" = "uid",
 *   },
 *   links = {
 *     "delete-form" = "/admin/reports/ai-reports/{ai_report}/delete",
 *   },
 * )
 */
class AiReport extends ContentEntityBase implements AiReportInterface {

  use EntityChangedTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function getType() {
    return $this->get('type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setType($type) {
    $this->set('type', $type);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getReportData() {
    $data = $this->get('report_data')->value;
    return $data ? json_decode($data, TRUE) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function setReportData(array $data) {
    $this->set('report_data', json_encode($data));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getVersion() {
    return (int) $this->get('version')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setVersion($version) {
    $this->set('version', $version);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus() {
    return $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setStatus($status) {
    $this->set('status', $status);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getGeneratedAt() {
    return (int) $this->get('generated_at')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setGeneratedAt($timestamp) {
    $this->set('generated_at', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getGenerationTime() {
    return (float) $this->get('generation_time')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setGenerationTime($time) {
    $this->set('generation_time', $time);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getModelUsed() {
    return $this->get('model_used')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setModelUsed($model) {
    $this->set('model_used', $model);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceDataHash() {
    return $this->get('source_data_hash')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setSourceDataHash($hash) {
    $this->set('source_data_hash', $hash);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceSubmissions() {
    $data = $this->get('source_submissions')->value;
    return $data ? json_decode($data, TRUE) : [];
  }

  /**
   * {@inheritdoc}
   */
  public function setSourceSubmissions(array $submissions) {
    $this->set('source_submissions', json_encode($submissions));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    // Add the owner field from the trait.
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Report Type'))
      ->setDescription(t('The type of AI report (e.g., role_impact, career_transitions).'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64);

    $fields['report_data'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Report Data'))
      ->setDescription(t('The complete report data in JSON format.'))
      ->setRequired(TRUE);

    $fields['version'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Version'))
      ->setDescription(t('The version number of this report.'))
      ->setRequired(TRUE)
      ->setDefaultValue(1);

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Status'))
      ->setDescription(t('The status of the report (published, archived, draft).'))
      ->setRequired(TRUE)
      ->setDefaultValue('published')
      ->setSetting('max_length', 32);

    $fields['generated_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('Generated at'))
      ->setDescription(t('The time when the report was generated.'))
      ->setRequired(TRUE);

    $fields['generation_time'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('Generation Time'))
      ->setDescription(t('The time taken to generate the report in seconds.'))
      ->setSetting('precision', 10)
      ->setSetting('scale', 3);

    $fields['model_used'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Model Used'))
      ->setDescription(t('The AI model used to generate the report.'))
      ->setSetting('max_length', 128);

    $fields['source_data_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Source Data Hash'))
      ->setDescription(t('MD5 hash of the source webform submission data.'))
      ->setSetting('max_length', 32);

    $fields['source_submissions'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Source Submissions'))
      ->setDescription(t('JSON array of webform submission IDs used to generate the report.'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'))
      ->setDescription(t('The time that the entity was last edited.'));

    return $fields;
  }

}
