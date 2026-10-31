<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Rebuild the file tables in `docs/13-code-map.md`.
 *
 * The code map lists every file and what it does. Written by hand it would be
 * wrong within a week, so the tables are generated and a test fails when they
 * are stale. Only the tables between the HTML comment markers are rewritten;
 * the prose around them is hand-written and never touched.
 *
 *   <!-- code-map:app/Services/ -->
 *   ...the table this command owns...
 *   <!-- /code-map -->
 *
 * Each description is the first prose line of the file's class docblock, so an
 * entry cannot disagree with its file. The map lists no line counts: they
 * changed on nearly every commit, and a page that goes stale on every commit
 * becomes a merge conflict on every branch.
 *
 *   php artisan atlas:code-map
 *   php artisan atlas:code-map --check
 *
 * @see docs/13-code-map.md
 */
class BuildCodeMap extends Command
{
    protected $signature = 'atlas:code-map
                            {--check : Write nothing; fail if the page is out of date}';

    protected $description = 'Rebuild the file tables in docs/13-code-map.md from the docblocks the code already carries';

    private const PAGE = 'docs/13-code-map.md';

    /**
     * Marker id => the path prefix it lists.
     *
     * `views` is the exception: Blade files carry no class docblock, so that
     * table is names only.
     */
    private const SECTIONS = [
        'routes/',
        'app/Http/Controllers/',
        'app/Http/Middleware/',
        'app/Http/Requests/',
        'app/Services/',
        'app/Models/',
        'app/Enums/',
        'app/Policies/',
        'app/Observers/',
        'app/Support/',
        'app/View/Components/',
        'app/Providers/',
        'app/Console/Commands/',
        'database/migrations/',
        'database/seeders/',
        'database/factories/',
        'config/',
        'tests/',
        'views',
    ];

    public function handle(): int
    {
        $page = base_path(self::PAGE);

        if (! is_file($page)) {
            $this->error('  '.self::PAGE.' does not exist. This command fills a page in; it does not create one.');

            return self::FAILURE;
        }

        $summaries = $this->summaries();
        $before = file_get_contents($page);
        $after = $before;
        $missing = [];

        foreach (self::SECTIONS as $section) {
            $table = $section === 'views'
                ? $this->viewTable()
                : $this->fileTable($summaries, $section);

            $replaced = $this->fill($after, $section, $table);

            if ($replaced === null) {
                $missing[] = $section;

                continue;
            }

            $after = $replaced;
        }

        if ($missing !== []) {
            $this->error('  No marker for: '.implode(', ', $missing));
            $this->line('  Add <!-- code-map:'.$missing[0].' --> and <!-- /code-map --> where the table belongs.');

            return self::FAILURE;
        }

        $covered = substr_count($after, "\n| `");

        if ($this->option('check')) {
            if ($after === $before) {
                $this->info("  {$covered} files listed, and the page matches them.");

                return self::SUCCESS;
            }

            $this->error('  '.self::PAGE.' is out of date. Run: php artisan atlas:code-map');

            return self::FAILURE;
        }

        if ($after === $before) {
            $this->info("  {$covered} files listed. Already up to date.");

            return self::SUCCESS;
        }

        file_put_contents($page, $after);

        $this->info("  {$covered} files listed in ".self::PAGE.'.');

        return self::SUCCESS;
    }

    /**
     * Replace what sits between one pair of markers.
     *
     * Returns null when the markers are absent, so a section added to
     * SECTIONS without a home in the page fails loudly instead of vanishing.
     */
    private function fill(string $page, string $section, string $table): ?string
    {
        $open = '<!-- code-map:'.$section.' -->';
        $close = '<!-- /code-map -->';

        $start = strpos($page, $open);

        if ($start === false) {
            return null;
        }

        $from = $start + strlen($open);
        $end = strpos($page, $close, $from);

        if ($end === false) {
            return null;
        }

        return substr($page, 0, $from)."\n".$table.substr($page, $end);
    }

