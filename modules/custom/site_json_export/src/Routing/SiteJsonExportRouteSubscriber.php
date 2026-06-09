<?php

namespace Drupal\site_json_export\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Updates export route path from module configuration.
 */
class SiteJsonExportRouteSubscriber extends RouteSubscriberBase {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Creates a route subscriber instance.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection) {
    $route = $collection->get('site_json_export.export');
    if (!$route) {
      return;
    }

    $config = $this->configFactory->get('site_json_export.settings');
    $path = trim((string) ($config->get('endpoint_path') ?: '/site-json-export'));

    if ($path === '') {
      $path = '/site-json-export';
    }

    if ($path[0] !== '/') {
      $path = '/' . $path;
    }

    $route->setPath($path);
  }

}
