<?php

namespace Drupal\jsonapi\Normalizer;

use Drupal\jsonapi\Exception\EntityAccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Normalizes an EntityAccessDeniedException object for JSON output which
 * complies with the JSON API specification. A source pointer is added to help
 * client applications report to know which entity was access denied.
 *
 * @see http://jsonapi.org/format/#error-objects
 */
class EntityAccessDeniedHttpExceptionNormalizer extends HttpExceptionNormalizer {

  /**
   * {@inheritdoc}
   */
  protected $supportedInterfaceOrClass = EntityAccessDeniedHttpException::class;

  /**
   * {@inheritdoc}
   */
  protected function buildErrorObjects(HttpException $exception) {
    $errors = parent::buildErrorObjects($exception);

    if ($exception instanceof EntityAccessDeniedHttpException) {
      $error = $exception->getError();
      /** @var \Drupal\Core\Entity\EntityInterface $entity */
      $entity = $error['entity'];
      $pointer = $error['pointer'];
      $reason = $error['reason'];

      $errors[0]['id'] = sprintf(
        '/%s--%s/%s',
        $entity->getEntityTypeId(),
        $entity->bundle(),
        $entity->uuid()
      );
      $errors[0]['source']['pointer'] = $pointer;

      if ($reason) {
        $errors[0]['detail']['message'] = $reason;
      }
    }

    return $errors;
  }

}
