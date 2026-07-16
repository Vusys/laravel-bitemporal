<?php

declare(strict_types=1);

namespace Vusys\Bitemporal\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use SplFileInfo;
use Vusys\Bitemporal\Exceptions\TemporalDomainException;
use Vusys\Bitemporal\Exceptions\TemporalException;

/**
 * Full parity scan between the exception catalogue (classes + factory methods),
 * their throw sites in src/, the published doc, and the lang templates.
 *
 * The scan is entirely reflection- and filesystem-driven — no exception class is
 * hand-listed here — so adding an exception (or a factory) without cataloguing,
 * documenting, or throwing it fails CI automatically. Runs in the default SQLite
 * suite; no database engine is touched.
 */
final class ExceptionCatalogueParityTest extends TestCase
{
    private const SRC_DIR = __DIR__.'/../../src';

    private const EXCEPTIONS_DIR = self::SRC_DIR.'/Exceptions';

    private const EXCEPTIONS_NAMESPACE = 'Vusys\\Bitemporal\\Exceptions\\';

    private const DOC = __DIR__.'/../../docs/09a-exception-catalogue.md';

    private const LANG = __DIR__.'/../../lang/en/messages.php';

    /**
     * Factory methods deliberately defined ahead of their throw sites. Each entry
     * MUST carry a justification, and is removed the moment the throw site lands —
     * a stale entry (now-called, or non-existent) fails
     * {@see test_reserved_factory_list_has_no_stale_entries}.
     *
     * @var array<string, string>
     */
    private const RESERVED_FACTORIES = [
        'TemporalDomainException::invariant' => 'defensive "should never happen" assertion; kept ready for algorithm invariants even when no site currently trips it',
        'TemporalWriteConflictException::clockRegressed' => 'reserved for the clock-regression write guard',
        'TemporalUnsupportedDatabaseException::advisoryLocksUnsupported' => 'wired by #16/#17 (engine grammars + advisory-lock fallback)',
        'TemporalUnsupportedDatabaseException::engineVersionBelowMinimum' => 'wired by #13/#16 (engine-version boot guard)',
    ];

    /**
     * Every discovered exception class maps to exactly one lang section. Adding an
     * exception without registering its section here fails
     * {@see test_every_exception_class_has_a_lang_section}.
     *
     * @var array<string, string>
     */
    private const LANG_SECTIONS = [
        'TemporalConfigurationException' => 'configuration',
        'TemporalInvalidSpellException' => 'invalid_period',
        'TemporalMissingDimensionException' => 'missing_dimension',
        'TemporalOverlapException' => 'overlap',
        'TemporalCardinalityException' => 'cardinality',
        'TemporalWriteConflictException' => 'write_conflict',
        'TemporalUnsupportedDatabaseException' => 'unsupported_database',
        'TemporalDomainException' => 'domain',
    ];

    // --- discovery helpers ---------------------------------------------------

    /**
     * Discover every concrete Temporal exception by reflecting over the Exceptions
     * directory — never a hand-maintained array.
     *
     * @return list<class-string<TemporalException>>
     */
    private static function discoverExceptionClasses(): array
    {
        $classes = [];

        foreach (glob(self::EXCEPTIONS_DIR.'/*.php') ?: [] as $file) {
            /** @var class-string $class */
            $class = self::EXCEPTIONS_NAMESPACE.basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $ref = new ReflectionClass($class);

            if ($ref->isAbstract() || ! $ref->isSubclassOf(TemporalException::class)) {
                continue; // the abstract base itself
            }

            /** @var class-string<TemporalException> $class */
            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    /**
     * Public static factory methods declared on the class that return an instance
     * of it (excludes accessors like wasNoneFound()).
     *
     * @param  class-string  $class
     * @return list<string>
     */
    private static function factoryMethods(string $class): array
    {
        $ref = new ReflectionClass($class);
        $factories = [];

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! $method->isStatic() || $method->getDeclaringClass()->getName() !== $class) {
                continue;
            }

            $type = $method->getReturnType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

            if (in_array($typeName, ['self', 'static', $class], true)) {
                $factories[] = $method->getName();
            }
        }

        sort($factories);

        return $factories;
    }

