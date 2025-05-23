<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Hal\Serializer;

use ApiPlatform\Metadata\IriConverterInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\ResourceAccessCheckerInterface;
use ApiPlatform\Metadata\ResourceClassResolverInterface;
use ApiPlatform\Metadata\UrlGeneratorInterface;
use ApiPlatform\Metadata\Util\ClassInfoTrait;
use ApiPlatform\Metadata\Util\TypeHelper;
use ApiPlatform\Serializer\AbstractItemNormalizer;
use ApiPlatform\Serializer\CacheKeyTrait;
use ApiPlatform\Serializer\ContextTrait;
use ApiPlatform\Serializer\TagCollectorInterface;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\PropertyInfo\Type as LegacyType;
use Symfony\Component\Serializer\Exception\CircularReferenceException;
use Symfony\Component\Serializer\Exception\LogicException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Mapping\AttributeMetadataInterface;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactoryInterface;
use Symfony\Component\Serializer\NameConverter\NameConverterInterface;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\CompositeTypeInterface;
use Symfony\Component\TypeInfo\Type\ObjectType;

/**
 * Converts between objects and array including HAL metadata.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
final class ItemNormalizer extends AbstractItemNormalizer
{
    use CacheKeyTrait;
    use ClassInfoTrait;
    use ContextTrait;

    public const FORMAT = 'jsonhal';

    protected const HAL_CIRCULAR_REFERENCE_LIMIT_COUNTERS = 'hal_circular_reference_limit_counters';

    private array $componentsCache = [];
    private array $attributesMetadataCache = [];

    public function __construct(PropertyNameCollectionFactoryInterface $propertyNameCollectionFactory, PropertyMetadataFactoryInterface $propertyMetadataFactory, IriConverterInterface $iriConverter, ResourceClassResolverInterface $resourceClassResolver, ?PropertyAccessorInterface $propertyAccessor = null, ?NameConverterInterface $nameConverter = null, ?ClassMetadataFactoryInterface $classMetadataFactory = null, array $defaultContext = [], ?ResourceMetadataCollectionFactoryInterface $resourceMetadataCollectionFactory = null, ?ResourceAccessCheckerInterface $resourceAccessChecker = null, ?TagCollectorInterface $tagCollector = null)
    {
        $defaultContext[AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER] = function ($object): ?array {
            $iri = $this->iriConverter->getIriFromResource($object);
            if (null === $iri) {
                return null;
            }

            return ['_links' => ['self' => ['href' => $iri]]];
        };

        parent::__construct($propertyNameCollectionFactory, $propertyMetadataFactory, $iriConverter, $resourceClassResolver, $propertyAccessor, $nameConverter, $classMetadataFactory, $defaultContext, $resourceMetadataCollectionFactory, $resourceAccessChecker, $tagCollector);
    }

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return self::FORMAT === $format && parent::supportsNormalization($data, $format, $context);
    }

    public function getSupportedTypes($format): array
    {
        return self::FORMAT === $format ? parent::getSupportedTypes($format) : [];
    }

    /**
     * {@inheritdoc}
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $resourceClass = $this->getObjectClass($object);
        if ($this->getOutputClass($context)) {
            return parent::normalize($object, $format, $context);
        }

        $previousResourceClass = $context['resource_class'] ?? null;
        if ($this->resourceClassResolver->isResourceClass($resourceClass) && (null === $previousResourceClass || $this->resourceClassResolver->isResourceClass($previousResourceClass))) {
            $resourceClass = $this->resourceClassResolver->getResourceClass($object, $previousResourceClass);
        }

        $context = $this->initContext($resourceClass, $context);

        $iri = $context['iri'] ??= $this->iriConverter->getIriFromResource($object, UrlGeneratorInterface::ABS_PATH, $context['operation'] ?? null, $context);
        $context['object'] = $object;
        $context['format'] = $format;
        $context['api_normalize'] = true;

        if (!isset($context['cache_key'])) {
            $context['cache_key'] = $this->getCacheKey($format, $context);
        }

        $data = parent::normalize($object, $format, $context);

        if (!\is_array($data)) {
            return $data;
        }

        $metadata = [
            '_links' => [
                'self' => [
                    'href' => $iri,
                ],
            ],
        ];
        $components = $this->getComponents($object, $format, $context);
        $metadata = $this->populateRelation($metadata, $object, $format, $context, $components, 'links');
        $metadata = $this->populateRelation($metadata, $object, $format, $context, $components, 'embedded');

        return $metadata + $data;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        // prevent the use of lower priority normalizers (e.g. serializer.normalizer.object) for this format
        return self::FORMAT === $format;
    }

    /**
     * {@inheritdoc}
     *
     * @throws LogicException
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): never
    {
        throw new LogicException(\sprintf('%s is a read-only format.', self::FORMAT));
    }

    /**
     * {@inheritdoc}
     */
    protected function getAttributes($object, $format = null, array $context = []): array
    {
        return $this->getComponents($object, $format, $context)['states'];
    }

    /**
     * Gets HAL components of the resource: states, links and embedded.
     */
    private function getComponents(object $object, ?string $format, array $context): array
    {
        $cacheKey = $this->getObjectClass($object).'-'.$context['cache_key'];

        if (isset($this->componentsCache[$cacheKey])) {
            return $this->componentsCache[$cacheKey];
        }

        $attributes = parent::getAttributes($object, $format, $context);
        $options = $this->getFactoryOptions($context);

        $components = [
            'states' => [],
            'links' => [],
            'embedded' => [],
        ];

        foreach ($attributes as $attribute) {
            $propertyMetadata = $this->propertyMetadataFactory->create($context['resource_class'], $attribute, $options);

            if (method_exists(PropertyInfoExtractor::class, 'getType')) {
                $type = $propertyMetadata->getNativeType();
                $types = $type instanceof CompositeTypeInterface ? $type->getTypes() : (null === $type ? [] : [$type]);
                /** @var class-string|null $className */
                $className = null;
            } else {
                $types = $propertyMetadata->getBuiltinTypes() ?? [];
            }

            // prevent declaring $attribute as attribute if it's already declared as relationship
            $isRelationship = false;
            $typeIsResourceClass = function (Type $type) use (&$className): bool {
                return $type instanceof ObjectType && $this->resourceClassResolver->isResourceClass($className = $type->getClassName());
            };

            foreach ($types as $type) {
                $isOne = $isMany = false;

                /** @var Type|LegacyType|null $valueType */
                $valueType = null;

                if ($type instanceof LegacyType) {
                    if ($type->isCollection()) {
                        $valueType = $type->getCollectionValueTypes()[0] ?? null;
                        $isMany = null !== $valueType && ($className = $valueType->getClassName()) && $this->resourceClassResolver->isResourceClass($className);
                    } else {
                        $className = $type->getClassName();
                        $isOne = $className && $this->resourceClassResolver->isResourceClass($className);
                    }
                } elseif ($type instanceof Type) {
                    if ($type->isSatisfiedBy(fn ($t) => $t instanceof CollectionType)) {
                        $isMany = TypeHelper::getCollectionValueType($type)?->isSatisfiedBy($typeIsResourceClass);
                    } else {
                        $isOne = $type->isSatisfiedBy($typeIsResourceClass);
                    }
                }

                if (!$isOne && !$isMany) {
                    // don't declare it as an attribute too quick: maybe the next type is a valid resource
                    continue;
                }

                $relation = ['name' => $attribute, 'cardinality' => $isOne ? 'one' : 'many', 'iri' => null, 'operation' => null];

                // if we specify the uriTemplate, generates its value for link definition
                // @see ApiPlatform\Serializer\AbstractItemNormalizer:getAttributeValue logic for intentional duplicate content
                if (($className ?? false) && $uriTemplate = $propertyMetadata->getUriTemplate()) {
                    $childContext = $this->createChildContext($context, $attribute, $format);
                    unset($childContext['iri'], $childContext['uri_variables'], $childContext['resource_class'], $childContext['operation'], $childContext['operation_name']);

                    $operation = $this->resourceMetadataCollectionFactory->create($className)->getOperation(
                        operationName: $uriTemplate,
                        httpOperation: true
                    );

                    $relation['iri'] = $this->iriConverter->getIriFromResource($object, UrlGeneratorInterface::ABS_PATH, $operation, $childContext);
                    $relation['operation'] = $operation;
                    $cacheKey = null;
                }

                if ($propertyMetadata->isReadableLink()) {
                    $components['embedded'][] = $relation;
                }

                $components['links'][] = $relation;
                $isRelationship = true;
            }

            // if all types are not relationships, declare it as an attribute
            if (!$isRelationship) {
                $components['states'][] = $attribute;
            }
        }

        if ($cacheKey && false !== $context['cache_key']) {
            $this->componentsCache[$cacheKey] = $components;
        }

        return $components;
    }

    /**
     * Populates _links and _embedded keys.
     */
    private function populateRelation(array $data, object $object, ?string $format, array $context, array $components, string $type): array
    {
        $class = $this->getObjectClass($object);

        if ($this->isHalCircularReference($object, $context)) {
            return $this->handleHalCircularReference($object, $format, $context);
        }

        $attributesMetadata = \array_key_exists($class, $this->attributesMetadataCache) ?
            $this->attributesMetadataCache[$class] :
            $this->attributesMetadataCache[$class] = $this->classMetadataFactory ? $this->classMetadataFactory->getMetadataFor($class)->getAttributesMetadata() : null;

        $key = '_'.$type;
        foreach ($components[$type] as $relation) {
            if (null !== $attributesMetadata && $this->isMaxDepthReached($attributesMetadata, $class, $relation['name'], $context)) {
                continue;
            }

            $relationName = $relation['name'];
            if ($this->nameConverter) {
                $relationName = $this->nameConverter->normalize($relationName, $class, $format, $context);
            }

            // if we specify the uriTemplate, then the link takes the uriTemplate defined.
            if ('links' === $type && $iri = $relation['iri']) {
                $data[$key][$relationName]['href'] = $iri;
                continue;
            }

            $childContext = $this->createChildContext($context, $relationName, $format);
            unset($childContext['iri'], $childContext['uri_variables'], $childContext['operation'], $childContext['operation_name']);

            if ($operation = $relation['operation']) {
                $childContext['operation'] = $operation;
                $childContext['operation_name'] = $operation->getName();
            }

            $attributeValue = $this->getAttributeValue($object, $relation['name'], $format, $childContext);

            if (empty($attributeValue)) {
                continue;
            }

            if ('one' === $relation['cardinality']) {
                if ('links' === $type) {
                    $data[$key][$relationName]['href'] = $this->getRelationIri($attributeValue);
                    continue;
                }

                $data[$key][$relationName] = $attributeValue;
                continue;
            }

            // many
            $data[$key][$relationName] = [];
            foreach ($attributeValue as $rel) {
                if ('links' === $type) {
                    $rel = ['href' => $this->getRelationIri($rel)];
                }

                $data[$key][$relationName][] = $rel;
            }
        }

        return $data;
    }

    /**
     * Gets the IRI of the given relation.
     *
     * @throws UnexpectedValueException
     */
    private function getRelationIri(mixed $rel): string
    {
        if (!(\is_array($rel) || \is_string($rel))) {
            throw new UnexpectedValueException('Expected relation to be an IRI or array');
        }

        return \is_string($rel) ? $rel : $rel['_links']['self']['href'];
    }

    /**
     * Is the max depth reached for the given attribute?
     *
     * @param AttributeMetadataInterface[] $attributesMetadata
     */
    private function isMaxDepthReached(array $attributesMetadata, string $class, string $attribute, array &$context): bool
    {
        if (
            !($context[self::ENABLE_MAX_DEPTH] ?? false)
            || !isset($attributesMetadata[$attribute])
            || null === $maxDepth = $attributesMetadata[$attribute]->getMaxDepth()
        ) {
            return false;
        }

        $key = \sprintf(self::DEPTH_KEY_PATTERN, $class, $attribute);
        if (!isset($context[$key])) {
            $context[$key] = 1;

            return false;
        }

        if ($context[$key] === $maxDepth) {
            return true;
        }

        ++$context[$key];

        return false;
    }

    /**
     * Detects if the configured circular reference limit is reached.
     *
     * @throws CircularReferenceException
     */
    protected function isHalCircularReference(object $object, array &$context): bool
    {
        $objectHash = spl_object_hash($object);

        $circularReferenceLimit = $context[AbstractNormalizer::CIRCULAR_REFERENCE_LIMIT] ?? $this->defaultContext[AbstractNormalizer::CIRCULAR_REFERENCE_LIMIT];
        if (isset($context[self::HAL_CIRCULAR_REFERENCE_LIMIT_COUNTERS][$objectHash])) {
            if ($context[self::HAL_CIRCULAR_REFERENCE_LIMIT_COUNTERS][$objectHash] >= $circularReferenceLimit) {
                unset($context[self::HAL_CIRCULAR_REFERENCE_LIMIT_COUNTERS][$objectHash]);

                return true;
            }

            ++$context[self::HAL_CIRCULAR_REFERENCE_LIMIT_COUNTERS][$objectHash];
        } else {
            $context[self::HAL_CIRCULAR_REFERENCE_LIMIT_COUNTERS][$objectHash] = 1;
        }

        return false;
    }

    /**
     * Handles a circular reference.
     *
     * If a circular reference handler is set, it will be called. Otherwise, a
     * {@class CircularReferenceException} will be thrown.
     *
     * @final
     *
     * @throws CircularReferenceException
     */
    protected function handleHalCircularReference(object $object, ?string $format = null, array $context = []): mixed
    {
        $circularReferenceHandler = $context[AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER] ?? $this->defaultContext[AbstractNormalizer::CIRCULAR_REFERENCE_HANDLER];
        if ($circularReferenceHandler) {
            return $circularReferenceHandler($object, $format, $context);
        }

        throw new CircularReferenceException(\sprintf('A circular reference has been detected when serializing the object of class "%s" (configured limit: %d).', get_debug_type($object), $context[AbstractNormalizer::CIRCULAR_REFERENCE_LIMIT] ?? $this->defaultContext[AbstractNormalizer::CIRCULAR_REFERENCE_LIMIT]));
    }
}
