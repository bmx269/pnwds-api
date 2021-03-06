<?php


namespace Drupal\simple_oauth_extras\Plugin\Oauth2Grant;

use Drupal\simple_oauth\Plugin\Oauth2GrantBase;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @Oauth2Grant(
 *   id = "client_credentials",
 *   label = @Translation("Client Credentials")
 * )
 */
class ClientCredentials extends Oauth2GrantBase {

  /**
   * @var \League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface
   */
  protected $refreshTokenRepository;

  /**
   * Class constructor.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RefreshTokenRepositoryInterface $refresh_token_repository) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->refreshTokenRepository = $refresh_token_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('simple_oauth.repositories.refresh_token')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getGrantType() {
    return new ClientCredentialsGrant();
  }

}
