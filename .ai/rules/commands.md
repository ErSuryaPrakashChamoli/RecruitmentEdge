---
paths:
  - 'database/seeders/Demo/**,app/Console/Commands/SetupDemo.php,config/demo.php'
---

# Commands

## Demo installation: seed through services on the DemoTimeline; demo:setup is guarded by APP_DEMO
The client-presentation demo is the same code on its own database (.env.demo, APP_DEMO=true). `demo:setup` wipes + seeds via Database\Seeders\Demo\DemoSeeder and refuses to run unless config('demo.enabled'). The story is played chronologically by DemoTimeline (generators yield the moment they act; the clock is frozen there via DemoContext::freeze) THROUGH THE REAL SERVICES (StageTransitionService, InterviewService, OfferService, CandidateJoiningService, incentive/approval services) so histories, notifications and audit logs are authentic — never insert pipeline rows directly. Randomness comes only from the seeded DemoContext faker (deterministic). When adding a module, also give it demo data here; tests/Feature/DemoEnvironmentTest.php sweeps every page/resource/relation manager/widget for every demo login (run it at full scale with DEMO_TEST_SCALE=1).
