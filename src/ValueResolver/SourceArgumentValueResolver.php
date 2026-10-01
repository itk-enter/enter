<?php

namespace App\ValueResolver;

use App\Source\SourceInterface;
use App\SourceManager;
use Symfony\Component\Console\ArgumentResolver\ValueResolver\ValueResolverInterface;
use Symfony\Component\Console\Attribute\Reflection\ReflectionMember;
use Symfony\Component\Console\Input\InputInterface;

class SourceArgumentValueResolver implements ValueResolverInterface
{
    public function __construct(
        private readonly SourceManager $sourceManager,
    ) {
    }

    /**
     * @return iterable<SourceInterface>
     */
    public function resolve(string $argumentName, InputInterface $input, ReflectionMember $member): iterable
    {
        /** @var \ReflectionNamedType $type */
        $type = $member->getType();
        if (SourceInterface::class !== $type->getName()) {
            return [];
        }
        $value = $input->getArgument($argumentName);

        return [
            $this->sourceManager->getSource($value),
        ];
    }
}
