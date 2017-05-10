<?php

namespace Xoco70\KendoTournaments\TreeGen;

use Illuminate\Support\Collection;
use Xoco70\KendoTournaments\Contracts\TreeGenerable;
use Xoco70\KendoTournaments\Exceptions\TreeGenerationException;
use Xoco70\KendoTournaments\Models\Championship;
use Xoco70\KendoTournaments\Models\ChampionshipSettings;
use Xoco70\KendoTournaments\Models\Competitor;
use Xoco70\KendoTournaments\Models\FightersGroup;
use Xoco70\KendoTournaments\Models\Team;

class TreeGen implements TreeGenerable
{
    protected $groupBy;
    protected $tree;
    public $championship;
    public $settings;
    protected $numFighters;

    /**
     * @param Championship $championship
     * @param $groupBy
     */
    public function __construct(Championship $championship, $groupBy)
    {
        $this->championship = $championship;
        $this->groupBy = $groupBy;
        $this->settings = $championship->settings;
        $this->tree = new Collection();
    }

    /**
     * Generate tree groups for a championship.
     *
     * @throws TreeGenerationException
     */
    public function run()
    {
        $usersByArea = $this->getFightersByArea();
        $numFighters = sizeof($usersByArea->collapse());

        $this->generateGroupsForRound($usersByArea, $area = 1, $round = 1, $shuffle = 1);
        $this->pushEmptyGroupsToTree($numFighters);
        $this->addParentToChildren($numFighters);
        // Now add parents to all
    }

    /**
     * Get the biggest entity group
     * @param $userGroups
     *
     * @return int
     */
    private function getMaxFightersByEntity($userGroups): int
    {
        return $userGroups
            ->sortByDesc(function ($group) {
                return $group->count();
            })
            ->first()
            ->count();

    }

    /**
     * Get Competitor's list ordered by entities
     * Countries for Internation Tournament, State for a National Tournament, etc.
     *
     * @return Collection
     */
    private function getFightersByEntity($fighters): Collection
    {
        // Right now, we are treating users and teams as equals.
        // It doesn't matter right now, because we only need name attribute which is common to both models

        // $this->groupBy contains federation_id, association_id, club_id, etc.
        if (($this->groupBy) != null) {
            $fighterGroups = $fighters->groupBy($this->groupBy); // Collection of Collection
        } else {
            $fighterGroups = $fighters->chunk(1); // Collection of Collection
        }
        return $fighterGroups;
    }

    /**
     * Calculate the Byes need to fill the Championship Tree.
     * @param Championship $championship
     * @return Collection
     */
    private function getByeGroup(Championship $championship, $fighters)
    {
        $groupSizeDefault = 3;
        $preliminaryGroupSize = 2;

        $fighterCount = $fighters->count();

        if ($championship->hasPreliminary()) {
            $preliminaryGroupSize = $championship->settings != null
                ? $championship->settings->preliminaryGroupSize
                : $groupSizeDefault;
        } elseif ($championship->isDirectEliminationType()) {
            $preliminaryGroupSize = 2;
        } else {
            // No Preliminary and No Direct Elimination --> Round Robin
            // Should Have no tree
        }
        $treeSize = $this->getTreeSize($fighterCount, $preliminaryGroupSize);
        $byeCount = $treeSize - $fighterCount;

        return $this->createNullsGroup($byeCount, $championship->category->isTeam);
    }

    /**
     * Get the size the first round will have
     * @param $fighterCount
     * @return int
     */
    private function getTreeSize($fighterCount, $groupSize)
    {
        $square = collect([1, 2, 4, 8, 16, 32, 64]);
        $squareMultiplied = $square->map(function($item, $key) use ($groupSize) {
            return $item * $groupSize;
        });

        foreach ($squareMultiplied as $limit) {
            if ($fighterCount <= $limit) {
                dd($limit);
                return $limit;
            }
        }
        return 64 * $groupSize;
    }

    /**
     * Create Bye Groups to adjust tree
     * @param $byeCount
     * @return Collection
     */
    private function createNullsGroup($byeCount, $isTeam): Collection
    {
        $isTeam
            ? $null = new Team()
            : $null = new Competitor();

        $byeGroup = new Collection();
        for ($i = 0; $i < $byeCount; $i++) {
            $byeGroup->push($null);
        }
        return $byeGroup;
    }

