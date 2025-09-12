<?php

namespace Drupal\silfi_sync_profile\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\silfi_sync_profile\Service\ProfileSyncService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event subscriber for handling profile sync on specific routes.
 */
class RouteSubscriber implements EventSubscriberInterface {

  /**
   * The profile sync service.
   *
   * @var \Drupal\silfi_sync_profile\Service\ProfileSyncService
   */
  protected $syncService;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructor.
   *
   * @param \Drupal\silfi_sync_profile\Service\ProfileSyncService $sync_service
   *   The profile sync service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    ProfileSyncService $sync_service,
    AccountInterface $current_user,
    LoggerChannelInterface $logger
  ) {
    $this->syncService = $sync_service;
    $this->currentUser = $current_user;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['onRequest', 100],
    ];
  }

  /**
   * Handle the request event.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    // Only process the main request
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $path = $request->getPathInfo();

    // Check if we're on the target path
    if ($path !== '/servizi/prenotazione-appuntamenti/new') {
      return;
    }

    // Check if user is authenticated
    if ($this->currentUser->isAnonymous()) {
      $this->logger->debug('User not authenticated on sync path, skipping profile sync');
      return;
    }

    $user_id = $this->currentUser->id();

    $this->logger->info('Triggering profile sync for user @user_id on path @path', [
      '@user_id' => $user_id,
      '@path' => $path,
    ]);

    // Perform the sync
    $sync_result = $this->syncService->performSync($user_id);

    if ($sync_result) {
      $this->logger->info('Profile sync completed successfully for user @user_id', [
        '@user_id' => $user_id,
      ]);
    }
    else {
      $this->logger->warning('Profile sync failed for user @user_id', [
        '@user_id' => $user_id,
      ]);
    }
  }

}
