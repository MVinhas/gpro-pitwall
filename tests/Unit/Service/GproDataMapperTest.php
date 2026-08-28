<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\GproDataMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GproDataMapper::class)]
final class GproDataMapperTest extends TestCase
{
    private GproDataMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new GproDataMapper();
    }

    public function testEveryInternalFieldIsPresentForAnEmptyPayload(): void
    {
        $mapped = $this->mapper->mapDriver([]);

        $this->assertSame([
            'concentration',
            'talent',
            'aggressiveness',
            'experience',
            'technical_insight',
            'stamina',
            'charisma',
            'motivation',
            'weight',
            'age',
            'name',
            'id',
            'overall',
            'salary',
            'fee',
        ], array_keys($mapped));
    }

    public function testAbsentStatsDefaultToZeroRatherThanNull(): void
    {
        $mapped = $this->mapper->mapDriver([]);

        $this->assertSame(0, $mapped['concentration']);
        $this->assertSame(0, $mapped['talent']);
        $this->assertSame(0, $mapped['motivation']);
        $this->assertSame(0, $mapped['salary']);
    }

    public function testStatsAreCarriedAcrossFromTheApiPayload(): void
    {
        $mapped = $this->mapper->mapDriver([
            'concentration' => 91,
            'talent' => 88,
            'aggressiveness' => 42,
            'experience' => 77,
            'stamina' => 65,
            'charisma' => 55,
            'motivation' => 99,
            'weight' => 72,
            'age' => 26,
        ]);

        $this->assertSame(91, $mapped['concentration']);
        $this->assertSame(88, $mapped['talent']);
        $this->assertSame(42, $mapped['aggressiveness']);
        $this->assertSame(77, $mapped['experience']);
        $this->assertSame(65, $mapped['stamina']);
        $this->assertSame(55, $mapped['charisma']);
        $this->assertSame(99, $mapped['motivation']);
        $this->assertSame(72, $mapped['weight']);
        $this->assertSame(26, $mapped['age']);
    }

    /** The API has shipped both spellings; the mapper must accept either. */
    public function testTechnicalInsightAcceptsTheShortApiSpelling(): void
    {
        $mapped = $this->mapper->mapDriver(['techInsight' => 70]);

        $this->assertSame(70, $mapped['technical_insight']);
    }

    public function testTechnicalInsightAcceptsTheLongApiSpelling(): void
    {
        $mapped = $this->mapper->mapDriver(['technicalInsight' => 71]);

        $this->assertSame(71, $mapped['technical_insight']);
    }

    public function testTheShortTechnicalInsightSpellingWinsWhenBothArePresent(): void
    {
        $mapped = $this->mapper->mapDriver([
            'techInsight' => 70,
            'technicalInsight' => 71,
        ]);

        $this->assertSame(70, $mapped['technical_insight']);
    }

    public function testNameIsComposedFromTheFirstAndLastNameFields(): void
    {
        $mapped = $this->mapper->mapDriver(['fName' => 'Ada', 'lName' => 'Lovelace']);

        $this->assertSame('Ada Lovelace', $mapped['name']);
    }

    public function testAComposedNameIsTrimmed(): void
    {
        $mapped = $this->mapper->mapDriver(['fName' => '  Ada ', 'lName' => ' Lovelace  ']);

        $this->assertSame('Ada   Lovelace', $mapped['name']);
    }

    public function testAPlainNameFieldIsUsedWhenTheSplitFieldsAreAbsent(): void
    {
        $mapped = $this->mapper->mapDriver(['name' => 'Ada Lovelace']);

        $this->assertSame('Ada Lovelace', $mapped['name']);
    }

    public function testAHalfPresentSplitNameFallsBackRatherThanProducingAStray(): void
    {
        $mapped = $this->mapper->mapDriver(['fName' => 'Ada', 'name' => 'Fallback Name']);

        $this->assertSame('Fallback Name', $mapped['name']);
    }

    public function testAnUnnamedDriverBecomesUnknown(): void
    {
        $this->assertSame('Unknown', $this->mapper->mapDriver([])['name']);
    }

    public function testContractFieldsAreCarriedAcross(): void
    {
        $mapped = $this->mapper->mapDriver([
            'id' => 12345,
            'overall' => 104,
            'salary' => 55000,
            'fee' => 120000,
        ]);

        $this->assertSame(12345, $mapped['id']);
        $this->assertSame(104, $mapped['overall']);
        $this->assertSame(55000, $mapped['salary']);
        $this->assertSame(120000, $mapped['fee']);
    }

    public function testUnknownApiFieldsAreDroppedRatherThanPassedThrough(): void
    {
        $mapped = $this->mapper->mapDriver(['someNewApiField' => 'surprise']);

        $this->assertArrayNotHasKey('someNewApiField', $mapped);
    }
}
