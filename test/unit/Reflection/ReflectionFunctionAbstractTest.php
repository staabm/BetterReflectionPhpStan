<?php

declare(strict_types=1);

namespace Roave\BetterReflectionTest\Reflection;

use PhpParser\Node\Stmt\Function_;
use PhpParser\Parser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass as CoreReflectionClass;
use PHPStan\BetterReflection\Reflection\Exception\CodeLocationMissing;
use PHPStan\BetterReflection\Reflection\ReflectionFunction;
use PHPStan\BetterReflection\Reflection\ReflectionFunctionAbstract;
use PHPStan\BetterReflection\Reflection\ReflectionParameter;
use PHPStan\BetterReflection\Reflection\ReflectionType;
use PHPStan\BetterReflection\Reflector\DefaultReflector;
use PHPStan\BetterReflection\SourceLocator\Ast\Locator;
use PHPStan\BetterReflection\SourceLocator\Located\LocatedSource;
use PHPStan\BetterReflection\SourceLocator\SourceStubber\ReflectionSourceStubber;
use PHPStan\BetterReflection\SourceLocator\Type\AggregateSourceLocator;
use PHPStan\BetterReflection\SourceLocator\Type\ClosureSourceLocator;
use PHPStan\BetterReflection\SourceLocator\Type\PhpInternalSourceLocator;
use PHPStan\BetterReflection\SourceLocator\Type\SingleFileSourceLocator;
use PHPStan\BetterReflection\SourceLocator\Type\StringSourceLocator;
use Roave\BetterReflectionTest\BetterReflectionSingleton;
use Roave\BetterReflectionTest\Fixture\Attr;
use Roave\BetterReflectionTest\Fixture\StringEnum;
use stdClass;

use function sprintf;

#[CoversClass(ReflectionFunctionAbstract::class)]
class ReflectionFunctionAbstractTest extends TestCase
{
    /**
     * @var \PhpParser\Parser
     */
    private $parser;

    /**
     * @var \PHPStan\BetterReflection\SourceLocator\Ast\Locator
     */
    private $astLocator;

    protected function setUp(): void
    {
        parent::setUp();

        $configuration    = BetterReflectionSingleton::instance();
        $this->parser     = $configuration->phpParser();
        $this->astLocator = $configuration->astLocator();
    }

    public function testNameMethodsWithNamespace(): void
    {
        $php = '<?php namespace Foo { function bar() {}}';

        $reflector    = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $functionInfo = $reflector->reflectFunction('Foo\bar');

        self::assertSame('Foo\bar', $functionInfo->getName());
        self::assertTrue($functionInfo->inNamespace());
        self::assertSame('Foo', $functionInfo->getNamespaceName());
        self::assertSame('bar', $functionInfo->getShortName());
    }

    public function testNameMethodsWithoutNamespace(): void
    {
        $php = '<?php function foo() {}';

        $reflector    = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $functionInfo = $reflector->reflectFunction('foo');

        self::assertSame('foo', $functionInfo->getName());
        self::assertFalse($functionInfo->inNamespace());
        self::assertNull($functionInfo->getNamespaceName());
        self::assertSame('foo', $functionInfo->getShortName());
    }

    public function testNameMethodsInRootNamespace(): void
    {
        $php = '<?php namespace { function foo() {} }';

        $reflector    = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $functionInfo = $reflector->reflectFunction('foo');

        self::assertSame('foo', $functionInfo->getName());
        self::assertFalse($functionInfo->inNamespace());
        self::assertNull($functionInfo->getNamespaceName());
        self::assertSame('foo', $functionInfo->getShortName());
    }

    public function testNameMethodsWithClosure(): void
    {
        $functionInfo = (new DefaultReflector(new ClosureSourceLocator(static function (): void {
        }, $this->parser)))->reflectFunction('foo');

        self::assertSame('Roave\BetterReflectionTest\Reflection\\' . ReflectionFunction::CLOSURE_NAME, $functionInfo->getName());
        self::assertSame('Roave\BetterReflectionTest\Reflection', $functionInfo->getNamespaceName());
        self::assertSame(ReflectionFunction::CLOSURE_NAME, $functionInfo->getShortName());
    }

    public function testIsClosureWithRegularFunction(): void
    {
        $php = '<?php function foo() {}';

        $reflector = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $function  = $reflector->reflectFunction('foo');

        self::assertFalse($function->isClosure());
    }

