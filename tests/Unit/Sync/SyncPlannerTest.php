<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sync;

use App\Sync\Endpoint;
use App\Sync\SyncContext;
use App\Sync\SyncPlan;
use App\Sync\SyncPlanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SyncPlanner::class)]
#[CoversClass(SyncContext::class)]
#[CoversClass(SyncPlan::class)]
final class SyncPlannerTest extends TestCase
{
    private const array SNAPSHOT = [
        Endpoint::DRIVER, Endpoint::CAR, Endpoint::TRACK, Endpoint::SETUP, Endpoint::TESTING,
        Endpoint::STAFF, Endpoint::TD, Endpoint::SPONSORS, Endpoint::MONEY, Endpoint::ANALYSIS,
        Endpoint::STANDINGS, Endpoint::ECONOMY,
    ];

    private const array SEASONAL = [Endpoint::CALENDAR, Endpoint::TYRES, Endpoint::MENU, Endpoint::SF_HISTORY, Endpoint::CCP_HISTORY];

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    private static function office(array $o = []): array
    {
        return $o + [
            'seasonNb' => 10, 'raceNb' => 5, 'endOfSeason' => false, 'driId' => '123', 'tdId' => '55',
            'sponsorsAlert' => '0', 'donePractice' => false, 'doneQ1' => false, 'doneQ2' => false,
            'doneRaceSetup' => false, 'doneDriTrain' => false, 'doneStaffTrain' => false,
            'doneCarRepair' => false, 'doneFacilitiesUpdate' => false, 'doneTesting' => false,
        ];
    }

