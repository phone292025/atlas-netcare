<?php

namespace Tests\Unit\Enums;

use App\Enums\AlarmType;
use App\Enums\ApStatus;
use App\Enums\ChecklistResult;
use App\Enums\DeviceStatus;
use App\Enums\InspectionResult;
use App\Enums\RiskBand;
use App\Enums\ShiftStatus;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use Tests\TestCase;

/**
 * Contract tests every enum in the system must satisfy.
 *
 * These guard the promise made in ADR-0004: that labels and tones live on the
 * enum, so a badge and a form can never drift apart.
 */
class EnumContractTest extends TestCase
{
    /** @return array<int, class-string> */
    public static function statusEnums(): array
    {
        return [
            [DeviceStatus::class],
            [ApStatus::class],
            [TicketStatus::class],
            [TicketPriority::class],
            [ChecklistResult::class],
            [InspectionResult::class],
            [ShiftStatus::class],
            [AlarmType::class],
            [RiskBand::class],
        ];
    }

    /** @return array<int, class-string> */
    public static function allEnums(): array
    {
        return array_merge(self::statusEnums(), [
            [UserRole::class],
            [TicketCategory::class],
        ]);
    }

    /**
     * @dataProvider allEnums
     *
     * @param  class-string  $enum
     */
    public function test_every_case_has_a_non_empty_label(string $enum): void
    {
        foreach ($enum::cases() as $case) {
            $this->assertNotSame('', trim($case->label()), "{$enum}::{$case->name} has an empty label");
        }
    }

    /**
     * @dataProvider allEnums
     *
     * @param  class-string  $enum
     */
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

    /**
     * @dataProvider statusEnums
     *
     * @param  class-string  $enum
     */
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

    /**
     * @dataProvider allEnums
     *
     * @param  class-string  $enum
     */
    public function test_values_and_options_stay_in_step(string $enum): void
    {
        $this->assertSame($enum::values(), array_keys($enum::options()));
        $this->assertCount(count($enum::cases()), $enum::values());
    }
}
