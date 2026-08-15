<?php

namespace App\Oracly\Services;

/** Chooses the lower-probability exact score between 0-3 and 3-0. */
final class AgainstThreeGoalsStrategy extends AgainstOneGoalStrategy
{
    protected const TARGET_GOALS = 3;
}
