<?php
declare(strict_types=1);

namespace Dynamite;

use Dynamite\Exception\DynamiteException;
use Dynamite\Exception\ItemNotFoundException;
use Dynamite\Exception\ItemRepositoryException;
use Dynamite\Mapping\ItemMapping;
use Dynamite\Repository\AccessPatternsProviderInterface;
use Dynamite\Typed\QueryRequest;

/**
 * @author pizzaminded <mikolajczajkowsky@gmail.com>
 * @license MIT
 */
class ItemRepository
{
    private SingleTableService $singleTableService;
    private string $itemName;
    private ItemMapping $itemMapping;
    private ItemSerializer $itemSerializer;
    /**
     * @var AccessPattern[]
     */
    private array $accessPatterns = [];


    public function __construct(
        SingleTableService $singleTableService,
        string $itemName,
        ItemMapping $itemMapping,
        ItemSerializer $itemSerializer
    )
    {
        $this->singleTableService = $singleTableService;
        $this->itemName = $itemName;
        $this->itemMapping = $itemMapping;
        $this->itemSerializer = $itemSerializer;

        if ($this instanceof AccessPatternsProviderInterface) {
            $this->accessPatterns = $this->registerAccessPatterns();
        }
    }

    /**
     * When passing a string to $partitionKey or $sortKey, Dynamite will pass them to DB directly.
     * But when passing an array, it will build an key using data from SortKeyFormat and PrimaryKeyFormat annotations.
     * Remember to pass all "fragments" of key in array in given format:
     * [ fieldName => value ]
     *
     * @param array<string, string>|string $partitionKey
     * @param array<string, string>|string $sortKey
     * @return object
     * @throws ItemNotFoundException
     */
    public function getItem($partitionKey, $sortKey = null): object
    {
        if (is_array($partitionKey)) {
            $pkFormat = $this->itemMapping->getPartitionKeyFormat();
            $pkPlaceholders = [];

            foreach ($partitionKey as $key => $val) {
                $pkPlaceholders[sprintf('{%s}', $key)] = $val;
            }

            $partitionKey = str_replace(
                array_keys($pkPlaceholders),
                array_values($pkPlaceholders),
                $pkFormat
            );
        }

        if ($sortKey === null) {
            $sortKey = $this->itemMapping->getSortKeyFormat();
        } elseif (is_array($sortKey)) {
            $skFormat = $this->itemMapping->getSortKeyFormat();

            $skPlaceholders = [];

            foreach ($sortKey as $key => $val) {
                $skPlaceholders[sprintf('{%s}', $key)] = $val;
            }

            $sortKey = str_replace(
                array_keys($skPlaceholders),
                array_values($skPlaceholders),
                $skFormat
            );
        }

        $item = $this->singleTableService->getItem($partitionKey, $sortKey);

        if ($item === null) {
            throw new ItemNotFoundException(
                sprintf('Could not find item with PK "%s" and SK "%s"', $partitionKey, $sortKey)
            );
        }

        return $this->itemSerializer->hydrateObject($this->itemName, $this->itemMapping, $item);
    }

    /**
     * @param string $patternName
     * @param int|null $limit
     * @param array|null $lastEvaluatedKey
     * @return object|object[]|QueryIterator
     * @throws \Exception
     */
    public function executeAccessPattern(string $patternName, ?int $limit = null, ?array $lastEvaluatedKey = null)
    {
        foreach ($this->accessPatterns as $accessPattern) {
            if ($accessPattern->getName() === $patternName) {
                $request = new QueryRequest();

                if ($limit !== null) {
                    $request->withLimit($limit);
                }

                if ($lastEvaluatedKey !== null) {
                    $request->withExclusiveStartKey($lastEvaluatedKey);
                }


                $request
                    ->withKeyConditionExpression('#pk = :pk')
                    ->withExpressionAttributeName(
                        '#pk',
                        $this->singleTableService->getTableConfiguration()->getPartitionKeyName()
                    )
                    ->withExpressionAttributeValue(
                        ':pk',
                        $accessPattern->getPartitionKeyFormat()
                    );

                if ($accessPattern->getIndex() !== null) {
                    $indexPrimaryKeyPair =
                        $this->singleTableService
                            ->getTableConfiguration()
                            ->getIndexPrimaryKeyPair(
                                $accessPattern->getIndex()
                            );

                    $request
                        ->withIndexName($accessPattern->getIndex())
                        ->withExpressionAttributeName(
                            '#pk',
                            $indexPrimaryKeyPair[0]
                        );

                }

                return $this->query($request);
            }
        }

        throw new \Exception('access pattern not found');
    }

