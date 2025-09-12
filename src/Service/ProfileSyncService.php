<?php

namespace Drupal\silfi_sync_profile\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for synchronizing user profile data from OpenCity.
 */
class ProfileSyncService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * OpenCity API base URL.
   *
   * @var string
   */
  protected $apiBaseUrl = 'https://api.055055.it:8243/opencity/1.0';

  /**
   * Constructor.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    LoggerChannelInterface $logger,
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    RequestStack $request_stack
  ) {
    $this->httpClient = $http_client;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->requestStack = $request_stack;
  }

  /**
   * Check if user is authenticated.
   *
   * @return bool
   *   TRUE if user is authenticated, FALSE otherwise.
   */
  public function isUserAuthenticated(): bool {
    return !$this->currentUser->isAnonymous();
  }

  /**
   * Check if sync was already performed in the last 30 minutes.
   *
   * @param int $user_id
   *   The user ID.
   *
   * @return bool
   *   TRUE if sync was already performed recently, FALSE otherwise.
   */
  public function wasSyncPerformedRecently(int $user_id): bool {
    $last_sync_key = 'silfi_sync_profile.last_sync.' . $user_id;
    $last_sync = \Drupal::state()->get($last_sync_key);

    if (!$last_sync) {
      return FALSE;
    }

    // Check if last sync was within the last 30 minutes (1800 seconds)
    $threshold = time() - 1800;
    return $last_sync > $threshold;
  }

  /**
   * Mark sync as performed for the current time.
   *
   * @param int $user_id
   *   The user ID.
   */
  public function markSyncPerformed(int $user_id): void {
    $last_sync_key = 'silfi_sync_profile.last_sync.' . $user_id;
    \Drupal::state()->set($last_sync_key, time());
  }

  /**
   * Get WSO2 auth token from session.
   *
   * @return string|null
   *   The auth token or NULL if not available.
   */
  public function getWso2AuthToken(): ?string {
    $session = $this->requestStack->getCurrentRequest()->getSession();
    $wso2_session = $session->get('wso2_auth_session');

    if (!empty($wso2_session) && !empty($wso2_session['access_token'])) {
      return $wso2_session['access_token'];
    }

    return NULL;
  }

  /**
   * Get user's fiscal code.
   *
   * @param int $user_id
   *   The user ID.
   *
   * @return string|null
   *   The fiscal code or NULL if not available.
   */
  public function getUserFiscalCode(int $user_id): ?string {
    try {
      $user = $this->entityTypeManager->getStorage('user')->load($user_id);

      if ($user && $user->hasField('field_user_fiscalcode')) {
        $fiscal_code_value = $user->get('field_user_fiscalcode')->value;
        return !empty($fiscal_code_value) ? $fiscal_code_value : NULL;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error loading user or fiscal code: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Fetch user data from OpenCity service.
   *
   * @param string $fiscal_code
   *   The user's fiscal code.
   * @param string $auth_token
   *   The WSO2 auth token.
   *
   * @return array|null
   *   The user data from OpenCity or NULL on failure.
   */
  public function fetchUserDataFromOpenCity(string $fiscal_code, string $auth_token): ?array {
    $url = $this->apiBaseUrl . '/utente/' . urlencode($fiscal_code);

    $options = [
      'headers' => [
        'accept' => 'application/json',
        'X-JWT-Assertion' => 'dsfdsf',
        'Authorization' => 'Bearer ' . $auth_token,
      ],
    ];

    // Skip SSL verification if configured in WSO2 auth
    $wso2_config = $this->configFactory->get('wso2_auth.settings');
    if ($wso2_config && $wso2_config->get('skip_ssl_verification')) {
      $options['verify'] = FALSE;
    }

    try {
      $this->logger->info('Calling OpenCity API for user with fiscal code: @fiscal_code', [
        '@fiscal_code' => $fiscal_code,
      ]);

      $response = $this->httpClient->get($url, $options);
      $data = json_decode((string) $response->getBody(), TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->error('Error decoding OpenCity response: @error', [
          '@error' => json_last_error_msg(),
        ]);
        return NULL;
      }

      // Check if the response indicates success
      if (isset($data['esito']) && $data['esito'] === 'SUCCESS') {
        $this->logger->info('Successfully fetched user data from OpenCity');
        return $data;
      }
      else {
        $this->logger->warning('OpenCity API returned non-success response: @data', [
          '@data' => print_r($data, TRUE),
        ]);
        return NULL;
      }
    }
    catch (RequestException $e) {
      $this->logger->error('Error calling OpenCity API: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Update user profile with data from OpenCity.
   *
   * @param int $user_id
   *   The user ID.
   * @param array $opencity_data
   *   The data from OpenCity service.
   *
   * @return bool
   *   TRUE if update was successful, FALSE otherwise.
   */
  public function updateUserProfile(int $user_id, array $opencity_data): bool {
    try {
      $user = $this->entityTypeManager->getStorage('user')->load($user_id);

      if (!$user) {
        $this->logger->error('User not found: @user_id', ['@user_id' => $user_id]);
        return FALSE;
      }

      $updated = FALSE;
      $persona_data = $opencity_data['personaOpencity'] ?? [];

      // Update mobile phone
      if (isset($persona_data['cellulare']) &&
          !empty($persona_data['cellulare']) &&
          $user->hasField('field_user_mobilephone')) {

        $current_mobile = $user->get('field_user_mobilephone')->value;
        $new_mobile = $persona_data['cellulare'];

        if ($current_mobile !== $new_mobile) {
          $user->set('field_user_mobilephone', $new_mobile);
          $updated = TRUE;
          $this->logger->info('Updated mobile phone for user @user_id: @old -> @new', [
            '@user_id' => $user_id,
            '@old' => $current_mobile ?: 'empty',
            '@new' => $new_mobile,
          ]);
        }
      }

      // Update email
      if (isset($persona_data['email']) &&
          !empty($persona_data['email']) &&
          $user->hasField('field_user_mail')) {

        $current_email = $user->get('field_user_mail')->value;
        $new_email = $persona_data['email'];

        if ($current_email !== $new_email) {
          $user->set('field_user_mail', $new_email);
          $updated = TRUE;
          $this->logger->info('Updated email for user @user_id: @old -> @new', [
            '@user_id' => $user_id,
            '@old' => $current_email ?: 'empty',
            '@new' => $new_email,
          ]);
        }
      }

      // Save the user if any updates were made
      if ($updated) {
        $user->save();
        $this->logger->info('Successfully updated user profile for user @user_id', [
          '@user_id' => $user_id,
        ]);
        return TRUE;
      }
      else {
        $this->logger->info('No profile updates needed for user @user_id', [
          '@user_id' => $user_id,
        ]);
        return TRUE;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error updating user profile: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Perform the complete sync process.
   *
   * @param int $user_id
   *   The user ID.
   *
   * @return bool
   *   TRUE if sync was successful or not needed, FALSE on error.
   */
  public function performSync(int $user_id): bool {
    // Check if user is authenticated
    if (!$this->isUserAuthenticated()) {
      $this->logger->debug('User not authenticated, skipping sync');
      return FALSE;
    }

    // Check if sync was already performed recently
    if ($this->wasSyncPerformedRecently($user_id)) {
      $this->logger->debug('Sync already performed recently for user @user_id, skipping', [
        '@user_id' => $user_id,
      ]);
      return TRUE;
    }

    // Get WSO2 auth token
    $auth_token = $this->getWso2AuthToken();
    if (!$auth_token) {
      $this->logger->warning('WSO2 auth token not available for user @user_id', [
        '@user_id' => $user_id,
      ]);
      return FALSE;
    }

    // Get user's fiscal code
    $fiscal_code = $this->getUserFiscalCode($user_id);
    if (!$fiscal_code) {
      $this->logger->warning('Fiscal code not available for user @user_id', [
        '@user_id' => $user_id,
      ]);
      return FALSE;
    }

    // Fetch data from OpenCity
    $opencity_data = $this->fetchUserDataFromOpenCity($fiscal_code, $auth_token);
    if (!$opencity_data) {
      $this->logger->warning('Failed to fetch data from OpenCity for user @user_id', [
        '@user_id' => $user_id,
      ]);
      return FALSE;
    }

    // Update user profile
    $update_success = $this->updateUserProfile($user_id, $opencity_data);

    // Mark sync as performed regardless of update success to avoid repeated attempts
    $this->markSyncPerformed($user_id);

    return $update_success;
  }

}
