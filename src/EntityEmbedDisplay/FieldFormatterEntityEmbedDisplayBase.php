<?php

/**
 * @file
 * Contains \Drupal\entity_embed\EntityEmbedDisplay\FieldFormatterEntityEmbedDisplayBase.
 */

namespace Drupal\entity_embed\EntityEmbedDisplay;

use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FormatterPluginManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\TypedDataManager;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class FieldFormatterEntityEmbedDisplayBase extends EntityEmbedDisplayBase {

  /**
   * The field formatter plugin manager.
   *
   * @var \Drupal\Core\Field\FormatterPluginManager
   */
  protected $formatterPluginManager;

  /**
   * The typed data manager.
   *
   * @var \Drupal\Core\TypedData\TypedDataManager
   */
  protected $typedDataManager;

  /**
   * Constructs a FieldFormatterEntityEmbedDisplayBase object.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager service.
   * @param \Drupal\Core\Field\FormatterPluginManager $formatter_plugin_manager
   *   The field formatter plugin manager.
   * @param \Drupal\Core\TypedData\TypedDataManager $typed_data_manager
   *   The typed data manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityManagerInterface $entity_manager, FormatterPluginManager $formatter_plugin_manager, TypedDataManager $typed_data_manager) {
    $this->formatterPluginManager = $formatter_plugin_manager;
    $this->setConfiguration($configuration);
    $this->setEntityManager($entity_manager);
    $this->typedDataManager = $typed_data_manager;
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity.manager'),
      $container->get('plugin.manager.field.formatter'),
      $container->get('typed_data_manager')
    );
  }

  /**
   * Get the FieldDefinition object required to render this field's formatter.
   *
   * @return \Drupal\Core\Field\BaseFieldDefinition
   *   The field definition.
   *
   * @see \Drupal\entity_embed\FieldFormatterEntityEmbedDisplayBase::build()
   */
  abstract public function getFieldDefinition();

  /**
   * Get the field value required to pass into the field formatter.
   *
   * @param \Drupal\Core\Field\BaseFieldDefinition $definition
   *   The field definition.
   *
   * @return mixed
   *   The field value.
   */
  abstract public function getFieldValue(BaseFieldDefinition $definition);

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account = NULL) {
    if (!parent::access($account)) {
      return FALSE;
    }

    $definition = $this->formatterPluginManager->getDefinition($this->getDerivativeId());
    return $definition['class']::isApplicable($this->getFieldDefinition());
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Create a temporary node object to which our fake field value can be
    // added.
    $node = Node::create(array('type' => '_entity_embed'));

    // Create the field definition, some might need more settings, it currently
    // doesn't load in the field type defaults. https://drupal.org/node/2116341
    $definition = $this->getUniqueFieldDefinition();

    /* @var \Drupal\Core\Field\FieldItemListInterface $items $items */
    // Create a field item list object, 1 is the value, array('target_id' => 1)
    // would work too, or multiple values. 1 is passed down from the list to the
    // field item, which knows that an integer is the ID.
    $items = $this->typedDataManager->create(
      $definition,
      $this->getFieldValue($definition),
      $definition->getName(),
      $node->getTypedData()
    );

    if ($langcode = $this->getAttributeValue('data-langcode')) {
      $items->setLangcode($langcode);
    }

    $formatter = $this->getFormatter($definition);
    // Prepare, expects an array of items, keyed by parent entity ID.
    $formatter->prepareView(array($node->id() => $items));
    $build = $formatter->viewElements($items);
    // For some reason $build[0]['#printed'] is TRUE, which means it will fail
    // to render later. So for now we manually fix that.
    // @todo Investigate why this is needed.
    show($build[0]);
    return $build[0];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return $this->formatterPluginManager->getDefaultSettings($this->getDerivativeId());
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    return $this->getFormatter()->settingsForm($form, $form_state);
  }

  /**
   * Constructs a \Drupal\Core\Field\FormatterInterface object.
   *
   * @param \Drupal\Core\Field\BaseFieldDefinition $definition
   *   The field definition.
   *
   * @return \Drupal\Core\Field\FormatterInterface
   *   The formatter object.
   */
  protected function getFormatter(BaseFieldDefinition $definition = NULL) {
    if (!isset($definition)) {
      $definition = $this->getUniqueFieldDefinition();
    }

    $display = array(
      'type' => $this->getDerivativeId(),
      'settings' => $this->getConfiguration(),
      'label' => 'hidden',
    );

    /* @var \Drupal\Core\Field\FormatterInterface $formatter */
    // Create the formatter plugin. Will use the default formatter for that
    // field type if none is passed.
    return $this->formatterPluginManager->getInstance(array(
      'field_definition' => $definition,
      'view_mode' => '_entity_embed',
      'configuration' => $display,
    ));
  }

  /**
   * @return \Drupal\Core\Field\BaseFieldDefinition $definition
   */
  public function getUniqueFieldDefinition() {
    $definition = $this->getFieldDefinition();
    static $index = 0;
    $definition->setName('_entity_embed_' . $index++);
    return $definition;
  }
}