    public function put(object $item): void
    {
        if (!($item instanceof $this->itemName)) {
            throw ItemRepositoryException::objectNotSupported(get_class($item), $this->itemName);
        }

        $serializedValues = $this->itemSerializer->serialize($item, $this->itemMapping);
        $partitionKeyFormat = $this->itemMapping->getPartitionKeyFormat();
        $primaryKeyPlaceholders = [];

        foreach ($serializedValues as $property => $value) {
            if (is_array($value)) {
                foreach ($value as $valueKey => $val) {
                    if (is_array($val)) {
                        throw new DynamiteException('FIXME: more than one nested array');
                    }
                    $primaryKeyPlaceholders[sprintf('{%s.%s}', $property, $valueKey)] = $val;
                }
            } else {
                $primaryKeyPlaceholders[sprintf('{%s}', $property)] = $value;
            }
        }

        $partitionKeyValue = \str_replace(
            array_keys($primaryKeyPlaceholders),
            array_values($primaryKeyPlaceholders),
            $partitionKeyFormat
        );

        $sortKeyValue = null;
        if ($this->itemMapping->getSortKeyFormat() !== null) {
            $sortKeyValue = \str_replace(
                array_keys($primaryKeyPlaceholders),
                array_values($primaryKeyPlaceholders),
                $this->itemMapping->getSortKeyFormat()
            );
        }

        $serializedValues['objectType'] = $this->itemMapping->getObjectType();

        $duplicates = $this->itemMapping->getDuplicates();

        if (count($duplicates) > 0) {
            $tablePkName = $this->singleTableService->getTableConfiguration()->getPartitionKeyName();
            $tableSkName = $this->singleTableService->getTableConfiguration()->getSortKeyName();

            $serializedValues[$tablePkName] = $partitionKeyValue;
            $serializedValues[$tableSkName] = $sortKeyValue;

            $batch = [];
            $batch[] = $serializedValues;

            foreach ($duplicates as $duplicate) {
                $duplicatedItem = [];
                $propsToDuplicate = $duplicate->getProps();

                foreach ($serializedValues as $key => $val) {
                    if (in_array($key, $propsToDuplicate)) {
                        $duplicatedItem[$key] = $serializedValues[$key];
                    }
                }

                $duplicatedItem[$tablePkName] = \str_replace(
                    array_keys($primaryKeyPlaceholders),
                    array_values($primaryKeyPlaceholders),
                    $duplicate->getPartitionKeyFormat()
                );

                $duplicatedItem[$tableSkName] = \str_replace(
                    array_keys($primaryKeyPlaceholders),
                    array_values($primaryKeyPlaceholders),
                    $duplicate->getSortKeyFormat()
                );

                $batch[] = $duplicatedItem;

            }

            $this->singleTableService->writeRequestBatch($batch);
            return;
        }

        $this->singleTableService->putItem(
            $partitionKeyValue,
            $serializedValues,
            $sortKeyValue
        );
    }


    public function query(QueryRequest $request): QueryIterator
    {
        $response = $this->singleTableService->rawQuery($request);
        $items = $response->getItems();
        $output = [];
        foreach ($items as $item) {
            $unmarshaledItem = $this->singleTableService->unmarshalItem($item);
            $output[] = $this->itemSerializer->hydrateObject($this->itemName, $this->itemMapping, $unmarshaledItem);
        }

        return new QueryIterator(
            $output,
            $response->getLastEvaluatedKey()
        );
    }

    protected function getSingleTableService(): SingleTableService
    {
        return $this->singleTableService;
    }
}