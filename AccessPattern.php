<?php
declare(strict_types=1);

namespace Dynamite;

/**
 * @author pizzaminded <mikolajczajkowsky@gmail.com>
 * @license MIT
 */
class AccessPattern
{
    protected string $name;
    protected ?string $index = null;
    protected string $partitionKeyFormat;
    protected ?string $sortKeyFormat = null;
    protected AccessPatternOperation $operation;


    protected function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function create(string $name): self
    {
        return new self($name);
    }


    public function withIndex(string $index)
    {
        $self = clone $this;
        $self->index = $index;
        return $self;
    }

    public function withPartitionKeyFormat(string $pk)
    {
        $self = clone $this;
        $self->partitionKeyFormat = $pk;
        return $self;
    }

    public function withSortKeyFormat(string $sk)
    {
        $self = clone $this;
        $self->sortKeyFormat = $sk;
        return $self;
    }

    public function withOperation(AccessPatternOperation $operation)
    {
        $self = clone $this;
        $self->operation = $operation;
        return $self;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string|null
     */
    public function getIndex(): ?string
    {
        return $this->index;
    }

    /**
     * @return string
     */
    public function getPartitionKeyFormat(): string
    {
        return $this->partitionKeyFormat;
    }

    /**
     * @return string|null
     */
    public function getSortKeyFormat(): ?string
    {
        return $this->sortKeyFormat;
    }

    /**
     * @return AccessPatternOperation
     */
    public function getOperation(): AccessPatternOperation
    {
        return $this->operation;
    }


}