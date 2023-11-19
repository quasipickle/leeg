<?php

namespace App\Controllers;

use Kint\Renderer\PlainRenderer;

class Home extends BaseController
{
    public function index(): string
    {
        $slots = [];
        foreach (DayOfWeek::cases() as $dow) {
            foreach (Time::cases() as $time) {
                $slots[] = new Slot(count($slots), $dow, $time);
            }
        }

        $teams = [
            new Team("SD", [
                new Preference(2, PreferenceType::Preferred),
                new Preference(0, PreferenceType::Blacklist),
                new Preference(1, PreferenceType::Blacklist)
            ]),
            new Team("Spikers", [
                new Preference(4, PreferenceType::Avoid),
                new Preference(5, PreferenceType::Avoid)
            ]),
            new Team("Beached", [
                new Preference(0, PreferenceType::Preferred),
                new Preference(2, PreferenceType::Preferred),
                new Preference(4, PreferenceType::Preferred)
            ]),
            new Team("Dolomites",[
                new Preference(4, PreferenceType::Preferred),
                new Preference(5, PreferenceType::Preferred)
            ])
        ];

        $Schedule = new Schedule($slots, $teams);
        $Schedule->generate();

        return view('index');
    }
}



enum Time
{
    case EARLY;
    case LATE;
}

enum DayOfWeek
{
    case Tuesday;
    case Wednesday;
    case Thursday;
}

enum PreferenceType: string
{
    case Preferred = "P";
    case Avoid = "A";
    case Blacklist = "B";
}

class Slot
{
    public function __construct(
        public int $id,
        public DayOfWeek $dow,
        public Time $time
    ) {}
}

class Preference
{
    public function __construct(
        public int $slotId,
        public PreferenceType $type
    ) {}
}

class Team
{
    /**
     * An keyed by preference type value,
     * valued by an array of slot ids;
     */
    private array $sortedPreferences;

    public function __construct(
        public string $name,
        array $preferences,

    ) {
        foreach(PreferenceType::cases() as $PreferenceType)
        {
            $matchingSlotIds = array_column(
                array_filter($preferences, fn($pref) => $pref->type == $PreferenceType),
                "slotId"
            );
            $this->sortedPreferences[$PreferenceType->value] = $matchingSlotIds;
        }
    }

    public function getPreferences(PreferenceType $preference): array
    {
        return $this->sortedPreferences[$preference->value];
    }
}

class Matchup
{
    public function __construct(
        public Team $team1,
        public Team $team2,
        public ?int $slotId,
    ) {}
}

class Schedule
{
    public array $matchups = [];
    public array $noMatchups = [];

    public function __construct(
        public array $slots, // Array of Slot objects
        public array $teams // Array of Team objects
    ) {}

    public function isSlotFree(int $slotId): bool
    {
        foreach($this->matchups as $matchup) {
            if ($matchup->slotId == $slotId) {
                return false;
            }
        }
        return true;
    }

    public function generate()
    {
        shuffle($this->teams);

        // first pass is for teams that have blacklists
        $blacklistTeams = array_filter($this->teams, fn($Team) => count($Team->getPreferences(PreferenceType::Blacklist)) > 0);
        $this->generateMatchups($blacklistTeams, $this->teams);        

        // then everyone left over
        $this->generateMatchups($this->teams, $this->teams);

        d($this->matchups);
    }

    private function generateMatchups($teams1, $teams2)
    {
        foreach ($teams1 as $team1) {
            foreach ($teams2 as $team2) {
                if($team1->name == $team2->name) {
                    continue;
                }
                
                if ($this->areMatchedUp($team1, $team2)) {
                    continue;
                }

                // Try to matchup mutual preferred slots
                // todo: change getPreferences to getSlotIds and use elsewhere
                $team1Preferred = $team1->getPreferences(PreferenceType::Preferred);
                $team2Preferred = $team2->getPreferences(PreferenceType::Preferred);

                $commonPreferred = array_intersect($team1Preferred, $team2Preferred);

                foreach ($commonPreferred as $slotId) {
                    if ($this->isSlotFree($slotId)) {
                        $this->matchups[] = new Matchup($team1, $team2, $slotId);
                        continue 2;
                    }
                }

                // Try to at least match a slot that team 2 is ok with
                $team2NiceSlotIds = $this->getSlotIdsExcept($team2, [PreferenceType::Avoid, PreferenceType::Blacklist]);
                $commonAllowedSlotIds = array_intersect($team1Preferred, $team2NiceSlotIds);
                foreach ($commonAllowedSlotIds as $slotId) {
                    if ($this->isSlotFree($slotId)) {
                        $this->matchups[] = new Matchup($team1, $team2, $slotId);
                        continue 2;
                    }
                }

                // Try to match up mutual non-Avoid & non-Blacklist
                $team1NiceSlotIds = $this->getSlotIdsExcept($team1, [PreferenceType::Avoid, PreferenceType::Blacklist]);
                $commonNiceSlotIds = array_intersect($team1NiceSlotIds, $team2NiceSlotIds);
                foreach ($commonNiceSlotIds as $slotId) {
                    if ($this->isSlotFree($slotId)) {
                        $this->matchups[] = new Matchup($team1, $team2, $slotId);
                        continue 2;
                    }
                }

                // Try to match up any slot that isn't blacklisted
                $team1AllowedSlotIds = $this->getSlotIdsExcept($team1, [PreferenceType::Blacklist]);
                $team2AllowedSlotIds = $this->getSlotIdsExcept($team2, [PreferenceType::Blacklist]);
                $commonAllowedSlotIds = array_intersect($team1AllowedSlotIds, $team2AllowedSlotIds);
                foreach ($commonAllowedSlotIds as $slotId) {
                    if ($this->isSlotFree($slotId)) {
                        $this->matchups[] = new Matchup($team1, $team2, $slotId);
                        continue 2;
                    }
                }

                // no match-up possible
                $this->noMatchups[] = new Matchup($team1, $team2, null);
            }
        }
    }

    private function areMatchedUp($team1, $team2): bool
    {
        foreach ($this->matchups as $Matchup) {
            if (($Matchup->team1->name == $team1->name && $Matchup->team2->name == $team2->name)
                || ($Matchup->team1->name == $team2->name && $Matchup->team2->name == $team1->name)) {
                return true;
            }
        }

        return false;
    }

    /** 
     * Get all slots ids that aren't Avoid or BlackList for the passed team
     */
    public function getSlotIdsExcept(Team $team, array $exceptTypes): array
    {
        $badSlotIds = [];
        foreach($exceptTypes as $type)
        {
            $badSlotIds += array_column(
                array_filter($team->getPreferences($type)),
                "slotId"
            );
        }

        $allSlotIds = array_column($this->slots, "id");

        return array_diff($allSlotIds, $badSlotIds);
    }

    /**
     * Get a common preference between the two Preference[]
     * 
     * @param $slots1 an array of slot ids
     * @param $prefs2 an array of more slot ids
     * 
     * @return an array of slot ids that exist in both passed arrays.  May be empty.
     */
    public function findCommonSlots(array $slots1, array $slots2): array
    {
        $common = [];
        foreach($slots1 as $slot1)
        {
            $matches = array_filter($slots2, fn($slot2) => $slot1 == $slot2);
            $common += $matches;
        }
        return $common;
    }
}
