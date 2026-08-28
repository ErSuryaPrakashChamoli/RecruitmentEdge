<?php

namespace App\Enums;

enum FeedbackRecommendation: string
{
    case StronglyRecommend = 'strongly_recommend';
    case Recommend = 'recommend';
    case Neutral = 'neutral';
    case NotRecommend = 'not_recommend';
    case StronglyNotRecommend = 'strongly_not_recommend';

    public function label(): string
    {
        return match ($this) {
            self::StronglyRecommend => 'Strongly Recommend',
            self::Recommend => 'Recommend',
            self::Neutral => 'Neutral',
            self::NotRecommend => 'Not Recommend',
            self::StronglyNotRecommend => 'Strongly Not Recommend',
        };
    }
}