    /**
     * Repart BYE in the tree,
     * @param $fighterGroups
     * @param int $max
     *
     * @return Collection
     */
    private function repart($fighterGroups, $max)
    {
        $fighters = new Collection();
        for ($i = 0; $i < $max; $i++) {
            foreach ($fighterGroups as $fighterGroup) {
                $fighter = $fighterGroup->values()->get($i);
                if ($fighter != null) {
                    $fighters->push($fighter);
                }
            }
        }

        return $fighters;
    }

    /**
     * Insert byes in an homogen way.
     *
     * @param Collection $fighters
     * @param Collection $byeGroup
     *
     * @return Collection
     */
    private function insertByes(Collection $fighters, Collection $byeGroup)
    {
        $bye = count($byeGroup) > 0 ? $byeGroup[0] : [];
        $sizeFighters = count($fighters);
        $sizeGroupBy = count($byeGroup);

        $frequency = $sizeGroupBy != 0
            ? (int)floor($sizeFighters / $sizeGroupBy)
            : -1;

        // Create Copy of $competitors
        $newFighters = new Collection();
        $i = 0;
        $byeCount = 0;
        foreach ($fighters as $fighter) {
            if ($frequency != -1 && $i % $frequency == 0 && $byeCount < $sizeGroupBy) {
                $newFighters->push($bye);
                $byeCount++;
            }
            $newFighters->push($fighter);
            $i++;
        }

        return $newFighters;
    }

    /**
     * Fighter is the name for competitor or team, depending on the case
     * @return Collection
     */
    private function getFighters()
    {
        $this->championship->category->isTeam()
            ? $fighters = $this->championship->teams
            : $fighters = $this->championship->competitors;

        return $fighters;
    }

    /**
     * @param Collection $usersByArea
     * @param integer $area
     * @param integer $round
     * @param integer $shuffle
     *
     */
    public function generateGroupsForRound($usersByArea, $area, $round, $shuffle)
    {
        foreach ($usersByArea as $fightersByEntity) {
            // Chunking to make small round robin groups
            $fightersGroup = $this->chunkAndShuffle($round, $shuffle, $fightersByEntity);
            $order = 1;
            foreach ($fightersGroup as $value => $fighters) {
                $this->saveGroupAndSync($fighters, $area, $order, $round, $parent = null, $shuffle);
                $order++;
            }
            $area++;
        }
    }

    /**
     * @param $fighters
     * @param $area
     * @param integer $order
     * @param $round
     * @return FightersGroup
     */
    public function saveGroupAndSync($fighters, $area, $order, $round, $parent, $shuffle)
    {
        $fighters = $fighters->pluck('id');
        if ($shuffle) {
            $fighters->shuffle();
        }
        $group = $this->saveGroup($area, $order, $round, $parent);

        // Add all competitors to Pivot Table
        if ($this->championship->category->isTeam()) {
            $group->syncTeams($fighters);
        } else {
            $group->syncCompetitors($fighters);
        }

        return $group;
    }

    /**
     * Create empty groups for direct Elimination Tree
     * @param $numFighters
     */
    public function pushEmptyGroupsToTree($numFighters)
    {
        $numFightersEliminatory = $numFighters;
        // We check what will be the number of groups after the preliminaries
        if ($this->championship->hasPreliminary()) {
            $numFightersEliminatory = $numFighters / $this->championship->getSettings()->preliminaryGroupSize * 2;
        }
        // We calculate how much rounds we will have
        $numRounds = intval(log($numFightersEliminatory, 2));
        $this->pushGroups($numRounds, $numFightersEliminatory, $shuffle = 1);
    }

    /**
     * @param $area
     * @param $order
     * @param $round
     * @param $parent
     * @return FightersGroup
     */
    private function saveGroup($area, $order, $round, $parent): FightersGroup
    {
        $group = new FightersGroup();
        $group->area = $area;
        $group->order = $order;
        $group->round = $round;
        $group->championship_id = $this->championship->id;
        if ($parent != null) {
            $group->parent_id = $parent->id;
        }
        $group->save();
        return $group;
    }

    private function createByeFighter()
    {
        return $this->championship->category->isTeam
            ? new Team()
            : new Competitor();
    }

    /**
     * @param integer $groupSize
     */
    public function createByeGroup($groupSize): Collection
    {
        $byeFighter = $this->createByeFighter();
        $group = new Collection();
        for ($i = 0; $i < $groupSize; $i++) {
            $group->push($byeFighter);
        }
        return $group;
    }

