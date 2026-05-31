<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Maps a sponsor's profile characteristics to the recommended answer
 * for each of the 5 GPRO negotiation questions.
 *
 * The GPRO API returns sponsor characteristics on the 0..6 scale; the
 * in-game banding is described on the 1..7 scale. This service adds 1
 * on read so the public mapping rules stay readable.
 *
 * Mapping today (user-supplied, cross-checked against in-game text):
 *   Q1 "Which area of the car would our advertisement be placed on?"
 *     → driven by Image
 *   Q2 "What are you expecting to achieve next season?"
 *     → driven by Expectations
 *   Q3 "How popular is your driver with the fans?"
 *     → driven by Image
 *   Q4 "What do you think of the amount per race we proposed?"
 *     → driven by Patience
 *   Q5 "What do you think of the contract duration we proposed?"
 *     → driven by Patience
 *
 * Finances / Reputation / Negotiation are read straight from the
 * profile and surfaced for context — they don't drive the 5 questions.
 */
final class SponsorAdvisorService
{
    /**
     * @param array<string, mixed> $sponsorProfile a GetSponsorProfile payload
     * @return array{
     *   characteristics: array{
     *     finances:    int, expectations: int, patience:    int,
     *     reputation:  int, image:        int, negotiation: int,
     *   },
     *   answers: array{
     *     car_spot:          string,
     *     expectation:       string,
     *     driver_popularity: string,
     *     amount:            string,
     *     duration:          string,
     *   }
     * }
     */
    public function adviseFor(array $sponsorProfile): array
    {
        $characteristics = $this->characteristics($sponsorProfile);

        return [
            'characteristics' => $characteristics,
            'answers'         => [
                'car_spot'          => $this->carSpotFor($characteristics['image']),
                'expectation'       => $this->expectationFor($characteristics['expectations']),
                'driver_popularity' => $this->driverPopularityFor($characteristics['image']),
                'amount'            => $this->amountFor($characteristics['patience']),
                'duration'          => $this->durationFor($characteristics['patience']),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @return array{
     *   finances: int, expectations: int, patience: int,
     *   reputation: int, image: int, negotiation: int,
     * }
     */
    private function characteristics(array $profile): array
    {
        // API returns 0..6; in-game scale is 1..7.
        return [
            'finances'    => (int) ($profile['finances']    ?? 0) + 1,
            'expectations' => (int) ($profile['expectations'] ?? 0) + 1,
            'patience'    => (int) ($profile['patience']    ?? 0) + 1,
            'reputation'  => (int) ($profile['reputation']  ?? 0) + 1,
            'image'       => (int) ($profile['image']       ?? 0) + 1,
            'negotiation' => (int) ($profile['negotiation'] ?? 0) + 1,
        ];
    }

    private function carSpotFor(int $image): string
    {
        return match (true) {
            $image <= 1 => 'Front wing',
            $image === 2 => 'Rear wing',
            $image === 3 => 'Nose',
            $image <= 5  => 'Sidepods',
            default      => 'Engine cover',
        };
    }

    private function expectationFor(int $expectations): string
    {
        return match (true) {
            $expectations <= 2 => 'Relegate with cash',
            $expectations <= 4 => 'Low table position',
            $expectations === 5 => 'Mid table position',
            default            => 'Promotion / top 4 / championship win',
        };
    }

    private function driverPopularityFor(int $image): string
    {
        return match (true) {
            $image <= 2 => 'My driver is hated by the fans',
            $image <= 4 => 'My driver is not very popular with the fans',
            $image === 5 => 'My driver is liked by the fans',
            $image === 6 => 'My driver is quite popular with the fans',
            default      => 'My driver is a favourite of the fans',
        };
    }

    private function amountFor(int $patience): string
    {
        return match (true) {
            $patience <= 2 => 'OK',
            $patience <= 4 => 'A bit too low',
            $patience <= 6 => 'Far too low',
            default        => 'Unacceptable',
        };
    }

    private function durationFor(int $patience): string
    {
        return match (true) {
            $patience <= 2 => 'OK',
            $patience <= 4 => 'OK',
            $patience <= 6 => 'A bit too low',
            default        => 'Far too low',
        };
    }
}
