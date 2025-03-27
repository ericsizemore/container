<?php

declare(strict_types=1);

namespace League\Container;

use League\Container\Definition\{DefinitionAggregate, DefinitionInterface, DefinitionAggregateInterface};
use League\Container\Exception\{NotFoundException, ContainerException};
use League\Container\Inflector\{InflectorAggregate, InflectorInterface, InflectorAggregateInterface};
use League\Container\ServiceProvider\{ServiceProviderAggregate,
    ServiceProviderAggregateInterface,
    ServiceProviderInterface};
use Psr\Container\ContainerInterface;

class Container implements DefinitionContainerInterface
{
    /**
     * @var boolean
     */
    protected $defaultToShared = false;

    /**
     * @var boolean
     */
    protected $defaultToOverwrite = false;

    /**
     * @var DefinitionAggregateInterface
     */
    protected $definitions;

    /**
     * @var ServiceProviderAggregateInterface
     */
    protected $providers;

    /**
     * @var InflectorAggregateInterface
     */
    protected $inflectors;

    /**
     * @var ContainerInterface[]
     */
    protected array $delegates = [];

    public function __construct(
        protected DefinitionAggregateInterface $definitions = new DefinitionAggregate(),
        protected ServiceProviderAggregateInterface $providers = new ServiceProviderAggregate(),
        protected InflectorAggregateInterface $inflectors = new InflectorAggregate(),
        protected bool $defaultToShared = false
    ) {
        if ($this->definitions instanceof ContainerAwareInterface) {
            $this->definitions->setContainer($this);
        }

        if ($this->providers instanceof ContainerAwareInterface) {
            $this->providers->setContainer($this);
        }

        if ($this->inflectors instanceof ContainerAwareInterface) {
            $this->inflectors->setContainer($this);
        }
    }

    public function add(string $id, $concrete = null, bool $overwrite = false): DefinitionInterface
    {
        $toOverwrite = $this->defaultToOverwrite || $overwrite;
        $concrete = $concrete ??= $id;

        if (true === $this->defaultToShared) {
            return $this->addShared($id, $concrete, $toOverwrite);
        }

        return $this->definitions->add($id, $concrete, $toOverwrite);
    }

    public function addShared(string $id, $concrete = null, bool $overwrite = false): DefinitionInterface
    {
        $toOverwrite = $this->defaultToOverwrite || $overwrite;
        $concrete = $concrete ??= $id;
        return $this->definitions->addShared($id, $concrete, $toOverwrite);
    }

    public function defaultToShared(bool $shared = true): ContainerInterface
    {
        $this->defaultToShared = $shared;
        return $this;
    }

    public function defaultToOverwrite(bool $overwrite = true): ContainerInterface
    {
        $this->defaultToOverwrite = $overwrite;
        return $this;
    }

    public function extend(string $id): DefinitionInterface
    {
        if ($this->providers->provides($id)) {
            $this->providers->register($id);
        }

        if ($this->definitions->has($id)) {
            return $this->definitions->getDefinition($id);
        }

        throw new NotFoundException(sprintf(
            'Unable to extend alias (%s) as it is not being managed as a definition',
            $id
        ));
    }

    public function addServiceProvider(ServiceProviderInterface $provider): DefinitionContainerInterface
    {
        $this->providers->add($provider);
        return $this;
    }

    public function get(string $id)
    {
        return $this->resolve($id);
    }

    public function getNew(string $id): mixed
    {
        return $this->resolve($id, true);
    }

    public function has(string $id): bool
    {
        if ($this->definitions->has($id)) {
            return true;
        }

        if ($this->definitions->hasTag($id)) {
            return true;
        }

        if ($this->providers->provides($id)) {
            return true;
        }

        foreach ($this->delegates as $delegate) {
            if ($delegate->has($id)) {
                return true;
            }
        }

        return false;
    }

    public function inflector(string $type, callable $callback = null): InflectorInterface
    {
        return $this->inflectors->add($type, $callback);
    }

    public function delegate(ContainerInterface $container): self
    {
        $this->delegates[] = $container;

        if ($container instanceof ContainerAwareInterface) {
            $container->setContainer($this);
        }

        return $this;
    }

    protected function resolve(string $id, bool $new = false): mixed
    {
        if ($this->definitions->has($id)) {
            $resolved = (true === $new) ? $this->definitions->resolveNew($id) : $this->definitions->resolve($id);
            return $this->inflectors->inflect($resolved);
        }

        if ($this->definitions->hasTag($id)) {
            $arrayOf = (true === $new)
                ? $this->definitions->resolveTaggedNew($id)
                : $this->definitions->resolveTagged($id);

            array_walk($arrayOf, function (&$resolved) {
                $resolved = $this->inflectors->inflect($resolved);
            });

            return $arrayOf;
        }

        if ($this->providers->provides($id)) {
            $this->providers->register($id);

            if (false === $this->definitions->has($id) && false === $this->definitions->hasTag($id)) {
                throw new ContainerException(sprintf('Service provider lied about providing (%s) service', $id));
            }

            return $this->resolve($id, $new);
        }

        foreach ($this->delegates as $delegate) {
            if ($delegate->has($id)) {
                $resolved = $delegate->get($id);
                return $this->inflectors->inflect($resolved);
            }
        }

        throw new NotFoundException(sprintf('Alias (%s) is not being managed by the container or delegates', $id));
    }
}
