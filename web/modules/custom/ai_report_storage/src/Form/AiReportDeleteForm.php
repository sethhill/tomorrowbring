<?php

namespace Drupal\ai_report_storage\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides a form for deleting AI Report entities.
 */
class AiReportDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    /** @var \Drupal\ai_report_storage\Entity\AiReportInterface $entity */
    $entity = $this->getEntity();
    return $this->t('Are you sure you want to delete the %type report (version %version)?', [
      '%type' => $entity->getType(),
      '%version' => $entity->getVersion(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('ai_report_storage.admin_overview');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\ai_report_storage\Entity\AiReportInterface $entity */
    $entity = $this->getEntity();
    $entity->delete();

    $this->messenger()->addStatus($this->t('The report has been deleted.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