    /**
     * @param $fighters
     * @param Collection $fighterGroups
     * @return Collection
     */
    public function adjustFightersGroupWithByes($fighters, $fighterGroups): Collection
    {
        $tmpFighterGroups = clone $fighterGroups;
        $byeGroup = $this->getByeGroup($this->championship, $fighters);

        // Get biggest competitor's group
        $max = $this->getMaxFightersByEntity($tmpFighterGroups);

        // We reacommodate them so that we can mix them up and they don't fight with another competitor of his entity.

        $fighters = $this->repart($fighterGroups, $max);
        $fighters = $this->insertByes($fighters, $byeGroup);

        return $fighters;
    }

    /**
     * Get All Groups on previous round
     * @param $currentRound
     * @return Collection
     */
    private function getPreviousRound($currentRound)
    {
        $previousRound = $this->championship->groupsByRound($currentRound + 1)->get();
        return $previousRound;
    }

    /**
     * Get the next group on the right ( parent ), final round being the ancestor
     * @param $matchNumber
     * @param Collection $previousRound
     * @return mixed
     */
    private function getParentGroup($matchNumber, $previousRound)
    {
        $parentIndex = intval(($matchNumber + 1) / 2);
        $parent = $previousRound->get($parentIndex - 1);
        return $parent;
    }

    /**
     * Save Groups with their parent info
     * @param integer $numRounds
     * @param $numFightersEliminatory
     */
    private function pushGroups($numRounds, $numFightersEliminatory, $shuffle = true)
    {
        for ($roundNumber = 2; $roundNumber <= $numRounds; $roundNumber++) {
            // From last match to first match
            for ($matchNumber = 1; $matchNumber <= ($numFightersEliminatory / pow(2, $roundNumber)); $matchNumber++) {
                $fighters = $this->createByeGroup(2);
                $this->saveGroupAndSync($fighters, $area = 1, $order = $matchNumber, $roundNumber, $parent = null, $shuffle);
            }
        }
    }

    /**
     * Group Fighters by area
     * @return Collection
     * @throws TreeGenerationException
     */
    private function getFightersByArea()
    {
        // If previous trees already exists, delete all
        $this->championship->fightersGroups()->delete();
        $areas = $this->settings->fightingAreas;
        $fighters = $this->getFighters();

        if ($fighters->count() / $areas < ChampionshipSettings::MIN_COMPETITORS_BY_AREA) {
            throw new TreeGenerationException();
        }
        // Get Competitor's / Team list ordered by entities ( Federation, Assoc, Club, etc...)
        $fighterByEntity = $this->getFightersByEntity($fighters); // Chunk(1)
        $fightersWithBye = $this->adjustFightersGroupWithByes($fighters, $fighterByEntity);

        // Chunk user by areas
        return $fightersWithBye->chunk(count($fightersWithBye) / $areas);
    }

    /**
     * Chunk Fighters into groups for fighting, and optionnaly shuffle
     * @param $round
     * @param $shuffle
     * @param $fightersByEntity
     * @return mixed
     */
    private function chunkAndShuffle($round, $shuffle, $fightersByEntity)
    {
        if ($this->championship->hasPreliminary()) {
            $fightersGroup = $fightersByEntity->chunk($this->settings->preliminaryGroupSize);
            if ($shuffle) {
                $fightersGroup->shuffle();
            }
        } elseif ($this->championship->isDirectEliminationType() || $round > 1) {
            $fightersGroup = $fightersByEntity->chunk(2);
            if ($shuffle) {
                $fightersGroup->shuffle();
            }
        } else { // Round Robin
            $fightersGroup = $fightersByEntity->chunk($fightersByEntity->count());
        }
        return $fightersGroup;
    }

    /**
     * Attach a parent to every child for nestedSet Navigation
     */
    private function addParentToChildren($numFightersEliminatory)
    {
        $numRounds = intval(log($numFightersEliminatory, 2));

        $groupsDesc = $this->championship
            ->fightersGroups()
            ->where('round', '<', $numRounds)
            ->orderByDesc('id')->get();

        $groupsDescByRound = $groupsDesc->groupBy('round');

        foreach ($groupsDescByRound as $round => $groups) {
            $previousRound = $this->getPreviousRound($round, $numRounds);
            foreach ($groups->reverse()->values() as $matchNumber => $group) {
                $parent = $this->getParentGroup($matchNumber + 1, $previousRound);
                $group->parent_id = $parent->id;
                $group->save();
            }
        }
    }
}
