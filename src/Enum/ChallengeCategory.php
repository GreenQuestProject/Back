<?php

namespace App\Enum;

enum ChallengeCategory: string
{
    case ECOLOGY = 'ecology';
    case COMMUNITY = 'community';
    case EDUCATION = 'education';
    case PERSONAL_DEVELOPMENT = 'personal_development';

    case NONE = 'none';
}
