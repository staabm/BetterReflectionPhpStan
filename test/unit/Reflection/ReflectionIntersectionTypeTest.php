<?php

declare(strict_types=1);

namespace Roave\BetterReflectionTest\Reflection;

use PhpParser\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPStan\BetterReflection\Reflection\ReflectionIntersectionType;
use PHPStan\BetterReflection\Reflection\ReflectionNamedType;
use PHPStan\BetterReflection\Reflection\ReflectionParameter;
use PHPStan\BetterReflection\Reflector\Reflector;

#[CoversClass(ReflectionIntersectionType::class)]
class ReflectionIntersectionTypeTest extends TestCase
{
    /**
     * @var \PHPStan\BetterReflection\Reflector\Reflector
     */
    private $reflector;
    /**
     * @var \PHPStan\BetterReflection\Reflection\ReflectionParameter
     */
    private $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reflector = $this->createMock(Reflector::class);
        $this->owner     = $this->createMock(ReflectionParameter::class);
    }

    /** @return list<array{0: Node\IntersectionType, 1: string}> */
    public static function dataProvider(): array
    {
        return [
            [new Node\IntersectionType([new Node\Name('\A\Foo'), new Node\Name('Boo')]), '\A\Foo&Boo'],
            [new Node\IntersectionType([new Node\Name('A'), new Node\Name('B')]), 'A&B'],
        ];
    }

    #[DataProvider('dataProvider')]
    public function test(Node\IntersectionType $intersectionType, string $expectedString): void
    {
        $typeReflection = new ReflectionIntersectionType($this->reflector, $this->owner, $intersectionType);

        self::assertContainsOnlyInstancesOf(ReflectionNamedType::class, $typeReflection->getTypes());
        self::assertSame($expectedString, $typeReflection->__toString());
        self::assertFalse($typeReflection->allowsNull());
    }

    public function testWithOwner(): void
    {
        $typeReflection = new ReflectionIntersectionType($this->reflector, $this->owner, new Node\IntersectionType([new Node\Name('\A\Foo'), new Node\Name('Boo')]));
        $types          = $typeReflection->getTypes();

        self::assertCount(2, $types);

        $owner = $this->createMock(ReflectionParameter::class);

        $cloneTypeReflection = $typeReflection->withOwner($owner);

        self::assertNotSame($typeReflection, $cloneTypeReflection);

        $cloneTypes = $cloneTypeReflection->getTypes();

        self::assertCount(2, $cloneTypes);
        self::assertNotSame($types[0], $cloneTypes[0]);
    }
}