    /**
     * The first prose line of every PHP file's class docblock, by path.
     *
     * @return array<string, string>
     */
    private function summaries(): array
    {
        $found = [];

        // Forward slashes on both sides. On Windows base_path() comes back
        // with backslashes while the iterator below is normalised, so a raw
        // str_replace silently leaves the absolute path in place, every
        // prefix match fails, and the tables come out empty rather than wrong.
        $root = str_replace('\\', '/', base_path()).'/';

        foreach (['app', 'database', 'routes', 'config', 'tests'] as $dir) {
            foreach ($this->filesIn(base_path($dir), '.php') as $path) {
                $found[str_replace($root, '', $path)] = $this->firstDocblockLine(file_get_contents($path));
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Skips `@param`-only blocks, so a file whose first docblock is a tag list
     * still reports the sentence a person wrote.
     *
     * Only docblocks before the class, enum, interface or trait count. A file
     * without a class docblock otherwise reported its first method's, and the
     * map said Laravel's users migration did "Run the migrations."
     */
    private function firstDocblockLine(string $source): string
    {
        $declaration = '/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|enum|interface|trait)\s+\w+|^\s*return\s+new\s+class\b/m';

        if (preg_match($declaration, $source, $match, PREG_OFFSET_CAPTURE)) {
            $source = substr($source, 0, $match[0][1]);
        }

        if (! preg_match_all('~/\*\*(.*?)\*/~s', $source, $blocks)) {
            return '';
        }

        foreach ($blocks[1] as $block) {
            foreach (preg_split('/\R/', $block) as $line) {
                $line = trim($line, " \t*");

                if ($line === '' || str_starts_with($line, '@')) {
                    continue;
                }

                return $line;
            }
        }

        return '';
    }

    /** @param  array<string, string>  $summaries */
    private function fileTable(array $summaries, string $prefix): string
    {
        $rows = '';

        foreach ($summaries as $path => $summary) {
            if (! str_starts_with($path, $prefix)) {
                continue;
            }

            $rows .= sprintf(
                "| `%s` | %s |\n",
                substr($path, strlen($prefix)),
                $this->readable($summary) ?: '_no summary in the file_',
            );
        }

        return "| File | What it does |\n|---|---|\n".$rows;
    }

    private function viewTable(): string
    {
        // Normalised for the same reason as summaries(): the iterator returns
        // forward slashes and base_path() does not, on Windows.
        $root = str_replace(DIRECTORY_SEPARATOR, '/', base_path('resources/views'));

        $names = array_map(
            fn (string $path) => str_replace($root.'/', '', $path),
            $this->filesIn($root, '.blade.php'),
        );

        sort($names);

        return "| File |\n|---|\n".implode('', array_map(fn (string $name) => "| `{$name}` |\n", $names));
    }

    /**
     * Em dashes are the house style in the source and not in this page, and a
     * pipe would end the table cell early.
     *
     * A dash does two different jobs and they need different replacements. With
     * spaces around it, it separates clauses and becomes a comma: "Demo
     * accounts, one sign-in per role". Without them it is a range, and a comma
     * makes nonsense of it: "the M1-M7 review" must not become "the M1,M7
     * review". Handle the spaced form first, or the bare rule eats it.
     */
    private function readable(string $summary): string
    {
        $summary = str_replace([' — ', ' – '], [', ', ', '], $summary);
        $summary = str_replace(['—', '–'], [' to ', ' to '], $summary);
        $summary = str_replace('|', '/', $summary);
        $summary = preg_replace('/\s+/', ' ', trim($summary));

        return rtrim($summary, '.');
    }

    /** @return list<string> */
    private function filesIn(string $root, string $extension): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (str_ends_with($path, $extension)) {
                $found[] = $path;
            }
        }

        sort($found);

        return $found;
    }
}
