<?php

declare(strict_types=1);

namespace Roave\BetterReflectionTest\Reflection\StringCast;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPStan\BetterReflection\Reflection\StringCast\ReflectionConstantStringCast;
use PHPStan\BetterReflection\Reflector\DefaultReflector;
use PHPStan\BetterReflection\SourceLocator\Ast\Locator;
use PHPStan\BetterReflection\SourceLocator\SourceStubber\SourceStubber;
use PHPStan\BetterReflection\SourceLocator\Type\AggregateSourceLocator;
use PHPStan\BetterReflection\SourceLocator\Type\PhpInternalSourceLocator;
use PHPStan\BetterReflection\SourceLocator\Type\SingleFileSourceLocator;
use PHPStan\BetterReflection\SourceLocator\Type\StringSourceLocator;
use Roave\BetterReflectionTest\BetterReflectionSingleton;

#[CoversClass(ReflectionConstantStringCast::class)]
class ReflectionConstantStringCastTest extends TestCase
{
    /**
     * @var \PHPStan\BetterReflection\SourceLocator\Ast\Locator
     */
    private $astLocator;

    /**
     * @var \PHPStan\BetterReflection\SourceLocator\SourceStubber\SourceStubber
     */
    private $sourceStubber;

    protected function setUp(): void
    {
        parent::setUp();

        $betterReflection = BetterReflectionSingleton::instance();

        $this->astLocator    = $betterReflection->astLocator();
        $this->sourceStubber = $betterReflection->sourceStubber();
    }

    /** @return list<array{0: string, 1: string}> */
    public static function toStringProvider(): array
    {
        return [
            ['Roave\BetterReflectionTest\Fixture\BY_CONST', "Constant [ <user> boolean Roave\BetterReflectionTest\Fixture\BY_CONST ] {\n  @@ %s/Fixture/StringCastConstants.php 5 - 5\n 1 }"],
            ['Roave\BetterReflectionTest\Fixture\BY_CONST_1', "Constant [ <user> integer Roave\BetterReflectionTest\Fixture\BY_CONST_1 ] {\n  @@ %s/Fixture/StringCastConstants.php 6 - 7\n 1 }"],
            ['Roave\BetterReflectionTest\Fixture\BY_CONST_2', "Constant [ <user> integer Roave\BetterReflectionTest\Fixture\BY_CONST_2 ] {\n  @@ %s/Fixture/StringCastConstants.php 6 - 7\n 2 }"],
            ['Roave\BetterReflectionTest\Fixture\NEW_IN_INITIALIZER', "Constant [ <user> object Roave\BetterReflectionTest\Fixture\NEW_IN_INITIALIZER ] {\n  @@ %s/Fixture/StringCastConstants.php 9 - 9\n Object }"],
            ['BY_DEFINE', "Constant [ <user> string BY_DEFINE ] {\n  @@ %s/Fixture/StringCastConstants.php 11 - 11\n define }"],
            ['E_ALL', 'Constant [ <internal:Core> integer E_ALL ] { %d }'],
        ];
    }

    #[DataProvider('toStringProvider')]
    public function testToString(string $constantName, string $expectedString): void
    {
        $sourceLocator = new AggregateSourceLocator([
            new SingleFileSourceLocator(__DIR__ . '/../../Fixture/StringCastConstants.php', $this->astLocator),
            new PhpInternalSourceLocator($this->astLocator, $this->sourceStubber),
        ]);

        $reflector          = new DefaultReflector($sourceLocator);
        $constantReflection = $reflector->reflectConstant($constantName);

        self::assertStringMatchesFormat($expectedString, (string) $constantReflection);
    }

    public function testToStringWithNoFileName(): void
    {
        $php = '<?php const CONSTANT_TO_STRING_CAST = "string";';

        $reflector          = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $constantReflection = $reflector->reflectConstant('CONSTANT_TO_STRING_CAST');

        self::assertSame('Constant [ <user> string CONSTANT_TO_STRING_CAST ] { string }', (string) $constantReflection);
    }
}
