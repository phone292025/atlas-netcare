<?php

namespace Tests\Feature;

use App\Console\Commands\BuildCodeMap;
use ReflectionMethod;
use Tests\TestCase;

/**
 * `atlas:code-map` keeps docs/13-code-map.md true.
 *
 * The page lists every file in the project and what it does. Maintained by
 * hand it would be wrong within a week, which is the failure this project has
 * spent the most time on: a badge promising a control nobody built, an
 * installation guide contradicting itself four lines later, a test count in
 * five documents with three of them disagreeing.
 *
 * These tests do not use RefreshDatabase. The command reads the filesystem and
 * touches no table.
 *
 * @see app/Console/Commands/BuildCodeMap.php
 */
class BuildCodeMapTest extends TestCase
{
    private string $page;

    private string $original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->page = base_path('docs/13-code-map.md');

        // The submitted copy of the project leaves docs/ out, and the page
        // with it. Nothing to check is a skip, not a failure.
        if (! is_file($this->page)) {
            $this->markTestSkipped('docs/13-code-map.md is not in this copy of the project.');
        }

        $this->original = file_get_contents($this->page);
    }

    protected function tearDown(): void
    {
        // The command writes to a file in the repository rather than to a
        // database, so nothing rolls back on its own. Unset after a skip.
        if (isset($this->original)) {
            file_put_contents($this->page, $this->original);
        }

        parent::tearDown();
    }

    public function test_the_committed_page_is_up_to_date(): void
    {
        /*
         * The one that earns its place. This fails the moment somebody adds a
         * file, renames one, or edits a class docblock without rebuilding the
         * page, which is exactly when the map starts lying.
         */
        $this->artisan('atlas:code-map --check')->assertExitCode(0);
    }

    public function test_check_writes_nothing(): void
    {
        file_put_contents($this->page, str_replace(
            'One traceable fault report',
            'Something quite different',
            $this->original,
        ));

        $stale = file_get_contents($this->page);

        $this->artisan('atlas:code-map --check')->assertExitCode(1);

        $this->assertSame($stale, file_get_contents($this->page), '--check edited the page.');
    }

    public function test_it_repairs_a_page_that_has_drifted(): void
    {
        file_put_contents($this->page, str_replace(
            'One traceable fault report',
            'Something quite different',
            $this->original,
        ));

        $this->artisan('atlas:code-map')->assertExitCode(0);

        $this->assertSame($this->original, file_get_contents($this->page));
    }

    public function test_every_php_file_and_view_appears(): void
    {
        // The point of the page. A file missing from it is a file somebody
        // will not find.
        $page = file_get_contents($this->page);
        $missing = [];

        foreach (['app', 'database', 'routes', 'config', 'tests'] as $dir) {
            foreach ($this->filesIn(base_path($dir), '.php') as $path) {
                $name = basename($path);

                if (! str_contains($page, '`'.$name.'`') && ! str_contains($page, '/'.$name.'`')) {
                    $missing[] = $name;
                }
            }
        }

        $this->assertSame([], $missing, 'Files absent from the code map: '.implode(', ', $missing));
    }

    public function test_an_edit_that_changes_no_docblock_leaves_the_page_alone(): void
    {
        // The map lists no line counts, so ordinary work does not make it
        // stale and branches do not collide on it.
        $service = base_path('app/Services/Tickets/TicketService.php');
        $source = file_get_contents($service);

        try {
            file_put_contents($service, $source."\n");

            $this->artisan('atlas:code-map --check')->assertExitCode(0);
        } finally {
            file_put_contents($service, $source);
        }
    }

    public function test_a_dash_between_clauses_and_a_dash_in_a_range_read_differently(): void
    {
        // One character, two jobs: a spaced dash separates clauses, a bare
        // one is a range. Checked on the formatter itself, so it does not
        // depend on which docblocks happen to contain a dash today.
        $readable = new ReflectionMethod(BuildCodeMap::class, 'readable');
        $command = app(BuildCodeMap::class);

        $this->assertSame('the M1 to M7 review', $readable->invoke($command, 'the M1–M7 review'));
        $this->assertSame('Demo accounts, one per role', $readable->invoke($command, 'Demo accounts — one per role.'));
        $this->assertStringNotContainsString(' , ', file_get_contents($this->page));
    }

    public function test_a_missing_marker_fails_rather_than_dropping_a_section(): void
    {
        /*
         * A section listed in the command with no home in the page would
         * otherwise vanish silently, and the page would be quietly incomplete
         * while reporting success.
         */
        file_put_contents($this->page, str_replace(
            '<!-- code-map:app/Models/ -->',
            '<!-- removed -->',
            $this->original,
        ));

        $this->artisan('atlas:code-map')
            ->expectsOutputToContain('app/Models/')
            ->assertExitCode(1);
    }

    /** @return list<string> */
    private function filesIn(string $root, string $extension): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (str_ends_with($path, $extension)) {
                $found[] = $path;
            }
        }

        return $found;
    }
}