    /**
     * Concatenated contents of every PHP file under src/ (throw-site scan target).
     */
    private static function srcBlob(): string
    {
        static $blob = null;

        if ($blob !== null) {
            return $blob;
        }

        $blob = '';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SRC_DIR, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $blob .= (string) file_get_contents($file->getPathname())."\n";
            }
        }

        return $blob;
    }

    /** @return array<int, array{0: class-string<TemporalException>}> */
    public static function discoveredClasses(): array
    {
        return array_map(static fn (string $c): array => [$c], self::discoverExceptionClasses());
    }

    // --- static invariants ---------------------------------------------------

    public function test_discovery_finds_the_catalogue(): void
    {
        $classes = self::discoverExceptionClasses();

        $this->assertGreaterThanOrEqual(8, count($classes), 'expected at least the 8 catalogued exceptions');
        $this->assertNotContains(TemporalException::class, $classes, 'the abstract base must be excluded');
    }

    /**
     * @param  class-string<TemporalException>  $class
     */
    #[DataProvider('discoveredClasses')]
    public function test_every_exception_is_final_baseless_and_factory_built(string $class): void
    {
        $ref = new ReflectionClass($class);

        $this->assertTrue($ref->isFinal(), "{$class} must be final");
        $this->assertTrue($ref->isSubclassOf(TemporalException::class), "{$class} must extend TemporalException");

        // The catalogue design routes all construction through named factories
        // (`new self(...)` on the inherited RuntimeException constructor). What we
        // enforce is that no subclass bolts on its *own* public constructor and
        // thereby re-opens a second construction path around the factories.
        $ctor = $ref->getConstructor();
        $declaresOwnConstructor = $ctor !== null && $ctor->getDeclaringClass()->getName() === $class;
        $this->assertFalse(
            $declaresOwnConstructor && $ctor->isPublic(),
            "{$class} must not declare its own public constructor; construct via named factories",
        );

        $this->assertNotEmpty(
            self::factoryMethods($class),
            "{$class} must declare at least one public static factory method",
        );
    }

    /**
     * @param  class-string<TemporalException>  $class
     */
    #[DataProvider('discoveredClasses')]
    public function test_every_exception_class_has_a_lang_section(string $class): void
    {
        $short = (new ReflectionClass($class))->getShortName();

        $this->assertArrayHasKey(
            $short,
            self::LANG_SECTIONS,
            "{$short} has no lang section registered in LANG_SECTIONS; catalogue it",
        );

        /** @var array<string, mixed> $messages */
        $messages = require self::LANG;
        $section = self::LANG_SECTIONS[$short];

        $this->assertArrayHasKey($section, $messages, "lang/en/messages.php is missing the '{$section}' section for {$short}");
        $this->assertIsArray($messages[$section]);
        $this->assertNotEmpty($messages[$section], "lang section '{$section}' must not be empty");
    }

    // --- throw-site parity ---------------------------------------------------

    public function test_every_factory_is_thrown_somewhere_or_reserved(): void
    {
        $blob = self::srcBlob();
        $unused = [];

        foreach (self::discoverExceptionClasses() as $class) {
            $short = (new ReflectionClass($class))->getShortName();

            foreach (self::factoryMethods($class) as $method) {
                $key = "{$short}::{$method}";

                if (str_contains($blob, "{$short}::{$method}(")) {
                    continue;
                }

                if (! array_key_exists($key, self::RESERVED_FACTORIES)) {
                    $unused[] = $key;
                }
            }
        }

        $this->assertSame(
            [],
            $unused,
            'these catalogued factories are never thrown in src/ and are not on the RESERVED_FACTORIES allowlist: '.implode(', ', $unused),
        );
    }

    public function test_reserved_factory_list_has_no_stale_entries(): void
    {
        $blob = self::srcBlob();
        $stale = [];

        foreach (self::RESERVED_FACTORIES as $key => $_reason) {
            [$short, $method] = explode('::', $key);
            /** @var class-string $class */
            $class = self::EXCEPTIONS_NAMESPACE.$short;

            if (! class_exists($class) || ! in_array($method, self::factoryMethods($class), true)) {
                $stale[] = "{$key} (no such factory)";

                continue;
            }

            if (str_contains($blob, "{$short}::{$method}(")) {
                $stale[] = "{$key} (now thrown — remove from RESERVED_FACTORIES)";
            }
        }

        $this->assertSame([], $stale, 'RESERVED_FACTORIES has stale entries: '.implode(', ', $stale));
    }

    public function test_raw_throws_only_use_catalogued_exception_classes(): void
    {
        $blob = self::srcBlob();
        $known = array_map(
            static fn (string $c): string => (new ReflectionClass($c))->getShortName(),
            self::discoverExceptionClasses(),
        );

        preg_match_all('/new\s+(Temporal[A-Za-z]*Exception)\b/', $blob, $matches);

        foreach (array_unique($matches[1]) as $short) {
            $this->assertContains(
                $short,
                $known,
                "src/ throws {$short} which is not a catalogued exception class in src/Exceptions/",
            );
        }
    }

    // --- doc parity ----------------------------------------------------------

    public function test_doc_lists_every_class_and_factory(): void
    {
        $doc = (string) file_get_contents(self::DOC);

        foreach (self::discoverExceptionClasses() as $class) {
            $short = (new ReflectionClass($class))->getShortName();
            $this->assertStringContainsString($short, $doc, "{$short} is missing from docs/09a-exception-catalogue.md");

            foreach (self::factoryMethods($class) as $method) {
                $this->assertStringContainsString(
                    $method,
                    $doc,
                    "factory {$short}::{$method} is missing from docs/09a-exception-catalogue.md",
                );
            }
        }
    }

    public function test_lang_sections_exactly_match_the_catalogue(): void
    {
        /** @var array<string, mixed> $messages */
        $messages = require self::LANG;

        $expected = array_values(self::LANG_SECTIONS);
        sort($expected);

        $actual = array_keys($messages);
        sort($actual);

        $this->assertSame(
            $expected,
            $actual,
            'lang/en/messages.php top-level sections must correspond one-to-one with the exception catalogue',
        );
    }

    // --- behavioural spot checks (kept from the original test) ---------------

    public function test_domain_exception_factories_produce_documented_messages(): void
    {
        $this->assertStringContainsString(
            'Report this with reproduction',
            TemporalDomainException::invariant('post-commit overlap', 'BitemporalWriter')->getMessage(),
        );

        $this->assertStringContainsString(
            'Clock skew',
            TemporalDomainException::clockSkew('Model#1', 'a', 'b', 90000, 60000)->getMessage(),
        );
    }
}
