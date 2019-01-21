<?php

namespace Drupal\consumer_image_styles\Plugin\jsonapi\FieldEnhancer;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\consumer_image_styles\ImageStylesProvider;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\ImageStyleInterface;
use Drupal\jsonapi_extras\Plugin\ResourceFieldEnhancerBase;
use Shaper\Util\Context;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Perform additional manipulations to timestamp fields.
 *
 * @ResourceFieldEnhancer(
 *   id = "image_styles",
 *   label = @Translation("Image Styles (Image field)"),
 *   description = @Translation("Adds links for images with image styles
 *   applied to them.")
 * )
 */
class ImageStyles extends ResourceFieldEnhancerBase implements ContainerFactoryPluginInterface {

  /**
   * @var \Drupal\consumer_image_styles\ImageStylesProvider
   */
  protected $imageStylesProvider;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ImageStylesProvider $image_styles_provider) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->imageStylesProvider = $image_styles_provider;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $image_styles_provider = $container->get('consumer_image_styles.image_styles_provider');
    $configuration['consumer_image_style_ids'] = [];
    $request = $container->get('request_stack')->getCurrentRequest();
    $consumer = $container->get('consumer.negotiator')
      ->negotiateFromRequest($request);
    if ($consumer) {
      $styles = $image_styles_provider->loadStyles($consumer);
      $configuration['consumer_image_style_ids'] = array_keys($styles);
    }
    return new static($configuration, $plugin_id, $plugin_definition, $image_styles_provider);
  }

  /**
   * {@inheritdoc}
   */
  protected function doUndoTransform($data, Context $context) {
    $image_style_ids = $this->imageStylesForField();
    if (empty($image_style_ids)) {
      return $data;
    }
    // Load image style entities in bulk.
    try {
      $image_styles = \Drupal::entityTypeManager()
        ->getStorage('image_style')
        ->loadMultiple($image_style_ids);
    } catch (InvalidPluginDefinitionException $e) {
      $image_styles = [];
    }
    /** @var \Drupal\Core\Entity\Entity $entity */
    $entity = \Drupal::service('entity.repository')
      ->loadEntityByUuid('file', $data['id']);
    // If the entity cannot be loaded or it's not an image, do not enhance it.
    if (!$entity || !$this->imageStylesProvider->entityIsImage($entity)) {
      return $data;
    }
    /** @var \Drupal\file\Entity\File $entity */
    // If the entity is not viewable
    $access = $entity->access('view', NULL, TRUE);
    if (!$access->isAllowed()) {
      return $data;
    }
    // @TODO: When enhanced transformations carry cacheable meta, add the access info.
    $uri = $entity->getFileUri();
    $links = array_map(
      function(ImageStyleInterface $image_style) use ($uri) {
        return $this->imageStylesProvider->buildDerivativeLink($uri, $image_style);
      },
      $image_styles
    );
    // @TODO: When enhanced transformations carry cacheable meta, add the image styles entities.
    return array_merge_recursive($data, ['meta' => ['links' => $links]]);
  }

  /**
   * Gets the list of image style IDs for the current field.
   *
   * Intersects the field enhancer configuration with the consumer's config to
   * calculate the list of image styles to apply to the field.
   *
   * @return string[]
   *   The list of IDs.
   */
  protected function imageStylesForField() {
    $configuration = $this->getConfiguration();
    $image_style_ids = $configuration['consumer_image_style_ids'];
    // By default make all the image styles for the consumer available, but
    // allow further refinement.
    if (
      !empty($configuration['styles']['refine']) &&
      !empty($configuration['styles']['custom_selection'])
    ) {
      $refined_style_ids = array_filter(
        array_values($configuration['styles']['custom_selection'])
      );
      $image_style_ids = array_intersect($refined_style_ids, $image_style_ids);
    }
    return $image_style_ids;
  }

  /**
   * {@inheritdoc}
   */
  protected function doTransform($value, Context $context) {
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputJsonSchema() {
    return [
      'type' => 'object',
      'properties' => [
        'type' => ['type' => 'string'],
        'id' => ['type' => 'string'],
        'meta' => [
          'type' => 'object',
          'properties' => [
            'height' => ['type' => 'integer'],
            'width' => ['type' => 'integer'],
            'alt' => [
              'anyOf' => [
                ['type' => 'string'],
                ['type' => 'null'],
              ],
            ],
            'title' => [
              'anyOf' => [
                ['type' => 'string'],
                ['type' => 'null'],
              ],
            ],
            'links' => [
              'type' => 'object',
              'patternProperties' => [
                '.*' => [
                  'type' => 'object',
                  'properties' => [
                    'href' => ['type' => 'string', 'format' => 'uri'],
                    'meta' => [
                      'type' => 'object',
                      'properties' => [
                        'rel' => [
                          'type' => 'array',
                          'items' => ['type' => 'string', 'format' => 'uri'],
                        ],
                      ],
                    ]
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSettingsForm(array $resource_field_info) {
    $options = [];
    $settings = empty($resource_field_info['enhancer']['settings'])
      ? $this->getConfiguration()
      : $resource_field_info['enhancer']['settings'];

    foreach (ImageStyle::loadMultiple() as $style) {
      $options[$style->id()] = $style->label();
    }

    $refine_ref = 'resourceFields[' . $resource_field_info['fieldName'] . '][enhancer][settings][styles][refine]';
    return [
      'styles' => [
        '#type' => 'details',
        '#open' => TRUE,
        '#title' => $this->t('Image Styles options'),
        '#tree' => TRUE,
        'refine' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Refine selection?'),
          '#description' => $this->t('Reduces the list of image styles in the output calculated from the Consumer configuration.'),
          '#default_value' => empty($settings['styles']['refine']) ? FALSE : $settings['styles']['refine'],
        ],
        'custom_selection' => [
          '#type' => 'checkboxes',
          '#title' => $this->t('Image Styles'),
          '#description' => $this->t('Narrow down the image styles to display on this field. Note that any image styles selected here that are not allowed in the consumer making the HTTP request will not appear in the output.'),
          '#default_value' => empty($settings['styles']['custom_selection']) ? array_keys($options) : $settings['styles']['custom_selection'],
          '#options' => $options,
          '#states' => [
            'disabled' => [
              ':input[name="' . $refine_ref . '"]' => ['checked' => FALSE],
            ],
            'invisible' => [
              ':input[name="' . $refine_ref . '"]' => ['checked' => FALSE],
            ],
          ],
        ],
      ],
    ];
  }

}