    /**
     * @param array<string, mixed> $c
     * @param array<string, mixed> $office
     * @param array<string, mixed>|null $previous
     */
    private static function ctx(array $c, array $office, ?array $previous): SyncContext
    {
        return new SyncContext(
            $previous,
            $office,
            $c['remaining'] ?? 100,
            $c['mode'] ?? SyncPlanner::MODE_LOGIN,
            array_values($c['race'] ?? self::SNAPSHOT),
            array_values($c['season'] ?? self::SEASONAL),
            $c['pending'] ?? [],
            $c['done'] ?? 0,
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>|null|false, 3: bool, 4: list<string>, 5: list<string>}>
     */
    public static function rows(): array
    {
        $same = false; // previous office = the unmodified default office
        $ps = ['SETUP'];
        return [
            'first sync' => [['race' => []], [], null, false,
                ['DRIVER', 'CAR', 'TRACK', 'SETUP', 'TESTING', 'STAFF', 'TD', 'SPONSORS', 'MONEY', 'ANALYSIS'],
                ['STANDINGS', 'ECONOMY']],
            'unchanged office' => [[], [], $same, false, [], []],
            'doneRaceSetup flip' => [[], ['doneRaceSetup' => true], $same, false, $ps, []],
            'doneQ2 flip' => [[], ['doneQ2' => true], $same, false, $ps, []],
            'doneDriTrain flip' => [[], ['doneDriTrain' => true], $same, false, ['DRIVER'], []],
            'doneCarRepair flip' => [[], ['doneCarRepair' => true], $same, false, ['CAR'], []],
            'doneTesting flip' => [[], ['doneTesting' => true], $same, false, ['CAR', 'TESTING'], []],
            'doneStaffTrain flip' => [[], ['doneStaffTrain' => true], $same, false, ['STAFF'], []],
            'doneFacilitiesUpdate flip' => [[], ['doneFacilitiesUpdate' => true], $same, false, ['STAFF'], []],
            'flag already true stays quiet' => [[], ['doneRaceSetup' => true, 'doneTesting' => true], self::office(['doneRaceSetup' => true, 'doneTesting' => true]), false, [], []],
            'flag string "1" flips' => [[], ['doneRaceSetup' => '1'], self::office(['doneRaceSetup' => '0']), false, $ps, []],
            'sponsorsAlert changed' => [[], ['sponsorsAlert' => '1'], $same, false, ['SPONSORS'], []],
            'tdId changed to a new TD' => [[], ['tdId' => '55'], self::office(['tdId' => '']), false, ['TD'], []],
            'tdId dropped plans no TD' => [[], ['tdId' => ''], $same, false, [], []],
            'manual unchanged' => [['mode' => SyncPlanner::MODE_MANUAL], [], $same, false, ['CAR', 'SETUP'], []],
            'manual with setup flip has no duplicates' => [['mode' => SyncPlanner::MODE_MANUAL], ['doneRaceSetup' => true], $same, false, ['CAR', 'SETUP'], []],
            'no driver on a new race' => [['race' => []], ['driId' => ''], null, false,
                ['CAR', 'TRACK', 'SETUP', 'TESTING', 'STAFF', 'TD', 'SPONSORS', 'MONEY', 'ANALYSIS'], ['STANDINGS', 'ECONOMY']],
            'no driver on driver flip' => [[], ['doneDriTrain' => true, 'driId' => '0'], $same, false, [], []],
            'no TD on a new race' => [['race' => []], ['tdId' => ''], null, false,
                ['DRIVER', 'CAR', 'TRACK', 'SETUP', 'TESTING', 'STAFF', 'SPONSORS', 'MONEY', 'ANALYSIS'], ['STANDINGS', 'ECONOMY']],
            'season rollover at end of season has no TRACK' => [['race' => []], ['seasonNb' => 11, 'raceNb' => 1, 'endOfSeason' => '1'], self::office(['seasonNb' => 10, 'raceNb' => 17]), false,
                ['DRIVER', 'CAR', 'SETUP', 'TESTING', 'STAFF', 'TD', 'SPONSORS', 'MONEY', 'ANALYSIS'], ['STANDINGS', 'ECONOMY']],
            'new race phase 2' => [['race' => []], ['raceNb' => 6], $same, false,
                ['DRIVER', 'CAR', 'TRACK', 'SETUP', 'TESTING', 'STAFF', 'TD', 'SPONSORS', 'MONEY', 'ANALYSIS'], ['STANDINGS', 'ECONOMY']],
            'analysis in phase 2 on a new race' => [['race' => []], ['raceNb' => 6], $same, true,
                ['DRIVER', 'CAR', 'TRACK', 'SETUP', 'TESTING', 'STAFF', 'TD', 'SPONSORS', 'MONEY'], ['ANALYSIS', 'STANDINGS', 'ECONOMY']],
            'analysis in phase 2 unchanged office' => [[], [], $same, true, [], []],
            'analysis in phase 2 heals a missing analysis' => [['race' => array_diff(self::SNAPSHOT, ['ANALYSIS'])], [], $same, true, [], ['ANALYSIS']],
            'new season not archived' => [['race' => [], 'season' => []], ['seasonNb' => 11, 'raceNb' => 1], self::office(['seasonNb' => 10, 'raceNb' => 17]), false,
                ['DRIVER', 'CAR', 'TRACK', 'SETUP', 'TESTING', 'STAFF', 'TD', 'SPONSORS', 'MONEY', 'ANALYSIS', 'CALENDAR', 'TYRES', 'MENU'],
                ['STANDINGS', 'ECONOMY', 'SF_HISTORY[season=10]', 'CCP_HISTORY[season=10]']],
            'season endpoints missing on unchanged office' => [['season' => ['TYRES']], [], $same, false, ['CALENDAR', 'MENU'], ['SF_HISTORY[season=9]', 'CCP_HISTORY[season=9]']],
            'one season history already archived' => [['season' => ['CALENDAR', 'TYRES', 'MENU', 'SF_HISTORY']], [], $same, false, [], ['CCP_HISTORY[season=9]']],
            'first season has no completed season to ask about' => [['season' => ['CALENDAR', 'TYRES', 'MENU']], ['seasonNb' => 1], self::office(['seasonNb' => 1]), false, [], []],
            'unknown season plans no history' => [['season' => ['CALENDAR', 'TYRES', 'MENU']], ['seasonNb' => ''], self::office(['seasonNb' => '']), false, [], []],
            'heal a missing endpoint' => [['race' => array_diff(self::SNAPSHOT, ['MONEY'])], [], $same, false, ['MONEY'], []],
            'heal keeps priority order' => [['race' => array_diff(self::SNAPSHOT, ['MONEY', 'CAR'])], ['doneRaceSetup' => true], $same, false, ['CAR', 'SETUP', 'MONEY'], []],
            'heal never plans TD without a TD' => [['race' => array_diff(self::SNAPSHOT, ['TD'])], ['tdId' => ''], self::office(['tdId' => '']), false, [], []],
            'heal never plans DRIVER without a driver' => [['race' => array_diff(self::SNAPSHOT, ['DRIVER'])], ['driId' => ''], self::office(['driId' => '']), false, [], []],
            'heal never plans TRACK at end of season' => [['race' => array_diff(self::SNAPSHOT, ['TRACK'])], ['endOfSeason' => true], self::office(['endOfSeason' => true]), false, [], []],
            'remaining 52 login new race' => [['race' => [], 'remaining' => 52], [], null, false, ['DRIVER', 'CAR'], []],
            'remaining 50 login new race' => [['race' => [], 'remaining' => 50], [], null, false, [], []],
            'remaining below floor never negative' => [['race' => [], 'remaining' => 10], [], null, false, [], []],
            'remaining 25 manual' => [['mode' => SyncPlanner::MODE_MANUAL, 'remaining' => 25], [], $same, false, ['CAR', 'SETUP'], []],
            'remaining 21 manual trims to the floor' => [['mode' => SyncPlanner::MODE_MANUAL, 'remaining' => 21], [], $same, false, ['CAR'], []],
            'remaining 59 with backfill' => [['remaining' => 59, 'pending' => ['10,1', '10,2']], [], $same, false, [], []],
            'remaining 60 with backfill' => [['remaining' => 60, 'pending' => ['10,1', '10,2']], [], $same, false, [], ['ANALYSIS[SR=10,1]', 'ANALYSIS[SR=10,2]']],
            'remaining 90 with 7 pending' => [['remaining' => 90, 'pending' => ['10,1', '10,2', '10,3', '10,4', '10,5', '10,6', '10,7']], [], $same, false, [],
                ['ANALYSIS[SR=10,1]', 'ANALYSIS[SR=10,2]', 'ANALYSIS[SR=10,3]', 'ANALYSIS[SR=10,4]', 'ANALYSIS[SR=10,5]']],
            'backfill done 4 of 5 with 7 pending' => [['remaining' => 90, 'done' => 4, 'pending' => ['10,1', '10,2', '10,3', '10,4', '10,5', '10,6', '10,7']], [], $same, false, [], ['ANALYSIS[SR=10,1]']],
            'backfill budget spent' => [['remaining' => 90, 'done' => 5, 'pending' => ['10,1']], [], $same, false, [], []],
            'backfill overspent never negative' => [['remaining' => 90, 'done' => 9, 'pending' => ['10,1']], [], $same, false, [], []],
            'phase 2 trimmed after phase 1' => [['race' => [], 'remaining' => 62, 'pending' => ['10,1']], [], null, false,
                ['DRIVER', 'CAR', 'TRACK', 'SETUP', 'TESTING', 'STAFF', 'TD', 'SPONSORS', 'MONEY', 'ANALYSIS'], ['STANDINGS', 'ECONOMY']],
            'phase 2 trimmed to the floor' => [['race' => [], 'remaining' => 61, 'pending' => ['10,1']], [], null, false,
                ['DRIVER', 'CAR', 'TRACK', 'SETUP', 'TESTING', 'STAFF', 'TD', 'SPONSORS', 'MONEY', 'ANALYSIS'], ['STANDINGS']],
            'heal STANDINGS only' => [['race' => array_diff(self::SNAPSHOT, ['STANDINGS'])], [], $same, false, [], ['STANDINGS']],
            'heal ECONOMY only' => [['race' => array_diff(self::SNAPSHOT, ['ECONOMY'])], [], $same, false, [], ['ECONOMY']],
            'heal both on an unchanged office' => [['race' => array_diff(self::SNAPSHOT, ['STANDINGS', 'ECONOMY'])], [], $same, false, [], ['STANDINGS', 'ECONOMY']],
            'heal trimmed by the phase 2 floor' => [['race' => array_diff(self::SNAPSHOT, ['STANDINGS', 'ECONOMY']), 'remaining' => 51], [], $same, false, [], ['STANDINGS']],
            'harvest heals STANDINGS and ECONOMY' => [['mode' => SyncPlanner::MODE_HARVEST, 'race' => array_diff(self::SNAPSHOT, ['STANDINGS', 'ECONOMY'])], [], $same, false, [], ['STANDINGS', 'ECONOMY']],
            'backfill gated after phase 1 (65 - 6 = 59)' => [['race' => array_diff(self::SNAPSHOT, ['DRIVER', 'CAR', 'TRACK', 'SETUP', 'TESTING', 'STAFF']), 'remaining' => 65, 'pending' => ['10,1']], [], $same, false,
                ['DRIVER', 'CAR', 'TRACK', 'SETUP', 'TESTING', 'STAFF'], []],
            'backfill after phase 1 (70 - 2 = 68)' => [['race' => array_diff(self::SNAPSHOT, ['DRIVER', 'CAR']), 'remaining' => 70, 'done' => 2, 'pending' => ['10,1', '10,2', '10,3', '10,4']], [], $same, false,
                ['DRIVER', 'CAR'], ['ANALYSIS[SR=10,1]', 'ANALYSIS[SR=10,2]', 'ANALYSIS[SR=10,3]']],
            'backfill gated after earlier phase 2 entries (61 - 2 = 59)' => [['race' => array_diff(self::SNAPSHOT, ['STANDINGS', 'ECONOMY']), 'remaining' => 61, 'pending' => ['10,1']], [], $same, false, [], ['STANDINGS', 'ECONOMY']],
            'backfill full allowance at 68 left' => [['remaining' => 68, 'pending' => ['10,1', '10,2', '10,3', '10,4', '10,5']], [], $same, false, [],
                ['ANALYSIS[SR=10,1]', 'ANALYSIS[SR=10,2]', 'ANALYSIS[SR=10,3]', 'ANALYSIS[SR=10,4]', 'ANALYSIS[SR=10,5]']],
            'harvest new race has no phase 1' => [['mode' => SyncPlanner::MODE_HARVEST, 'race' => []], [], null, false, [], ['STANDINGS', 'ECONOMY']],
            'harvest ignores season-scoped phase 1' => [['mode' => SyncPlanner::MODE_HARVEST, 'season' => []], [], $same, false, [], ['SF_HISTORY[season=9]', 'CCP_HISTORY[season=9]']],
            'harvest backfill' => [['mode' => SyncPlanner::MODE_HARVEST, 'remaining' => 80, 'pending' => ['9,3']], [], $same, false, [], ['ANALYSIS[SR=9,3]']],
        ];
    }

    /**
     * @param array<string, mixed> $c
     * @param array<string, mixed> $changes
     * @param array<string, mixed>|null|false $previous false = identical to the current office
     * @param list<string> $expectedPhase1
     * @param list<string> $expectedPhase2
     */
    #[DataProvider('rows')]
    public function testPlan(array $c, array $changes, array|null|false $previous, bool $analysisInPhase2, array $expectedPhase1, array $expectedPhase2): void
    {
        $office = self::office($changes);
        $prev = $previous === false ? self::office() : $previous;

        $plan = (new SyncPlanner($analysisInPhase2))->plan(self::ctx($c, $office, $prev));

        $this->assertSame($expectedPhase1, self::names($plan->phase1));
        $this->assertSame($expectedPhase2, self::names($plan->phase2));
    }

    public function testPlanIsTrimmedWhenTheFloorCutsPhase1(): void
    {
        $plan = (new SyncPlanner())->plan(self::ctx(['race' => [], 'remaining' => 52], self::office(), null));

        $this->assertSame(['DRIVER', 'CAR'], self::names($plan->phase1));
        $this->assertTrue($plan->trimmed);
        $this->assertSame(
            ['TRACK', 'SETUP', 'TESTING', 'STAFF', 'TD', 'SPONSORS', 'MONEY', 'ANALYSIS'],
            $plan->dropped,
        );
    }

    public function testPlanIsNotTrimmedWhenTheBudgetCoversPhase1(): void
    {
        $plan = (new SyncPlanner())->plan(self::ctx(['race' => [], 'remaining' => 100], self::office(), null));
        $this->assertFalse($plan->trimmed);
        $this->assertSame([], $plan->dropped);

        $exact = (new SyncPlanner())->plan(self::ctx(
            ['remaining' => 52],
            self::office(['doneRaceSetup' => true]),
            self::office(),
        ));
        $this->assertSame(['SETUP'], self::names($exact->phase1));
        $this->assertFalse($exact->trimmed);
    }

    /**
     * @param list<array{endpoint: string, params: string}> $entries
     *
     * @return list<string>
     */
    private static function names(array $entries): array
    {
        return array_map(
            static fn (array $e): string => $e['endpoint'] . ($e['params'] === '' ? '' : '[' . $e['params'] . ']'),
            $entries,
        );
    }
}
