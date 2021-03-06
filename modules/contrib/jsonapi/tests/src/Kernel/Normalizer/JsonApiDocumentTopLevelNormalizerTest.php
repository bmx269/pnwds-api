<?php

namespace Drupal\Tests\jsonapi\Kernel\Normalizer;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\LinkManager\LinkManager;
use Drupal\jsonapi\Normalizer\JsonApiDocumentTopLevelNormalizer;
use Drupal\jsonapi\Resource\JsonApiDocumentTopLevel;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\jsonapi\ResourceResponse;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\jsonapi\Kernel\JsonapiKernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use Prophecy\Argument;
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Route;

/**
 * @coversDefaultClass \Drupal\jsonapi\Normalizer\JsonApiDocumentTopLevelNormalizer
 * @group jsonapi
 */
class JsonApiDocumentTopLevelNormalizerTest extends JsonapiKernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'jsonapi',
    'field',
    'node',
    'serialization',
    'system',
    'taxonomy',
    'text',
    'user',
  ];

  /**
   * A node to normalize.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $node;

  /**
   * A user to normalize.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    // Add the entity schemas.
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    // Add the additional table schemas.
    $this->installSchema('system', ['sequences']);
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $type = NodeType::create([
      'type' => 'article',
    ]);
    $type->save();
    $this->createEntityReferenceField(
      'node',
      'article',
      'field_tags',
      'Tags',
      'taxonomy_term',
      'default',
      ['target_bundles' => ['tags']],
      FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED
    );
    $this->user = User::create([
      'name' => 'user1',
      'mail' => 'user@localhost',
    ]);
    $this->user2 = User::create([
      'name' => 'user2',
      'mail' => 'user2@localhost',
    ]);

    $this->user->save();
    $this->user2->save();

    $this->vocabulary = Vocabulary::create(['name' => 'Tags', 'vid' => 'tags']);
    $this->vocabulary->save();

    $this->term1 = Term::create([
      'name' => 'term1',
      'vid' => $this->vocabulary->id(),
    ]);
    $this->term2 = Term::create([
      'name' => 'term2',
      'vid' => $this->vocabulary->id(),
    ]);

    $this->term1->save();
    $this->term2->save();

    $this->node = Node::create([
      'title' => 'dummy_title',
      'type' => 'article',
      'uid' => 1,
      'field_tags' => [
        ['target_id' => $this->term1->id()],
        ['target_id' => $this->term2->id()],
      ],
    ]);

    $this->node->save();

    $link_manager = $this->prophesize(LinkManager::class);
    $link_manager
      ->getEntityLink(Argument::any(), Argument::any(), Argument::type('array'), Argument::type('string'))
      ->willReturn('dummy_entity_link');
    $link_manager
      ->getRequestLink(Argument::any())
      ->willReturn('dummy_document_link');
    $this->container->set('jsonapi.link_manager', $link_manager->reveal());

    $this->nodeType = NodeType::load('article');

    Role::create([
      'id' => RoleInterface::ANONYMOUS_ID,
      'permissions' => [
        'access content',
      ],
    ])->save();
  }


  /**
   * {@inheritdoc}
   */
  public function tearDown() {
    if ($this->node) {
      $this->node->delete();
    }
    if ($this->term1) {
      $this->term1->delete();
    }
    if ($this->term2) {
      $this->term2->delete();
    }
    if ($this->vocabulary) {
      $this->vocabulary->delete();
    }
    if ($this->user) {
      $this->user->delete();
    }
    if ($this->user2) {
      $this->user2->delete();
    }
  }

  /**
   * @covers ::normalize
   */
  public function testNormalize() {
    list($request, $resource_type) = $this->generateProphecies('node', 'article');
    $request->query = new ParameterBag([
      'fields' => [
        'node--article' => 'title,type,uid,field_tags',
        'user--user' => 'name',
      ],
      'include' => 'uid,field_tags',
    ]);

    $response = new ResourceResponse();
    $normalized = $this
      ->container
      ->get('serializer.normalizer.jsonapi_document_toplevel.jsonapi')
      ->normalize(
        new JsonApiDocumentTopLevel($this->node),
        'api_json',
        [
          'request' => $request,
          'resource_type' => $resource_type,
          'cacheable_metadata' => $response->getCacheableMetadata(),
        ]
      );
    $this->assertSame($normalized['data']['attributes']['title'], 'dummy_title');
    $this->assertEquals($normalized['data']['id'], $this->node->uuid());
    $this->assertSame([
      'data' => [
        'type' => 'node_type--node_type',
        'id' => NodeType::load('article')->uuid(),
      ],
      'links' => [
        'self' => 'dummy_entity_link',
        'related' => 'dummy_entity_link',
      ],
    ], $normalized['data']['relationships']['type']);
    $this->assertTrue(!isset($normalized['data']['attributes']['created']));
    $this->assertSame('node--article', $normalized['data']['type']);
    $this->assertEquals([
      'data' => [
        'type' => 'user--user',
        'id' => $this->user->uuid(),
      ],
      'links' => [
        'self' => 'dummy_entity_link',
        'related' => 'dummy_entity_link',
      ],
    ], $normalized['data']['relationships']['uid']);
    $this->assertEquals(
      'The current user is not allowed to GET the selected resource.',
      $normalized['meta']['errors'][0]['detail']
    );
    $this->assertEquals(403, $normalized['meta']['errors'][0]['status']);
    $this->assertEquals($this->term1->uuid(), $normalized['included'][0]['id']);
    $this->assertEquals('taxonomy_term--tags', $normalized['included'][0]['type']);
    $this->assertEquals($this->term1->label(), $normalized['included'][0]['attributes']['name']);
    $this->assertTrue(!isset($normalized['included'][0]['attributes']['created']));
    // Make sure that the cache tags for the includes and the requested entities
    // are bubbling as expected.
    $this->assertSame(
      ['node:1', 'taxonomy_term:1', 'taxonomy_term:2'],
      $response->getCacheableMetadata()->getCacheTags()
    );
    $this->assertSame(
      Cache::PERMANENT,
      $response->getCacheableMetadata()->getCacheMaxAge()
    );
  }

  /**
   * @covers ::normalize
   */
  public function testNormalizeRelated() {
    list($request, $resource_type) = $this->generateProphecies('node', 'article', 'uid');
    $request->query = new ParameterBag([
      'fields' => [
        'user--user' => 'name,roles',
      ],
      'include' => 'roles'
    ]);
    $document_wrapper = $this->prophesize(JsonApiDocumentTopLevel::class);
    $author = $this->node->get('uid')->entity;
    $document_wrapper->getData()->willReturn($author);

    $response = new ResourceResponse();
    $normalized = $this
      ->container
      ->get('serializer.normalizer.jsonapi_document_toplevel.jsonapi')
      ->normalize(
        $document_wrapper->reveal(),
        'api_json',
        [
          'request' => $request,
          'resource_type' => $resource_type,
          'cacheable_metadata' => $response->getCacheableMetadata(),
        ]
      );
    $this->assertSame($normalized['data']['attributes']['name'], 'user1');
    $this->assertEquals($normalized['data']['id'], User::load(1)->uuid());
    $this->assertEquals($normalized['data']['type'], 'user--user');
    // Make sure that the cache tags for the includes and the requested entities
    // are bubbling as expected.
    $this->assertSame(['user:1'], $response->getCacheableMetadata()
      ->getCacheTags());
    $this->assertSame(Cache::PERMANENT, $response->getCacheableMetadata()
      ->getCacheMaxAge());
  }

  /**
   * @covers ::normalize
   */
  public function testNormalizeUuid() {
    list($request, $resource_type) = $this->generateProphecies('node', 'article', 'uuid');
    $document_wrapper = $this->prophesize(JsonApiDocumentTopLevel::class);
    $document_wrapper->getData()->willReturn($this->node);
    $request->query = new ParameterBag([
      'fields' => [
        'node--article' => 'title,type,uid,field_tags',
        'user--user' => 'name',
      ],
      'include' => 'uid,field_tags',
    ]);

    $response = new ResourceResponse();
    $normalized = $this
      ->container
      ->get('serializer.normalizer.jsonapi_document_toplevel.jsonapi')
      ->normalize(
        $document_wrapper->reveal(),
        'api_json',
        [
          'request' => $request,
          'resource_type' => $resource_type,
          'cacheable_metadata' => $response->getCacheableMetadata(),
        ]
      );
    $this->assertStringMatchesFormat($this->node->uuid(), $normalized['data']['id']);
    $this->assertEquals($this->node->type->entity->uuid(), $normalized['data']['relationships']['type']['data']['id']);
    $this->assertEquals($this->user->uuid(), $normalized['data']['relationships']['uid']['data']['id']);
    $this->assertFalse(empty($normalized['included'][0]['id']));
    $this->assertFalse(empty($normalized['meta']['errors']));
    $this->assertEquals($this->term1->uuid(), $normalized['included'][0]['id']);
    // Make sure that the cache tags for the includes and the requested entities
    // are bubbling as expected.
    $this->assertSame(
      ['node:1', 'taxonomy_term:1', 'taxonomy_term:2'],
      $response->getCacheableMetadata()->getCacheTags()
    );
  }

  /**
   * @covers ::normalize
   */
  public function testNormalizeException() {
    list($request, $resource_type) = $this->generateProphecies('node', 'article', 'id');
    $document_wrapper = $this->prophesize(JsonApiDocumentTopLevel::class);
    $document_wrapper->getData()->willReturn($this->node);
    $request->query = new ParameterBag([
      'fields' => [
        'node--article' => 'title,type,uid',
        'user--user' => 'name',
      ],
      'include' => 'uid'
    ]);

    $response = new ResourceResponse();
    $normalized = $this
      ->container
      ->get('serializer')
      ->serialize(
        new BadRequestHttpException('Lorem'),
        'api_json',
        [
          'request' => $request,
          'resource_type' => $resource_type,
          'cacheable_metadata' => $response->getCacheableMetadata(),
          'data_wrapper' => 'errors',
        ]
      );
    $normalized = Json::decode($normalized);
    $this->assertNotEmpty($normalized['errors']);
    $this->assertArrayNotHasKey('data', $normalized);
    $this->assertEquals(400, $normalized['errors'][0]['status']);
    $this->assertEquals('Lorem', $normalized['errors'][0]['detail']);
    $this->assertEquals(['info' => 'http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html#sec10.4.1'], $normalized['errors'][0]['links']);
  }

  /**
   * @covers ::normalize
   */
  public function testNormalizeConfig() {
    list($request, $resource_type) = $this->generateProphecies('node_type', 'node_type', 'id');
    $document_wrapper = $this->prophesize(JsonApiDocumentTopLevel::class);
    $document_wrapper->getData()->willReturn($this->nodeType);
    $request->query = new ParameterBag([
      'fields' => [
        'node_type--node_type' => 'uuid,display_submitted',
      ],
      'include' => NULL
    ]);

    $response = new ResourceResponse();
    $normalized = $this
      ->container
      ->get('serializer.normalizer.jsonapi_document_toplevel.jsonapi')
      ->normalize($document_wrapper->reveal(), 'api_json', [
        'request' => $request,
        'resource_type' => $resource_type,
        'cacheable_metadata' => $response->getCacheableMetadata(),
      ]);
    $this->assertTrue(empty($normalized['data']['attributes']['type']));
    $this->assertTrue(!empty($normalized['data']['attributes']['uuid']));
    $this->assertSame($normalized['data']['attributes']['display_submitted'], TRUE);
    $this->assertSame($normalized['data']['id'], NodeType::load('article')->uuid());
    $this->assertSame($normalized['data']['type'], 'node_type--node_type');
    // Make sure that the cache tags for the includes and the requested entities
    // are bubbling as expected.
    $this->assertSame(['config:node.type.article'], $response->getCacheableMetadata()
      ->getCacheTags());
  }

  /**
   * Try to POST a node and check if it exists afterwards.
   *
   * @covers ::denormalize
   */
  public function testDenormalize() {
    $payload = '{"type":"article", "data":{"attributes":{"title":"Testing article"}}}';

    list($request, $resource_type) = $this->generateProphecies('node', 'article', 'id');
    $node = $this
      ->container
      ->get('serializer.normalizer.jsonapi_document_toplevel.jsonapi')
      ->denormalize(Json::decode($payload), JsonApiDocumentTopLevelNormalizer::class, 'api_json', [
        'request' => $request,
        'resource_type' => $resource_type,
      ]);
    $this->assertInstanceOf('\Drupal\node\Entity\Node', $node);
    $this->assertSame('Testing article', $node->getTitle());
  }

  /**
   * Try to POST a node and check if it exists afterwards.
   *
   * @covers ::denormalize
   */
  public function testDenormalizeUuid() {
    $configurations = [
      // Good data.
      [
        [
          [$this->term2->uuid(), $this->term1->uuid()],
          $this->user2->uuid(),
        ],
        [
          [$this->term2->id(), $this->term1->id()],
          $this->user2->id(),
        ],
      ],
      // Bad data in first tag.
      [
        [
          ['invalid-uuid', $this->term1->uuid()],
          $this->user2->uuid(),
        ],
        [
          [$this->term1->id()],
          $this->user2->id(),
        ],
      ],
      // Bad data in user and first tag.
      [
        [
          ['invalid-uuid', $this->term1->uuid()],
          'also-invalid-uuid',
        ],
        [
          [$this->term1->id()],
          NULL
        ],
      ],
    ];

    foreach ($configurations as $configuration) {
      list($payload_data, $expected) = $this->denormalizeUuidProviderBuilder($configuration);
      $payload = Json::encode($payload_data);

      list($request, $resource_type) = $this->generateProphecies('node', 'article');
      $this->container->get('request_stack')->push($request);
      $node = $this
        ->container
        ->get('serializer.normalizer.jsonapi_document_toplevel.jsonapi')
        ->denormalize(Json::decode($payload), JsonApiDocumentTopLevelNormalizer::class, 'api_json', [
          'request' => $request,
          'resource_type' => $resource_type,
        ]);

      /* @var \Drupal\node\Entity\Node $node */
      $this->assertInstanceOf('\Drupal\node\Entity\Node', $node);
      $this->assertSame('Testing article', $node->getTitle());
      if (!empty($expected['user_id'])) {
        $owner = $node->getOwner();
        $this->assertEquals($expected['user_id'], $owner->id());
      }
      $tags = $node->get('field_tags')->getValue();
      $this->assertEquals($expected['tag_ids'][0], $tags[0]['target_id']);
      if (!empty($expected['tag_ids'][1])) {
        $this->assertEquals($expected['tag_ids'][1], $tags[1]['target_id']);
      }
    }
  }

  /**
   * We cannot use a PHPUnit data provider because our data depends on $this.
   *
   * @param array $options
   *
   * @return array
   *   The test data.
   */
  protected function denormalizeUuidProviderBuilder($options) {
    list($input, $expected) = $options;
    list($input_tag_uuids, $input_user_uuid) = $input;
    list($expected_tag_ids, $expected_user_id) = $expected;

    return [
      [
        'type' => 'node--article',
        'data' => [
          'attributes' => [
            'title' => 'Testing article',
            'id' => '33095485-70D2-4E51-A309-535CC5BC0115',
          ],
          'relationships' => [
            'uid' => [
              'data' => [
                'type' => 'user--user',
                'id' => $input_user_uuid,
              ],
            ],
            'field_tags' => [
              'data' => [
                [
                  'type' => 'taxonomy_term--tags',
                  'id' => $input_tag_uuids[0],
                ],
                [
                  'type' => 'taxonomy_term--tags',
                  'id' => $input_tag_uuids[1],
                ],
              ],
            ],
          ],
        ],
      ],
      [
        'tag_ids' => $expected_tag_ids,
        'user_id' => $expected_user_id,
      ],
    ];
  }

  /**
   * Generates the prophecies for the mocked entity request.
   *
   * @param string $entity_type_id
   *   The ID of the entity type. Ex: node.
   * @param string $bundle
   *   The bundle. Ex: article.
   *
   * @return array
   *   A numeric array containing the request and the ResourceType.
   */
  protected function generateProphecies($entity_type_id, $bundle, $related_property = NULL) {
    $path = sprintf('/%s/%s', $entity_type_id, $bundle);
    $path = $related_property ?
      sprintf('%s/%s', $path, $related_property) :
      $path;

    $route = new Route($path, [
      '_on_relationship' => NULL,
    ], [
      '_entity_type' => $entity_type_id,
      '_bundle' => $bundle,
    ]);
    $request = new Request([], [], [
      RouteObjectInterface::ROUTE_OBJECT => $route,
    ]);
    /* @var \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager */
    $entity_type_manager = $this->container->get('entity_type.manager');

    $resource_type = new ResourceType(
      $entity_type_id,
      $bundle,
      $entity_type_manager->getDefinition($entity_type_id)->getClass()
    );

    /* @var \Symfony\Component\HttpFoundation\RequestStack $request_stack */
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    $this->container->set('request_stack', $request_stack);
    $this->container->get('serializer');

    return [$request, $resource_type];
  }

}
