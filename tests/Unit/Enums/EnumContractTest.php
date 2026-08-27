<?php

namespace Tests\Unit\Enums;

use App\Enums\Contracts\HasLabel;
use App\Enums\Contracts\HasTone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Contract tests every enum in the system must satisfy.
 *
 * These guard the promise made in ADR-0004: that labels and tones live on the
 * enum, so a badge and a form can never drift apart.
 *
 * The enum list is DISCOVERED from app/Enums rather than hand-maintained, so a
 * new enum is covered the moment it is created instead of being silently
 * untested until someone remembers to add it here.
 */
class EnumContractTest extends TestCase
{
    /**
     * Every backed enum in app/Enums.
     *
     * @return array<string, array{class-string}>
     */
    public static function allEnums(): array
    {
        // PHPUnit resolves data providers BEFORE the Laravel application is
        // booted, so app_path() is not available here. Walk up from this file
        // instead: tests/Unit/Enums -> tests/Unit -> tests -> project root.
        $enumDirectory = dirname(__DIR__, 3).'/app/Enums';

        $cases = [];

        foreach (glob($enumDirectory.'/*.php') as $file) {
            $name = basename($file, '.php');
            $class = 'App\\Enums\\'.$name;

            if (! enum_exists($class)) {
                continue;
            }

            $cases[$name] = [$class];
        }

        return $cases;
    }

    /**
     * Only the enums that carry semantic colour.
     *
     * @return array<string, array{class-string}>
     */
    public static function toneEnums(): array
    {
        return array_filter(
            self::allEnums(),
            static fn (array $row): bool => is_a($row[0], HasTone::class, allow_string: true),
        );
    }

    public function test_the_documented_enums_all_exist(): void
    {
        // docs/05-architecture.md and ADR-0004 both promise exactly these.
        $documented = [
            'UserRole', 'TicketStatus', 'TicketPriority', 'TicketCategory',
            'DeviceStatus', 'ApStatus', 'ChecklistResult', 'InspectionResult',
            'ShiftStatus', 'AlarmType', 'RiskBand',
        ];

        $discovered = array_keys(self::allEnums());

        sort($documented);
        sort($discovered);

        $this->assertSame($documented, $discovered, 'app/Enums has drifted from the documented list.');
    }

    #[DataProvider('allEnums')]
    public function test_every_enum_declares_the_label_contract(string $enum): void
    {
        // HasValues::options() calls label(). Without the interface a new enum
        // that forgets it would only fail when a page rendered.
        $this->assertTrue(
            is_a($enum, HasLabel::class, allow_string: true),
            "{$enum} must implement HasLabel"
        );
    }

    #[DataProvider('allEnums')]
    public function test_every_case_has_a_non_empty_label(string $enum): void
    {
        foreach ($enum::cases() as $case) {
            $this->assertNotSame('', trim($case->label()), "{$enum}::{$case->name} has an empty label");
        }
    }

    #[DataProvider('allEnums')]
    public function test_backing_values_are_lower_snake_case(string $enum): void
    {
        foreach ($enum::cases() as $case) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*$/',
                $case->value,
                "{$enum}::{$case->name} backing value '{$case->value}' is not lower snake_case"
            );
        }
    }

    #[DataProvider('toneEnums')]
    public function test_every_status_case_maps_to_a_known_tone(string $enum): void
    {
        $tones = ['healthy', 'attention', 'urgent', 'inactive'];

        foreach ($enum::cases() as $case) {
            $this->assertContains(
                $case->tone(),
                $tones,
                "{$enum}::{$case->name} returned unknown tone '{$case->tone()}'"
            );
        }
    }

    #[DataProvider('allEnums')]
    public function test_values_and_options_stay_in_step(string $enum): void
    {
        $this->assertSame($enum::values(), array_keys($enum::options()));
        $this->assertCount(count($enum::cases()), $enum::values());
    }
}
