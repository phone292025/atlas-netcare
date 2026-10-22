<?php

namespace Tests\Unit;

use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The dependency rules of the modular monolith, checked against the source.
 *
 * PHP has no package-private classes, so nothing else stops a model from
 * calling a service, or one module from reaching into another. This test reads
 * every file under app/ and lists the classes each one imports or writes out
 * in full. Comments don't count, so an @see is never a dependency. It boots
 * nothing and touches no database.
 *
 * @see docs/03-architecture.md — Dependency rules
 */
class ArchitectureTest extends TestCase
{
    /**
     * What each inner part of app/ may use from the rest of it. Dependencies
     * point inward: enums and support, then models, then the services, which
     * hold the business rules. The parts not listed here deliver those rules
     * (Http, Console, View, Providers) and may use anything inside them.
     */
    private const INWARD = [
        'Enums' => [],
        'Support' => [],
        'Models' => ['Enums', 'Support'],
        'Observers' => ['Models', 'Enums', 'Support'],
        'Policies' => ['Models', 'Enums', 'Support'],
        'Services' => ['Models', 'Enums', 'Support'],
    ];

    /**
     * Every use of one service module by another, with its reason. A new one
     * fails this test until it is listed here. Phase 2.1 of the roadmap
     * replaces the two Telemetry edges with domain events and deletes them.
     */
    private const MODULE_EDGES = [
        // TelemetryCollector opens a ticket for an access point that goes
        // offline, and alarms the engineers when one goes offline or degrades.
        'Telemetry' => ['Tickets', 'Shifts'],
        // TicketService alarms engineers about a new critical ticket, a ticket
        // assigned to them by somebody else, and a missed SLA.
        'Tickets' => ['Shifts'],
    ];

    /** Where the business rules live, and what they must never read. */
    private const BUSINESS_CODE = ['Services', 'Models', 'Support', 'Enums'];

    private const REQUEST_STATE_CLASSES = [
        'Illuminate\Http\Request',
        'Illuminate\Support\Facades\Auth',
        'Illuminate\Support\Facades\Request',
        'Illuminate\Support\Facades\Session',
        'Auth',
        'Request',
        'Session',
    ];

    private const REQUEST_STATE_HELPERS = ['request', 'auth', 'session'];

    /** @var array<string, array{class: string, names: list<string>, calls: list<string>}>|null */
    private static ?array $files = null;

    public function test_dependencies_point_inward(): void
    {
        $wrong = [];

        foreach (self::files() as $path => $file) {
            $from = self::layerOf($file['class']);

            if (! array_key_exists($from, self::INWARD)) {
                continue;
            }

            foreach ($file['names'] as $name) {
                $to = self::layerOf($name);

                if ($to !== null && $to !== $from && ! in_array($to, self::INWARD[$from], true)) {
                    $wrong[] = "{$path} uses {$name}";
                }
            }
        }

        $this->assertSame([], $wrong, "Inner code uses outer code. Pass the value in, or move the code outward:\n".implode("\n", $wrong));
    }

    public function test_nothing_uses_a_controller_or_a_command(): void
    {
        // Controllers and commands are entry points. Logic that two of them
        // need goes into a service they both call.
        $wrong = [];

        foreach (self::files() as $path => $file) {
            foreach ($file['names'] as $name) {
                foreach (['App\Http\Controllers\\', 'App\Console\\'] as $entryPoint) {
                    if (str_starts_with($name, $entryPoint) && ! str_starts_with($file['class'], $entryPoint)) {
                        $wrong[] = "{$path} uses {$name}";
                    }
                }
            }
        }

        $this->assertSame([], $wrong, "Code outside a controller or a command uses one:\n".implode("\n", $wrong));
    }

    public function test_modules_use_each_other_only_along_the_listed_edges(): void
    {
        $found = [];

        foreach (self::files() as $path => $file) {
            $from = self::moduleOf($file['class']);

            foreach ($file['names'] as $name) {
                $to = self::moduleOf($name);

                if ($from !== null && $to !== null && $to !== $from) {
                    $found["{$from} -> {$to}"] ??= $path;
                }
            }
        }

        $listed = [];

        foreach (self::MODULE_EDGES as $from => $targets) {
            foreach ($targets as $to) {
                $listed[] = "{$from} -> {$to}";
            }
        }

        $unlisted = array_map(fn (string $edge) => "{$edge}, first in {$found[$edge]}", array_values(array_diff(array_keys($found), $listed)));

        $this->assertSame([], $unlisted, "A module uses another one without a listed edge. Raise an event instead, or add the edge to MODULE_EDGES with its reason:\n".implode("\n", $unlisted));

        $unused = array_values(array_diff($listed, array_keys($found)));

        $this->assertSame([], $unused, "MODULE_EDGES lists an edge that no code uses any more. Delete it, so the list stays true:\n".implode("\n", $unused));
    }

    public function test_the_module_edges_form_no_cycle(): void
    {
        // A cycle welds its modules into one: none of them can change or be
        // tested without the others. Adding the edge that a failing test above
        // asks for is how one starts.
        $reaches = self::MODULE_EDGES;

        do {
            $grew = false;

            foreach ($reaches as $from => $targets) {
                foreach ($targets as $to) {
                    foreach (array_diff(self::MODULE_EDGES[$to] ?? [], $reaches[$from]) as $next) {
                        $reaches[$from][] = $next;
                        $grew = true;
                    }
                }
            }
        } while ($grew);

        foreach ($reaches as $module => $targets) {
            $this->assertNotContains($module, $targets, "{$module} depends on itself through MODULE_EDGES. Break the cycle with an event or an interface.");
        }
    }

