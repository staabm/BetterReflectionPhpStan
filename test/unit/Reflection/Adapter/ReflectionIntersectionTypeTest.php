<?php

declare(strict_types=1);

namespace Roave\BetterReflectionTest\Reflection\Adapter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass as CoreReflectionClass;
use ReflectionIntersectionType as CoreReflectionIntersectionType;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionIntersectionType as ReflectionIntersectionTypeAdapter;
use PHPStan\BetterReflection\Reflection\Adapter\ReflectionNamedType as ReflectionNamedTypeAdapter;
use PHPStan\BetterReflection\Reflection\ReflectionIntersectionType as BetterReflectionIntersectionType;
use PHPStan\BetterReflection\Reflection\ReflectionNamedType as BetterReflectionNamedType;

use function array_combine;
use function array_map;
use function get_class_methods;

#[CoversClass(ReflectionIntersectionTypeAdapter::class)]
class ReflectionIntersectionTypeTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function coreReflectionMethodNamesProvider(): array
    {
        $methods = get_class_methods(CoreReflectionIntersectionType::class);

        return array_combine($methods, array_map(static function (string $i) : array {
            return [$i];
        }, $methods));
    }

    #[DataProvider('coreReflectionMethodNamesProvider')]
    public function testCoreReflectionMethods(string $methodName): void
    {
        $reflectionTypeAdapterReflection = new CoreReflectionClass(ReflectionIntersectionTypeAdapter::class);

        self::assertTrue($reflectionTypeAdapterReflection->hasMethod($methodName));
        self::assertSame(ReflectionIntersectionTypeAdapter::class, $reflectionTypeAdapterReflection->getMethod($methodName)->getDeclaringClass()->getName());
    }

    /** @return list<array{0: string, 1: class-string|null, 2: mixed, 3: list<mixed>}> */
    public static function methodExpectationProvider(): array
    {
        return [
            ['__toString', null, 'int|string', []],
            ['allowsNull', null, true, []],
            ['getTypes', null, [], []],
        ];
    }

    /** @param list<mixed> $args
     * @param mixed $returnValue */
    #[DataProvider('methodExpectationProvider')]
    public function testAdapterMethods(string $methodName, ?string $expectedException, $returnValue, array $args): void
    {
        $reflectionStub = $this->createMock(BetterReflectionIntersectionType::class);

        if ($expectedException === null) {
            $reflectionStub->expects($this->once())
                ->method($methodName)
                ->with(...$args)
                ->will($this->returnValue($returnValue));
        }

        if ($expectedException !== null) {
            $this->expectException($expectedException);
        }

        $adapter = new ReflectionIntersectionTypeAdapter($reflectionStub);
        $adapter->{$methodName}(...$args);
    }

    public function testGetTypes(): void
    {
        $betterReflectionType1 = $this->createMock(BetterReflectionNamedType::class);
        $betterReflectionType2 = $this->createMock(BetterReflectionNamedType::class);

        $betterReflectionIntersectionType = $this->createMock(BetterReflectionIntersectionType::class);
        $betterReflectionIntersectionType
            ->method('getTypes')
            ->willReturn([
                $betterReflectionType1,
                $betterReflectionType2,
            ]);

        $reflectionUnionTypeAdapter = new ReflectionIntersectionTypeAdapter($betterReflectionIntersectionType);

        self::assertContainsOnlyInstancesOf(ReflectionNamedTypeAdapter::class, $reflectionUnionTypeAdapter->getTypes());
    }
}
