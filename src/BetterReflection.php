<?php

declare(strict_types=1);

namespace Roave\BetterReflection;

use PhpParser\Lexer\Emulative;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Roave\BetterReflection\Reflector\DefaultReflector;
use Roave\BetterReflection\Reflector\Reflector;
use Roave\BetterReflection\SourceLocator\Ast\Locator as AstLocator;
use Roave\BetterReflection\SourceLocator\SourceStubber\AggregateSourceStubber;
use Roave\BetterReflection\SourceLocator\SourceStubber\PhpStormStubsSourceStubber;
use Roave\BetterReflection\SourceLocator\SourceStubber\ReflectionSourceStubber;
use Roave\BetterReflection\SourceLocator\SourceStubber\SourceStubber;
use Roave\BetterReflection\SourceLocator\Type\AggregateSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\AutoloadSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\EvaledCodeSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\MemoizingSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\PhpInternalSourceLocator;
use Roave\BetterReflection\SourceLocator\Type\SourceLocator;
use Roave\BetterReflection\Util\FindReflectionOnLine;

use const PHP_VERSION_ID;

final class BetterReflection
{
    public static int $phpVersion = PHP_VERSION_ID;

    private static SourceLocator|null $sharedSourceLocator = null;

    private SourceLocator|null $sourceLocator = null;

    private static Reflector|null $sharedReflector = null;

    private Reflector|null $reflector = null;

    private static Parser|null $sharedPhpParser = null;

    private Parser|null $phpParser = null;

    private AstLocator|null $astLocator = null;

    private FindReflectionOnLine|null $findReflectionOnLine = null;

    private SourceStubber|null $sourceStubber = null;

    private static SourceStubber|null $sharedSourceStubber = null;

    /**
     * @var Standard|null
     */
    private static $sharedPrinter = null;

    /**
     * @var Standard|null
     */
    private $printer = null;

    public static function populate(
        int $phpVersion,
        SourceLocator $sourceLocator,
        Reflector $classReflector,
        Parser $phpParser,
        SourceStubber $sourceStubber,
        Standard $printer,
    ): void {
        self::$phpVersion          = $phpVersion;
        self::$sharedSourceLocator = $sourceLocator;
        self::$sharedReflector     = $classReflector;
        self::$sharedPhpParser     = $phpParser;
        self::$sharedSourceStubber = $sourceStubber;
        self::$sharedPrinter       = $printer;
    }

    public function __construct()
    {
        $this->sourceLocator = self::$sharedSourceLocator;
        $this->reflector     = self::$sharedReflector;
        $this->phpParser     = self::$sharedPhpParser;
        $this->sourceStubber = self::$sharedSourceStubber;
        $this->printer       = self::$sharedPrinter;
    }

    public function sourceLocator(): SourceLocator
    {
        $astLocator    = $this->astLocator();
        $sourceStubber = $this->sourceStubber();

        return $this->sourceLocator
            ?? $this->sourceLocator = new MemoizingSourceLocator(new AggregateSourceLocator([
                new PhpInternalSourceLocator($astLocator, $sourceStubber),
                new EvaledCodeSourceLocator($astLocator, $sourceStubber),
                new AutoloadSourceLocator($astLocator, $this->phpParser()),
            ]));
    }

    public function reflector(): Reflector
    {
        return $this->reflector
            ?? $this->reflector = new DefaultReflector($this->sourceLocator());
    }

    public function phpParser(): Parser
    {
        return $this->phpParser
            ?? $this->phpParser = (new ParserFactory())->create(ParserFactory::ONLY_PHP7, new Emulative([
                'usedAttributes' => ['comments', 'startLine', 'endLine', 'startFilePos', 'endFilePos'],
            ]));
    }

    public function astLocator(): AstLocator
    {
        return $this->astLocator
            ?? $this->astLocator = new AstLocator($this->phpParser());
    }

    public function findReflectionsOnLine(): FindReflectionOnLine
    {
        return $this->findReflectionOnLine
            ?? $this->findReflectionOnLine = new FindReflectionOnLine($this->sourceLocator(), $this->astLocator());
    }

    public function sourceStubber(): SourceStubber
    {
        return $this->sourceStubber
            ?? $this->sourceStubber = new AggregateSourceStubber(
                new PhpStormStubsSourceStubber($this->phpParser(), $this->printer(), self::$phpVersion),
                new ReflectionSourceStubber($this->printer()),
            );
    }

    public function printer(): Standard
    {
        return $this->printer ?? $this->printer = new Standard(['shortArraySyntax' => true]);
    }
}
