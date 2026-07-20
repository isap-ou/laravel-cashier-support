<?php

declare(strict_types=1);

namespace Isapp\CashierSupport\Tests\Feature;

use FilesystemIterator;
use Isapp\CashierSupport\Tests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * What we ship must import only what `composer.json` declares.
 *
 * A `use` on a class from an undeclared package is not a warning, it is a **fatal at
 * class load**: the package installs cleanly and then dies the first time anything
 * touches the class. Composer cannot catch it — it only reads composer.json, never our
 * imports — so nothing but a test stands between us and shipping that.
 *
 * This is #43, and #43 exists because the same gap was already found once, written into
 * a PR body (#41), and untracked the moment that PR merged. Prose about a dependency
 * cannot fail. This can.
 *
 * Scope is everything the service provider loads or publishes, not just `src/`:
 * `routes/webhook.php` is required at boot (`CashierSupportServiceProvider::boot()`) and
 * `database/migrations/` is published into the host app, so an undeclared import in
 * either is the same fatal in a different file.
 *
 * The direction is deliberate: imports ⊆ declared, not equality. `illuminate/routing` is
 * declared and never imported — routes/webhook.php reaches it through the `Route` facade,
 * and a declared-but-unimported package is honest, merely generous.
 *
 * **The blind spot is closed by failing, not by cleverness.** Reading `use` statements
 * with a regex means every form the regex does not anticipate — grouped, function/const
 * imports, something PHP grows later — would pass silently, which is precisely the way
 * this test would betray the issue that motivated it. So every `use` mentioning
 * Illuminate must be *recognised*, and one that is not fails loudly rather than quietly
 * opting out. What genuinely escapes is an inline `\Illuminate\…`, which is not an import
 * at all; `.claude/rules/coding-standards.md` forbids those ("Imports via `use`, never
 * FQCN inline").
 *
 * @see ExceptionBoundaryTest for the same shape — sweep what ships, inclusion by
 *      default, no allowlist to escape through.
 */
class DeclaredDependenciesTest extends TestCase
{
    /**
     * The root segment of an `Illuminate\…` import names the subsplit that provides it —
     * `Illuminate\Queue\…` → `illuminate/queue` — and `laravel/framework` `replace`s them
     * all, which is exactly why a real app never notices a missing one and only this test
     * can.
     *
     * That rule is a convention, not a law, and it is worth knowing where it bends: the
     * `Illuminate\Support\` namespace is served by *five* packages, not one —
     * `illuminate/support` itself plus `collections`, `macroable`, `conditionable` and
     * `reflection` (verified on packagist; `illuminate/macroable` autoloads
     * `Illuminate\Support\` and ships `Illuminate\Support\Traits\Macroable`, which
     * `src/CashierManager.php` imports). Requiring `illuminate/support` is therefore
     * *sufficient* rather than *exact*: it requires the other four, so the closure covers
     * the import either way. Nothing else we import bends it.
     */
    private const NAMESPACE_ROOT_IS_THE_PACKAGE = 'illuminate/';

    public function test_every_illuminate_package_shipped_code_imports_is_declared(): void
    {
        $declared = $this->declaredPackages();
        $missing = [];

        foreach ($this->illuminateImports() as $package => $evidence) {
            if (! in_array($package, $declared, true)) {
                $missing[$package] = $evidence;
            }
        }

        // Reported together, not one per run: with two gaps open, an assertion inside the
        // loop showed only the first and hid the second until it was fixed — which is how
        // this very change was written, and one round-trip per gap is a poor guard.
        $this->assertSame(
            [],
            $missing,
            "Shipped code imports from Illuminate packages that composer.json does not require:\n"
            .implode("\n", array_map(
                static fn (string $package, string $why): string => "  [{$package}] — {$why}",
                array_keys($missing),
                $missing
            ))
            ."\nA use on a class from an undeclared package is a fatal at class load. "
            .'Either declare the package, or stop importing from it.'
        );
    }

    public function test_every_symfony_package_shipped_code_imports_is_declared(): void
    {
        // The same sweep, one vendor over — added because the Illuminate-only version let
        // `symfony/console` ship undeclared for as long as it existed. `src/Console/
        // WebhookCommand.php` imports `Symfony\Component\Console\Attribute\AsCommand` and
        // nothing required the package; it loaded only because `illuminate/console` happens
        // to pull it in. A transitive dependency is not a declared one — the day that
        // package drops it, this becomes #43's fatal in a different file.
        //
        // `Symfony\Component\<Name>` → `symfony/<kebab-name>` is the same
        // namespace-root-is-the-package rule, and Symfony holds to it exactly.
        $declared = $this->declaredPackages();
        $missing = [];

        foreach ($this->symfonyImports() as $package => $evidence) {
            if (! in_array($package, $declared, true)) {
                $missing[$package] = $evidence;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Shipped code imports from Symfony packages that composer.json does not require:\n"
            .implode("\n", array_map(
                static fn (string $package, string $why): string => "  [{$package}] — {$why}",
                array_keys($missing),
                $missing
            ))
            ."\nA transitive install is not a declaration. Either require the package, or stop importing from it."
        );
    }

    public function test_every_php_extension_shipped_code_needs_is_declared(): void
    {
        // The third way the same fatal arrives, and the one no vendor-namespace sweep can
        // see: a global class that a PHP *extension* provides. `Cashier::formatAmount()`
        // builds a `NumberFormatter` (`src/CashierManager.php`) — that class exists only
        // when ext-intl is compiled in, and `moneyphp/money` merely *suggests* it. A
        // consumer on a build without intl installs cleanly and fatals on the first call.
        //
        // Asked of the runtime rather than a hand-kept list: Reflection knows which
        // extension declares a class, so a newly imported one is classified without anyone
        // remembering to teach this test about it.
        $declared = $this->declaredPackages();
        $missing = [];

        foreach ($this->extensionsUsed() as $extension => $evidence) {
            if (! in_array($extension, $declared, true)) {
                $missing[$extension] = $evidence;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "Shipped code needs PHP extensions that composer.json does not require:\n"
            .implode("\n", array_map(
                static fn (string $extension, string $why): string => "  [{$extension}] — {$why}",
                array_keys($missing),
                $missing
            ))
            ."\nComposer can refuse to install on a host without the extension, but only if we say we need it."
        );
    }

    /**
     * Every `symfony/*` package our shipped code imports from, mapped to one import that
     * proves it.
     *
     * @return array<string, string>
     */
    private function symfonyImports(): array
    {
        $found = [];

        foreach ($this->shippedFiles() as $file) {
            preg_match_all(
                '/^use\s+\\\\?Symfony\\\\Component\\\\([A-Za-z0-9_]+)\\\\[A-Za-z0-9_\\\\]+\s*(?:as\s+[A-Za-z0-9_]+\s*)?;$/m',
                (string) file_get_contents($file),
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                // HttpFoundation → http-foundation, Console → console.
                $package = 'symfony/'.strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $match[1]));

                $found[$package] ??= $match[0].' ('.basename($file).')';
            }
        }

        return $found;
    }

    /**
     * Every `ext-*` our shipped code needs, mapped to the import that proves it.
     *
     * Only unqualified imports are candidates — `use NumberFormatter;` — because that is
     * what a global, extension-provided class looks like in a namespaced file. Classes from
     * extensions that are always compiled in are not worth declaring; the rest are.
     *
     * @return array<string, string>
     */
    private function extensionsUsed(): array
    {
        // Bundled and non-optional in every supported PHP build, so requiring them says
        // nothing. Composer's own platform checks treat them the same way.
        $alwaysPresent = ['Core', 'standard', 'SPL', 'date', 'pcre', 'Reflection', 'json'];

        $found = [];

        foreach ($this->shippedFiles() as $file) {
            preg_match_all(
                '/^use\s+\\\\?([A-Za-z0-9_]+)\s*(?:as\s+[A-Za-z0-9_]+\s*)?;$/m',
                (string) file_get_contents($file),
                $matches,
                PREG_SET_ORDER
            );

            foreach ($matches as $match) {
                $class = $match[1];

                if (! class_exists($class) && ! interface_exists($class)) {
                    continue;
                }

                $extension = (new \ReflectionClass($class))->getExtensionName();

                if ($extension === false || in_array($extension, $alwaysPresent, true)) {
                    continue;
                }

                $found['ext-'.strtolower($extension)] ??= $class.' ('.basename($file).')';
            }
        }

        return $found;
    }

    /**
     * Every `illuminate/*` package our shipped code imports from, mapped to one import
     * that proves it.
     *
     * @return array<string, string>
     */
    private function illuminateImports(): array
    {
        $found = [];
        $unrecognised = [];

        foreach ($this->shippedFiles() as $file) {
            $contents = (string) file_get_contents($file);

            // Every use statement, in any shape — including multi-line, since [^;] spans
            // newlines. This is the denominator the strict pattern below is checked against.
            preg_match_all('/^use\s+[^;]*;/m', $contents, $statements);

            foreach ($statements[0] as $statement) {
                if (! str_contains($statement, 'Illuminate')) {
                    continue;
                }

                $matched = preg_match(
                    '/^use\s+\\\\?(Illuminate\\\\([A-Za-z0-9_]+)\\\\[A-Za-z0-9_\\\\]+)\s*(?:as\s+[A-Za-z0-9_]+\s*)?;$/',
                    $statement,
                    $match
                );

                if ($matched !== 1) {
                    $unrecognised[] = basename($file).': '.preg_replace('/\s+/', ' ', $statement);

                    continue;
                }

                $found[self::NAMESPACE_ROOT_IS_THE_PACKAGE.strtolower($match[2])] ??= $match[1].' ('.basename($file).')';
            }
        }

        $this->assertSame(
            [],
            $unrecognised,
            "This test could not classify an Illuminate import, so it cannot vouch for it:\n  "
            .implode("\n  ", $unrecognised)
            ."\nTeach the pattern that form rather than letting it pass unchecked — an import "
            .'this sweep silently skips is exactly the gap #43 was filed about.'
        );

        // Guards the guard: a walk or pattern that quietly matched nothing would make the
        // whole test vacuous, and it would pass by finding no work to do.
        $this->assertNotEmpty($found, 'Found no Illuminate imports in shipped code — this test is not looking where it thinks.');

        return $found;
    }

    /**
     * @return array<int, string>
     */
    private function declaredPackages(): array
    {
        $composer = json_decode((string) file_get_contents($this->packageRoot().'/composer.json'), true);

        $this->assertIsArray($composer);
        $this->assertIsArray($composer['require'] ?? null, 'composer.json has no require section.');

        return array_keys($composer['require']);
    }

    /**
     * Every PHP file this package ships: the library, the route file the provider requires
     * at boot, and the migrations it publishes.
     *
     * @return array<int, string>
     */
    private function shippedFiles(): array
    {
        $files = [];

        foreach (['/src', '/routes', '/database'] as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->packageRoot().$directory, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function packageRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}
