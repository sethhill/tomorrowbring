<?php

namespace Drupal\ai_report_storage\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Provides an interface for AI Report entities.
 */
interface AiReportInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

  /**
   * Gets the report type.
   *
   * @return string
   *   The report type (e.g., 'role_impact', 'career_transitions').
   */
  public function getType();

  /**
   * Sets the report type.
   *
   * @param string $type
   *   The report type.
   *
   * @return $this
   */
  public function setType($type);

  /**
   * Gets the report data.
   *
   * @return array
   *   The report data as an associative array.
   */
  public function getReportData();

  /**
   * Sets the report data.
   *
   * @param array $data
   *   The report data.
   *
   * @return $this
   */
  public function setReportData(array $data);

  /**
   * Gets the version number.
   *
   * @return int
   *   The version number.
   */
  public function getVersion();

  /**
   * Sets the version number.
   *
   * @param int $version
   *   The version number.
   *
   * @return $this
   */
  public function setVersion($version);

  /**
   * Gets the report status.
   *
   * @return string
   *   The status (published, archived, draft).
   */
  public function getStatus();

  /**
   * Sets the report status.
   *
   * @param string $status
   *   The status.
   *
   * @return $this
   */
  public function setStatus($status);

  /**
   * Gets the generation timestamp.
   *
   * @return int
   *   The Unix timestamp when the report was generated.
   */
  public function getGeneratedAt();

  /**
   * Sets the generation timestamp.
   *
   * @param int $timestamp
   *   The Unix timestamp.
   *
   * @return $this
   */
  public function setGeneratedAt($timestamp);

  /**
   * Gets the generation time in seconds.
   *
   * @return float
   *   The time taken to generate the report.
   */
  public function getGenerationTime();

  /**
   * Sets the generation time.
   *
   * @param float $time
   *   The generation time in seconds.
   *
   * @return $this
   */
  public function setGenerationTime($time);

  /**
   * Gets the AI model used.
   *
   * @return string
   *   The model identifier.
   */
  public function getModelUsed();

  /**
   * Sets the AI model used.
   *
   * @param string $model
   *   The model identifier.
   *
   * @return $this
   */
  public function setModelUsed($model);

  /**
   * Gets the source data hash.
   *
   * @return string
   *   The MD5 hash of source webform submissions.
   */
  public function getSourceDataHash();

  /**
   * Sets the source data hash.
   *
   * @param string $hash
   *   The MD5 hash.
   *
   * @return $this
   */
  public function setSourceDataHash($hash);

  /**
   * Gets the source submissions.
   *
   * @return array
   *   Array of webform submission IDs used to generate the report.
   */
  public function getSourceSubmissions();

  /**
   * Sets the source submissions.
   *
   * @param array $submissions
   *   Array of webform submission IDs.
   *
   * @return $this
   */
  public function setSourceSubmissions(array $submissions);

  /**
   * Gets the viewed timestamp.
   *
   * @return int|null
   *   The Unix timestamp when the report was first viewed, or NULL if not viewed.
   */
  public function getViewedAt();

  /**
   * Sets the viewed timestamp.
   *
   * @param int $timestamp
   *   The Unix timestamp.
   *
   * @return $this
   */
  public function setViewedAt($timestamp);

  /**
   * Checks if the report has been viewed.
   *
   * @return bool
   *   TRUE if the report has been viewed, FALSE otherwise.
   */
  public function isViewed();

}
