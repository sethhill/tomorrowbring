<?php

namespace Drupal\webform_client_manager\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

/**
 * Form handler for the Client add and edit forms.
 */
class ClientForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\webform_client_manager\ClientInterface $client */
    $client = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client Name'),
      '#maxlength' => 255,
      '#default_value' => $client->label(),
      '#description' => $this->t('Name of the client organization.'),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $client->id(),
      '#machine_name' => [
        'exists' => '\Drupal\webform_client_manager\Entity\Client::load',
      ],
      '#disabled' => !$client->isNew(),
    ];

    // Get all Module nodes.
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'module')
      ->condition('status', 1)
      ->accessCheck(FALSE);
    $nids = $query->execute();

    $options = [];
    if (!empty($nids)) {
      $nodes = Node::loadMultiple($nids);
      foreach ($nodes as $nid => $node) {
        $module_number = '';
        if ($node->hasField('field_number') && !$node->get('field_number')->isEmpty()) {
          $module_number = $node->get('field_number')->value . ': ';
        }
        $options[$nid] = $module_number . $node->label();
      }
      // Sort by module number.
      asort($options);
    }

    $form['enabled_modules'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled Modules'),
      '#options' => $options,
      '#default_value' => $client->getEnabledModules(),
      '#description' => $this->t('Select which Module nodes this client can access. Modules will be presented in numerical order.'),
    ];

    $form['completion_redirect_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Completion Redirect URL'),
      '#default_value' => $client->getCompletionRedirectUrl(),
      '#description' => $this->t('URL to redirect users to after completing all enabled modules. Leave empty for default thank you page.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\webform_client_manager\ClientInterface $client */
    $client = $this->entity;

    // Filter out unchecked modules
    $enabled_modules = array_filter($form_state->getValue('enabled_modules'));
    $client->setEnabledModules(array_values($enabled_modules));

    $status = $client->save();

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Created the %label Client.', [
        '%label' => $client->label(),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Saved the %label Client.', [
        '%label' => $client->label(),
      ]));
    }

    $form_state->setRedirectUrl($client->toUrl('collection'));
  }

}
