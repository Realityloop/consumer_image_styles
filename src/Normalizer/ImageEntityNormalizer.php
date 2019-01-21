<?php

namespace Drupal\consumer_image_styles\Normalizer;

use Drupal\consumer_image_styles\ImageStylesProvider;
use Drupal\consumers\Negotiator;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\file\Entity\File;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\image\ImageStyleInterface;
use Drupal\jsonapi\Normalizer\NormalizerBase;
use Drupal\jsonapi\Normalizer\Value\CacheableNormalization;
use Drupal\jsonapi\Normalizer\Value\CacheableOmission;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;

class ImageEntityNormalizer extends NormalizerBase implements DenormalizerInterface {

  /**
   * The interface or class that this Normalizer supports.
   *
   * @var string
   */
  protected $supportedInterfaceOrClass = File::class;

  /**
   * The formats that the Normalizer can handle.
   *
   * @var array
   */
  protected $formats = ['api_json'];

  /**
   * @var \Drupal\consumers\Negotiator
   */
  protected $consumerNegotiator;

  /**
   * @var \Drupal\consumer_image_styles\ImageStylesProvider
   */
  protected $imageStylesProvider;

  /**
   * @var \Drupal\Core\Image\ImageFactory
   */
  protected $imageFactory;

  /**
   * @var \Symfony\Component\Serializer\Normalizer\NormalizerInterface
   */
  protected $subject;

  /**
   * Constructs an EntityNormalizer object.
   *
   * @param \Symfony\Component\Serializer\Normalizer\NormalizerInterface $subject
   *   The decorated service.
   * @param \Drupal\consumers\Negotiator $consumer_negotiator
   *   The consumer negotiator.
   * @param \Drupal\consumer_image_styles\ImageStylesProvider
   *   Image styles utility.
   * @param \Drupal\Core\Image\ImageFactory $image_factory
   *   The image factory.
   */
  public function __construct(NormalizerInterface $subject, Negotiator $consumer_negotiator, ImageStylesProvider $imageStylesProvider, ImageFactory $image_factory) {
    $this->subject = $subject;
    $this->consumerNegotiator = $consumer_negotiator;
    $this->imageStylesProvider = $imageStylesProvider;
    $this->imageFactory = $image_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function setSerializer(SerializerInterface $serializer) {
    if ($this->serializer) {
      return;
    }
    parent::setSerializer($serializer);
    if (!$this->subject instanceof SerializerAwareInterface) {
      return;
    }
    $this->subject->setSerializer($serializer);
  }


  /**
   * {@inheritdoc}
   */
  public function supportsNormalization($data, $format = NULL) {
    if (!parent::supportsNormalization($data, $format)) {
      return FALSE;
    }
    /** @var \Drupal\file\Entity\File $data */
    $image = $this->imageFactory->get($data->getFileUri());
    return $image->isValid();
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDenormalization($data, $type, $format = NULL) {
    // We do not need to do anything special about denormalization. Passing here
    // will have the serializer use the normal content entity normalizer.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function normalize($entity, $format = NULL, array $context = []) {
    /** @var \Drupal\jsonapi\Normalizer\Value\CacheableNormalization $normalized_output */
    $normalized_output = $this->subject->normalize($entity, $format, $context);
    $variants = $this->buildVariantLinks($entity, $context);
    if ($variants instanceof CacheableOmission) {
      return $normalized_output;
    }

    $cacheability = CacheableMetadata::createFromObject($normalized_output);
    $cacheability->merge(CacheableMetadata::createFromObject($variants));
    $normalization = array_merge_recursive(
      $normalized_output->getNormalization(),
      ['links' => $variants->getNormalization()]
    );

    return new CacheableNormalization($cacheability, $normalization);
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = []) {
    // This should never be called.
    throw new \Exception('Unsupported denormalizer.');
  }

  /**
   * @param \Drupal\Core\Entity\EntityInterface $entity
   * @param array $context
   *
   * @return \Drupal\jsonapi\Normalizer\Value\CacheableNormalization
   *   The normalization.
   */
  protected function buildVariantLinks(EntityInterface $entity, array $context = []) {
    $request = empty($context['request']) ? NULL : $context['request'];
    $consumer = $this->consumerNegotiator->negotiateFromRequest($request);
    $access = $entity->access('view', $context['account'], TRUE);
    $cacheability = CacheableMetadata::createFromObject($access)
      ->addCacheableDependency($entity);

    // Bail-out if no consumer is found.
    if (!$consumer) {
      return new CacheableOmission($cacheability);
    }
    // If the entity cannot be loaded or it's not an image, do not enhance it.
    if (!$this->imageStylesProvider->entityIsImage($entity)) {
      return new CacheableOmission($cacheability);
    }
    /** @var \Drupal\file\Entity\File $entity */
    // If the entity is not viewable
    if (!$access->isAllowed()) {
      return new CacheableOmission($cacheability);
    }

    // Prepare some utils.
    $uri = $entity->getFileUri();
    // Generate derivatives only for the found ones.
    $image_styles = $this->imageStylesProvider->loadStyles($consumer);
    $keys = array_keys($image_styles);
    $values = array_map(
      function(ImageStyleInterface $image_style) use ($uri) {
        return $this->imageStylesProvider->buildDerivativeLink($uri, $image_style);
      },
      array_values($image_styles)
    );
    $value = array_combine($keys, $values);

    $extra_cacheability = array_reduce(
      $image_styles,
      function (RefinableCacheableDependencyInterface $cacheable, ImageStyleInterface $image_style) {
        return $cacheable->addCacheableDependency($image_style);
      },
      new CacheableMetadata()
    );
    return new CacheableNormalization(
      $cacheability->merge($extra_cacheability),
      $value
    );
  }

}