    public function test_business_code_never_reads_the_request_the_session_or_the_signed_in_user(): void
    {
        // The scheduler and the queue worker have no request, no session and
        // nobody signed in, and neither will the agents' ingest API. A service
        // that asked auth() for the user would get null there.
        $wrong = [];

        foreach (self::files() as $path => $file) {
            if (! in_array(self::layerOf($file['class']), self::BUSINESS_CODE, true)) {
                continue;
            }

            foreach (array_intersect($file['names'], self::REQUEST_STATE_CLASSES) as $name) {
                $wrong[] = "{$path} uses {$name}";
            }

            foreach (array_intersect($file['calls'], self::REQUEST_STATE_HELPERS) as $helper) {
                $wrong[] = "{$path} calls {$helper}()";
            }
        }

        $this->assertSame([], $wrong, "Business code reads request state. Pass the user or the value in as a parameter:\n".implode("\n", $wrong));
    }

    public function test_the_scan_reads_grouped_aliased_and_fully_qualified_names(): void
    {
        $scan = self::scan(<<<'PHP'
            <?php
            namespace App\Services\Example;

            use App\Enums\{TicketStatus, UserRole as Role};
            use App\Support\CampusClock as Clock;

            /** @see App\Http\Controllers\Controller, a docblock and not a dependency */
            class Example
            {
                use SomeTrait;

                public function run(): string
                {
                    $closure = function () use ($x) { return auth(); };

                    return \App\Models\Ticket::class.$this->request().Clock::now();
                }
            }
            PHP);

        $this->assertSame(['App\Enums\TicketStatus', 'App\Enums\UserRole', 'App\Support\CampusClock', 'App\Models\Ticket'], $scan['names']);
        $this->assertContains('auth', $scan['calls']);
        $this->assertNotContains('request', $scan['calls'], 'A method call was read as the request() helper.');
    }

    public function test_the_scan_finds_what_the_rules_check(): void
    {
        // Every rule above passes by default if the scan finds nothing.
        $layers = [];

        foreach (self::files() as $file) {
            foreach ($file['names'] as $name) {
                $layers[self::layerOf($name) ?? 'outside app'] = true;
            }
        }

        foreach (['Enums', 'Support', 'Models', 'Services'] as $layer) {
            $this->assertArrayHasKey($layer, $layers, "The scan found no use of App\\{$layer}. It is broken, and the rules above would pass without checking anything.");
        }
    }

    /** @return array<string, array{class: string, names: list<string>, calls: list<string>}> */
    private static function files(): array
    {
        if (self::$files !== null) {
            return self::$files;
        }

        $root = dirname(__DIR__, 2);
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/app", RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // composer.json maps App\ to app/, so the path names the class.
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $class = 'App\\'.str_replace('/', '\\', substr($path, strlen('app/'), -strlen('.php')));

            $files[$path] = ['class' => $class, ...self::scan(file_get_contents($file->getPathname()))];
        }

        ksort($files);

        return self::$files = $files;
    }

    /**
     * The names a file imports or writes out in full, and the plain functions
     * it calls. Comments and docblocks are skipped.
     *
     * @return array{names: list<string>, calls: list<string>}
     */
    private static function scan(string $code): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($code), fn (PhpToken $token) => ! $token->isIgnorable()));
        $names = [];
        $calls = [];
        $depth = 0;

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is(T_USE) && $depth === 0) {
                // An import: `use A\B;`, `use A\B as C;` or `use A\{B, C as D};`.
                // A trait's `use` and a closure's sit inside braces.
                for ($prefix = '', $i++; $i < $count && $tokens[$i]->text !== ';'; $i++) {
                    if (! $tokens[$i]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) || $tokens[$i - 1]->is(T_AS)) {
                        continue;
                    }

                    if ($tokens[$i + 1]->is(T_NS_SEPARATOR)) {
                        $prefix = ltrim($tokens[$i]->text, '\\').'\\';
                    } else {
                        $names[] = $prefix.ltrim($tokens[$i]->text, '\\');
                    }
                }

                continue;
            }

            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->text === '}') {
                $depth--;
            } elseif ($token->is(T_NAME_FULLY_QUALIFIED)) {
                $names[] = ltrim($token->text, '\\');
            }

            $previous = $tokens[$i - 1] ?? null;

            if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && ($tokens[$i + 1]->text ?? '') === '('
                && ! $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW])) {
                $calls[] = strtolower(ltrim($token->text, '\\'));
            }
        }

        return ['names' => array_values(array_unique($names)), 'calls' => array_values(array_unique($calls))];
    }

    /** Enums, Models, Services, Http and so on, for a class under App\. Null for anything else. */
    private static function layerOf(string $class): ?string
    {
        $segments = explode('\\', $class);

        return isset($segments[2]) && $segments[0] === 'App' ? $segments[1] : null;
    }

    /** Telemetry, Tickets and so on, for a class in a service module. Null for anything else. */
    private static function moduleOf(string $class): ?string
    {
        $segments = explode('\\', $class);

        return isset($segments[3]) && $segments[0] === 'App' && $segments[1] === 'Services' ? $segments[2] : null;
    }
}
