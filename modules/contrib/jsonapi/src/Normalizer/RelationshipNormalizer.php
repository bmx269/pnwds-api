<?php

namespace Drupal\jsonapi\Normalizer;

use Drupal\Core\Entity\EntityInterface;
use Drupal\jsonapi\Normalizer\Value\RelationshipNormalizerValue;
use Drupal\jsonapi\ResourceType\ResourceTypeRepository;
use Drupal\jsonapi\LinkManager\LinkManager;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;

/**
 * Normalizer class for relationship elements. A relationship can be anything
 * that points to an entity in a JSON API resource.
 */
class RelationshipNormalizer extends NormalizerBase {

  /**
   * The interface or class that this Normalizer supports.
   *
   * @var string
   */
  protected $supportedInterfaceOrClass = Relationship::class;

  /**
   * The formats that the Normalizer can handle.
   *
   * @var array
   */
  protected $formats = array('api_json');

  /**
   * The link manager.
   *
   * @var \Drupal\jsonapi\LinkManager\LinkManager
   */
  protected $linkManager;

  /**
   * RelationshipNormalizer constructor.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceTypeRepository $resource_type_repository
   *   The JSON API resource type repository.
   * @param \Drupal\jsonapi\LinkManager\LinkManager $link_manager
   *   The link manager.
   */
  public function __construct(ResourceTypeRepository $resource_type_repository, LinkManager $link_manager) {
    $this->resourceTypeRepository = $resource_type_repository;
    $this->linkManager = $link_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = array()) {
    throw new UnexpectedValueException('Denormalization not implemented for JSON API');
  }

  /**
   * Helper function to normalize field items.
   *
   * @param \Drupal\jsonapi\Normalizer\Relationship $relationship
   *   The field object.
   * @param string $format
   *   The format.
   * @param array $context
   *   The context array.
   *
   * @return \Drupal\jsonapi\Normalizer\Value\RelationshipNormalizerValue
   *   The array of normalized field items.
   */
  public function normalize($relationship, $format = NULL, array $context = array()) {
    /* @var \Drupal\jsonapi\Normalizer\Relationship $relationship */
    $normalizer_items = array();
    foreach ($relationship->getItems() as $relationship_item) {
      $normalizer_items[] = $this->serializer->normalize($relationship_item, $format, $context);
    }
    $cardinality = $relationship->getCardinality();
    $link_context = [
      'host_entity_id' => $relationship->getHostEntity()->uuid(),
      'field_name' => $relationship->getPropertyName(),
      'link_manager' => $this->linkManager,
      'resource_type' => $context['resource_type'],
    ];
    return new RelationshipNormalizerValue($normalizer_items, $cardinality, $link_context);
  }

  /**
   * Builds the sub-context for the relationship include.
   *
   * @param array $context
   *   The serialization context.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The related entity.
   * @param string $host_field_name
   *   The name of the field reference.
   *
   * @return array
   *   The modified new context.
   *
   * @see EntityReferenceItemNormalizer::buildSubContext()
   * @todo This is duplicated code from the reference item. Reuse code instead.
   */
  protected function buildSubContext($context, EntityInterface $entity, $host_field_name) {
    // Swap out the context for the context of the referenced resource.
    $context['resource_type'] = $this->resourceTypeRepository
      ->get($entity->getEntityTypeId(), $entity->bundle());
    // Since we're going one level down the only includes we need are the ones
    // that apply to this level as well.
    $include_candidates = array_filter($context['include'], function ($include) use ($host_field_name) {
      return strpos($include, $host_field_name . '.') === 0;
    });
    $context['include'] = array_map(function ($include) use ($host_field_name) {
      return str_replace($host_field_name . '.', '', $include);
    }, $include_candidates);
    return $context;
  }

}
