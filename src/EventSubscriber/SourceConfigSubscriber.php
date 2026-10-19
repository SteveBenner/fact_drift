<?php

namespace Drupal\fact_drift\EventSubscriber;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\fact_drift\Scanner;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Drops the cached facts when a source's configuration changes.
 */
class SourceConfigSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a SourceConfigSubscriber.
   *
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $invalidator
   *   The cache tags invalidator.
   */
  public function __construct(protected CacheTagsInvalidatorInterface $invalidator) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => 'onSourceChange',
      ConfigEvents::DELETE => 'onSourceChange',
    ];
  }

  /**
   * Invalidates the cached facts when a source is saved or deleted.
   *
   * @param \Drupal\Core\Config\ConfigCrudEvent $event
   *   The configuration event.
   */
  public function onSourceChange(ConfigCrudEvent $event): void {
    if (str_starts_with($event->getConfig()->getName(), 'fact_drift.source.')) {
      $this->invalidator->invalidateTags([Scanner::FACTS_TAG]);
    }
  }

}
