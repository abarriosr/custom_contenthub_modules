<?php

namespace Drupal\acquia_contenthub_ignore_config\EventSubscriber\DataTamper;

use Drupal\acquia_contenthub\AcquiaContentHubEvents;
use Drupal\acquia_contenthub\Event\EntityDataTamperEvent;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\depcalc\DependentEntityWrapper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Class LoadFromConfigEntity.
 *
 * Prevent Local Config save for a particular entity type.
 *
 * @package Drupal\acquia_contenthub_subscriber\EventSubscriber\LoadFromConfigEntity
 */
class LoadFromConfigEntity implements EventSubscriberInterface {

  /**
   * Configuration Entity Types that will be ignored from importing.
   *
   * @var array
   */
  protected $ignored_config_entity_types = [
    'entity_view_display',
  ];

  /**
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * LoadFromConfigEntity constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   *   The Entity Type Manager.
   */
  public function __construct(EntityTypeManager $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      AcquiaContentHubEvents::ENTITY_DATA_TAMPER => ['onDataTamper', 1001],
    ];
  }

  /**
   * Load previously imported entities from the tracking table data.
   *
   * @param \Drupal\acquia_contenthub\Event\EntityDataTamperEvent $event
   *   OnDataTamper event.
   *
   * @throws \Exception
   */
  public function onDataTamper(EntityDataTamperEvent $event) {
    $cdf = $event->getCdf();
    foreach ($cdf->getEntities() as $object) {
      if ($object->getType() === "drupal8_config_entity") {
        $entity_type_id = $object->getAttribute('entity_type')->getValue()['und'];

        // If this is not the config type, then keep moving along.
        if (!in_array($entity_type_id, $this->ignored_config_entity_types)) {
          continue;
        }

        $data = Yaml::parse(base64_decode($object->getMetadata()['data']));

        // Hard-coding the language to 'en'.
        $entity = \Drupal::entityTypeManager()->getStorage($entity_type_id)->load($data['en']['id']);

        if ($entity) {
          // Add existing entities to the stack, so that the config from the
          // CDF does not overwrite the existing config.
          $wrapper = new DependentEntityWrapper($entity);
          $wrapper->setRemoteUuid($object->getUuid());
          $event->getStack()->addDependency($wrapper);

          // Log ignored entity.
          $message = sprintf("Ignored Entity: (%s, %s).", $entity->getEntityTypeId(), $entity->id());
          \Drupal::logger('acquia_contenthub_ignore_config')->info($message);
        }
      }
    }
  }
}