    public function testIsClosureWithClosure(): void
    {
        $function = (new DefaultReflector(new ClosureSourceLocator(static function (): void {
        }, $this->parser)))->reflectFunction(ReflectionFunction::CLOSURE_NAME);

        self::assertTrue($function->isClosure());
    }

    public function testIsClosureWithArrowFunction(): void
    {
        $function = (new DefaultReflector(new ClosureSourceLocator(static function () : bool {
            return true;
        }, $this->parser)))->reflectFunction(ReflectionFunction::CLOSURE_NAME);

        self::assertTrue($function->isClosure());
    }

    #[DataProvider('nonDeprecatedProvider')]
    public function testIsDeprecated(string $comment): void
    {
        $php = sprintf('<?php
        %s
        function foo() {}', $comment);

        $reflector = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $function  = $reflector->reflectFunction('foo');

        self::assertFalse($function->isDeprecated());
    }

    /** @return list<array{0: string}> */
    public static function nonDeprecatedProvider(): array
    {
        return [
            [''],
            [
                '/**
                  * @deprecatedPolicy
                  */',
            ],
        ];
    }

    public function testIsInternal(): void
    {
        $php = '<?php function foo() {}';

        $reflector = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $function  = $reflector->reflectFunction('foo');

        self::assertFalse($function->isInternal());
        self::assertTrue($function->isUserDefined());
        self::assertNull($function->getExtensionName());
    }

    /** @return list<array{0: non-empty-string, 1: bool}> */
    public static function variadicProvider(): array
    {
        return [
            ['<?php function foo($notVariadic) {}', false],
            ['<?php function foo(...$isVariadic) {}', true],
            ['<?php function foo($notVariadic, ...$isVariadic) {}', true],
        ];
    }

    /** @param non-empty-string $php */
    #[DataProvider('variadicProvider')]
    public function testIsVariadic(string $php, bool $expectingVariadic): void
    {
        $reflector = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $function  = $reflector->reflectFunction('foo');

        self::assertSame($expectingVariadic, $function->isVariadic());
    }

    /**
     * These generator tests were taken from nikic/php-parser - so a big thank
     * you and credit to @nikic for this (and the awesome PHP-Parser library).
     *
     * @see https://github.com/nikic/PHP-Parser/blob/1.x/test/code/parser/stmt/function/generator.test
     *
     * @return list<array{0: non-empty-string, 1: bool}>
     */
    public static function generatorProvider(): array
    {
        return [
            ['<?php function foo() { return [1, 2, 3]; }', false],
            ['<?php function foo() { yield; }', true],
            ['<?php function foo() { yield $value; }', true],
            ['<?php function foo() { yield $key => $value; }', true],
            ['<?php function foo() { $data = yield; }', true],
            ['<?php function foo() { $data = (yield $value); }', true],
            ['<?php function foo() { $data = (yield $key => $value); }', true],
            ['<?php function foo() { if (yield $foo); elseif (yield $foo); }', true],
            ['<?php function foo() { if (yield $foo): elseif (yield $foo): endif; }', true],
            ['<?php function foo() { while (yield $foo); }', true],
            ['<?php function foo() { do {} while (yield $foo); }', true],
            ['<?php function foo() { switch (yield $foo) {} }', true],
            ['<?php function foo() { die(yield $foo); }', true],
            ['<?php function foo() { func(yield $foo); }', true],
            ['<?php function foo() { $foo->func(yield $foo); }', true],
            ['<?php function foo() { new Foo(yield $foo); }', true],
            ['<?php function foo() { yield from []; }', true],
        ];
    }

    /** @param non-empty-string $php */
    #[DataProvider('generatorProvider')]
    public function testIsGenerator(string $php, bool $expectingGenerator): void
    {
        $reflector = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $function  = $reflector->reflectFunction('foo');

        self::assertSame($expectingGenerator, $function->isGenerator());
    }

    /** @return list<array{0: non-empty-string, 1: int, 2: int}> */
    public static function startEndLineProvider(): array
    {
        return [
            ["<?php\n\nfunction foo() {\n}\n", 3, 4],
            ["<?php\n\nfunction foo() {\n\n}\n", 3, 5],
            ["<?php\n\n\nfunction foo() {\n}\n", 4, 5],
        ];
    }

    /** @param non-empty-string $php */
    #[DataProvider('startEndLineProvider')]
    public function testStartEndLine(string $php, int $expectedStart, int $expectedEnd): void
    {
        $reflector = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $function  = $reflector->reflectFunction('foo');

        self::assertSame($expectedStart, $function->getStartLine());
        self::assertSame($expectedEnd, $function->getEndLine());
    }

    /** @return list<array{0: non-empty-string, 1: int, 2: int}> */
    public static function columnsProvider(): array
    {
        return [
            ["<?php\n\nfunction foo() {\n}\n", 1, 1],
            ["<?php\n\n    function foo() {\n    }\n", 5, 5],
            ['<?php function foo() { }', 7, 24],
        ];
    }

    /** @param non-empty-string $php */
    #[DataProvider('columnsProvider')]
    public function testGetStartColumnAndEndColumn(string $php, int $startColumn, int $endColumn): void
    {
        $reflector = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $function  = $reflector->reflectFunction('foo');

        self::assertSame($startColumn, $function->getStartColumn());
        self::assertSame($endColumn, $function->getEndColumn());
    }

    public function testGetStartLineThrowsExceptionForMagicallyAddedEnumMethod(): void
    {
        $reflector = new DefaultReflector(new AggregateSourceLocator([
            new SingleFileSourceLocator(__DIR__ . '/../Fixture/Enums.php', $this->astLocator),
            BetterReflectionSingleton::instance()->sourceLocator(),
        ]));

        $classReflection  = $reflector->reflectClass(StringEnum::class);
        $methodReflection = $classReflection->getMethod('tryFrom');

        $this->expectException(CodeLocationMissing::class);
        $methodReflection->getStartLine();
    }

    public function testGetEndLineThrowsExceptionForMagicallyAddedEnumMethod(): void
    {
        $reflector = new DefaultReflector(new AggregateSourceLocator([
            new SingleFileSourceLocator(__DIR__ . '/../Fixture/Enums.php', $this->astLocator),
            BetterReflectionSingleton::instance()->sourceLocator(),
        ]));

        $classReflection  = $reflector->reflectClass(StringEnum::class);
        $methodReflection = $classReflection->getMethod('tryFrom');

        $this->expectException(CodeLocationMissing::class);
        $methodReflection->getEndLine();
    }

    /** @return list<array{0: non-empty-string, 1: bool}> */
    public static function returnsReferenceProvider(): array
    {
        return [
            ['<?php function foo() {}', false],
            ['<?php function &foo() {}', true],
        ];
    }

    /** @param non-empty-string $php */
    #[DataProvider('returnsReferenceProvider')]
    public function testReturnsReference(string $php, bool $expectingReturnsReference): void
    {
        $reflector = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $function  = $reflector->reflectFunction('foo');

        self::assertSame($expectingReturnsReference, $function->returnsReference());
    }

    public function testGetDocCommentWithComment(): void
    {
        $php = '<?php
        /* --- This is a separator --------------- */

        /**
         * Unused function comment
         */
        /** This function comment should be used. */
        function foo() {}
        ';

        $reflector    = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $functionInfo = $reflector->reflectFunction('foo');

        self::assertStringContainsString('This function comment should be used.', $functionInfo->getDocComment());
    }

    public function testGetDocReturnsNullWithNoComment(): void
    {
        $php = '<?php function foo() {}';

        $reflector    = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $functionInfo = $reflector->reflectFunction('foo');

        self::assertNull($functionInfo->getDocComment());
    }

    public function testGetNumberOfParameters(): void
    {
        $php = '<?php function foo($a, $b, $c = 1) {}';

        $reflector    = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $functionInfo = $reflector->reflectFunction('foo');

        self::assertSame(3, $functionInfo->getNumberOfParameters());
        self::assertSame(2, $functionInfo->getNumberOfRequiredParameters());
    }

    public function testGetParameter(): void
    {
        $php = '<?php function foo($a, $b, $c = 1) {}';

        $reflector    = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $functionInfo = $reflector->reflectFunction('foo');

        $paramInfo = $functionInfo->getParameter('a');

        self::assertInstanceOf(ReflectionParameter::class, $paramInfo);
        self::assertSame('a', $paramInfo->getName());
    }

    public function testGetParameterReturnsNullWhenNotFound(): void
    {
        $php = '<?php function foo($a, $b, $c = 1) {}';

        $reflector    = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $functionInfo = $reflector->reflectFunction('foo');

        self::assertNull($functionInfo->getParameter('d'));
    }

    public function testGetParameters(): void
    {
        $php = '<?php function foo($c, $b, $a) {}';

        $reflector    = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $functionInfo = $reflector->reflectFunction('foo');
        $parameters   = $functionInfo->getParameters();

        $expectedParameters = ['c', 'b', 'a'];

        foreach ($parameters as $parameterNo => $parameter) {
            self::assertSame($expectedParameters[$parameterNo], $parameter->getName());
        }
    }

    public function testGetFileName(): void
    {
        $functionInfo = (new DefaultReflector(new SingleFileSourceLocator(__DIR__ . '/../Fixture/Functions.php', $this->astLocator)))->reflectFunction('Roave\BetterReflectionTest\Fixture\myFunction');

        self::assertStringContainsString('Fixture/Functions.php', $functionInfo->getFileName());
    }

    public function testGetFileNameOfUnlocatedSource(): void
    {
        $php = '<?php function foo() {}';

        $functionInfo = (new DefaultReflector(new StringSourceLocator($php, $this->astLocator)))->reflectFunction('foo');

        self::assertNull($functionInfo->getFileName());
    }

    public function testGetLocatedSource(): void
    {
        $node          = new Function_('foo');
        $locatedSource = new LocatedSource('<?php function foo() {}', 'foo');
        $reflector     = new DefaultReflector(new StringSourceLocator('<?php', $this->astLocator));
        $functionInfo  = ReflectionFunction::createFromNode($reflector, $node, $locatedSource);

        self::assertSame($locatedSource, $functionInfo->getLocatedSource());
    }

    /** @return list<array{0: string, 1: string|class-string}> */
    public static function returnTypeFunctionProvider(): array
    {
        return [
            ['returnsInt', 'int'],
            ['returnsString', 'string'],
            ['returnsNull', 'null'],
            ['returnsObject', stdClass::class],
            ['returnsVoid', 'void'],
        ];
    }

    #[DataProvider('returnTypeFunctionProvider')]
    public function testGetReturnTypeWithDeclaredType(string $functionToReflect, string $expectedType): void
    {
        $functionInfo = (new DefaultReflector(new SingleFileSourceLocator(__DIR__ . '/../Fixture/Php7ReturnTypeDeclarations.php', $this->astLocator)))->reflectFunction($functionToReflect);

        $reflectionType = $functionInfo->getReturnType();
        self::assertInstanceOf(ReflectionType::class, $reflectionType);
        self::assertSame($expectedType, (string) $reflectionType);
    }

    public function testGetReturnTypeReturnsNullWhenTypeIsNotDeclared(): void
    {
        $functionInfo = (new DefaultReflector(new SingleFileSourceLocator(__DIR__ . '/../Fixture/Php7ReturnTypeDeclarations.php', $this->astLocator)))->reflectFunction('returnsNothing');

        self::assertNull($functionInfo->getReturnType());
    }

    public function testHasReturnTypeWhenTypeDeclared(): void
    {
        $functionInfo = (new DefaultReflector(new SingleFileSourceLocator(__DIR__ . '/../Fixture/Php7ReturnTypeDeclarations.php', $this->astLocator)))->reflectFunction('returnsString');

        self::assertTrue($functionInfo->hasReturnType());
    }

    public function testHasReturnTypeWhenTypeIsNotDeclared(): void
    {
        $functionInfo = (new DefaultReflector(new SingleFileSourceLocator(__DIR__ . '/../Fixture/Php7ReturnTypeDeclarations.php', $this->astLocator)))->reflectFunction('returnsNothing');

        self::assertFalse($functionInfo->hasReturnType());
    }

    /** @return list<array{0: string, 1: string}> */
    public static function nullableReturnTypeFunctionProvider(): array
    {
        return [
            ['returnsNullableInt', 'int|null'],
            ['returnsNullableString', 'string|null'],
            ['returnsNullableObject', stdClass::class . '|null'],
        ];
    }

    #[DataProvider('nullableReturnTypeFunctionProvider')]
    public function testGetNullableReturnTypeWithDeclaredType(string $functionToReflect, string $expectedType): void
    {
        $functionInfo = (new DefaultReflector(new SingleFileSourceLocator(__DIR__ . '/../Fixture/Php71NullableReturnTypeDeclarations.php', $this->astLocator)))->reflectFunction($functionToReflect);

        $reflectionType = $functionInfo->getReturnType();
        self::assertInstanceOf(ReflectionType::class, $reflectionType);
        self::assertSame($expectedType, (string) $reflectionType);
        self::assertTrue($reflectionType->allowsNull());
    }

    public function testHasTentativeReturnType(): void
    {
        $classInfo  = (new DefaultReflector(new PhpInternalSourceLocator($this->astLocator, new ReflectionSourceStubber())))->reflectClass(CoreReflectionClass::class);
        $methodInfo = $classInfo->getMethod('getName');

        self::assertTrue($methodInfo->hasTentativeReturnType());
        self::assertFalse($methodInfo->hasReturnType());
    }

    public function testHasNotTentativeReturnType(): void
    {
        $functionInfo = (new DefaultReflector(new SingleFileSourceLocator(__DIR__ . '/../Fixture/Php7ReturnTypeDeclarations.php', $this->astLocator)))->reflectFunction('returnsString');

        self::assertFalse($functionInfo->hasTentativeReturnType());
        self::assertTrue($functionInfo->hasReturnType());
    }

    public function testGetTentativeReturnType(): void
    {
        $classInfo  = (new DefaultReflector(new PhpInternalSourceLocator($this->astLocator, new ReflectionSourceStubber())))->reflectClass(CoreReflectionClass::class);
        $methodInfo = $classInfo->getMethod('getName');

        $returnType = $methodInfo->getTentativeReturnType();

        self::assertNotNull($returnType);
        self::assertSame('string', $returnType->__toString());
        self::assertNull($methodInfo->getReturnType());
    }

    public function testNoTentativeReturnType(): void
    {
        $functionInfo = (new DefaultReflector(new SingleFileSourceLocator(__DIR__ . '/../Fixture/Php7ReturnTypeDeclarations.php', $this->astLocator)))->reflectFunction('returnsString');

        self::assertNull($functionInfo->getTentativeReturnType());
        self::assertNotNull($functionInfo->getReturnType());
    }

    #[DataProvider('deprecatedDocCommentsProvider')]
    public function testFunctionsCanBeDeprecated(string $comment): void
    {
        $php = sprintf('<?php
        %s
        function foo() {}', $comment);

        $reflector = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $function  = $reflector->reflectFunction('foo');

        self::assertTrue($function->isDeprecated());
    }

    /** @return list<array{0: string}> */
    public static function deprecatedDocCommentsProvider(): array
    {
        return [
            [
                '/**
                  * @deprecated since 7.1
                  */',
            ],
            [
                '/**
                  * @deprecated
                  */',
            ],
        ];
    }

    public function testGetAttributesWithAttributes(): void
    {
        $reflector          = new DefaultReflector(new SingleFileSourceLocator(__DIR__ . '/../Fixture/Attributes.php', $this->astLocator));
        $functionReflection = $reflector->reflectFunction('Roave\BetterReflectionTest\Fixture\functionWithAttributes');
        $attributes         = $functionReflection->getAttributes();

        self::assertCount(2, $attributes);
    }

    public function testGetAttributesByName(): void
    {
        $reflector          = new DefaultReflector(new SingleFileSourceLocator(__DIR__ . '/../Fixture/Attributes.php', $this->astLocator));
        $functionReflection = $reflector->reflectFunction('Roave\BetterReflectionTest\Fixture\functionWithAttributes');
        $attributes         = $functionReflection->getAttributesByName(Attr::class);

        self::assertCount(1, $attributes);
    }

    public function testGetAttributesByInstance(): void
    {
        $reflector          = new DefaultReflector(new SingleFileSourceLocator(__DIR__ . '/../Fixture/Attributes.php', $this->astLocator));
        $functionReflection = $reflector->reflectFunction('Roave\BetterReflectionTest\Fixture\functionWithAttributes');
        $attributes         = $functionReflection->getAttributesByInstance(Attr::class);

        self::assertCount(2, $attributes);
    }

    public function testCouldThrow(): void
    {
        $php       = <<<'PHP'
        <?php
        function foo($a) {
            if ($a === null) {
                throw new Exception('Invalid a!');
            }
        }
        PHP;
        $reflector = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $function  = $reflector->reflectFunction('foo');
        self::assertTrue($function->couldThrow());

        $php       = <<<'PHP'
        <?php
        function foo(object $obj) {
            echo $obj instanceof Throwable ? 'throw' : 'not throw';
        }
        PHP;
        $reflector = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $function  = $reflector->reflectFunction('foo');
        self::assertNotTrue($function->couldThrow());
    }

    public function testCouldThrowForAbstractMethod(): void
    {
        $php = <<<'PHP'
        <?php

        abstract class Foo
        {
            abstract public function foo();
        }

        PHP;

        $reflector        = new DefaultReflector(new StringSourceLocator($php, $this->astLocator));
        $classReflection  = $reflector->reflectClass('Foo');
        $methodReflection = $classReflection->getMethod('foo');

        self::assertFalse($methodReflection->couldThrow());
    }
}
