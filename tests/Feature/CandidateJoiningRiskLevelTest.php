<?php

use App\Enums\JoiningStatus;
use App\Models\CandidateJoining;
use App\Models\RecruitmentSetting;

test('a joined candidate is always green', function (): void {
    $joining = CandidateJoining::factory()->create(['status' => JoiningStatus::Joined, 'expected_doj' => now()->subWeek()]);

    expect($joining->riskLevel())->toBe('green');
});

test('a no-show or dropout is always red', function (): void {
    $noShow = CandidateJoining::factory()->create(['status' => JoiningStatus::NoShow, 'expected_doj' => now()->addWeek()]);
    $dropout = CandidateJoining::factory()->create(['status' => JoiningStatus::Dropout, 'expected_doj' => now()->addWeek()]);

    expect($noShow->riskLevel())->toBe('red')
        ->and($dropout->riskLevel())->toBe('red');
});

test('an overdue expected join that has not happened is red', function (): void {
    $joining = CandidateJoining::factory()->create(['status' => JoiningStatus::Expected, 'expected_doj' => now()->subDay()]);

    expect($joining->riskLevel())->toBe('red');
});

test('a confirmed joining with a future date is green', function (): void {
    $joining = CandidateJoining::factory()->create(['status' => JoiningStatus::Confirmed, 'expected_doj' => now()->addWeek()]);

    expect($joining->riskLevel())->toBe('green');
});

test('an unconfirmed joining approaching the follow-up window is yellow, otherwise green', function (): void {
    $soon = CandidateJoining::factory()->create(['status' => JoiningStatus::Expected, 'expected_doj' => now()->addDays(2)]);
    $farOut = CandidateJoining::factory()->create(['status' => JoiningStatus::Expected, 'expected_doj' => now()->addDays(10)]);

    expect($soon->riskLevel())->toBe('yellow')
        ->and($farOut->riskLevel())->toBe('green');
});

test('the follow-up window is configurable via recruitment settings', function (): void {
    RecruitmentSetting::put('joining_risk_followup_days', 14, 'int');

    $joining = CandidateJoining::factory()->create(['status' => JoiningStatus::Expected, 'expected_doj' => now()->addDays(10)]);

    expect($joining->riskLevel())->toBe('yellow');
});
