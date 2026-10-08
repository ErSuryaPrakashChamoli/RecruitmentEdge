---
paths:
  - 'app/Services/Distribution/**'
---

# Distribution

## Job publishing goes through JobDistributionService
Publish/unpublish/pause only via JobDistributionService; it refuses non-Open (unapproved) requisitions and duplicate channels. Job boards without API access use UnavailableJobBoardConnector and record an honest Failed "not configured" distribution — never a fake success. Career applications enter the existing pipeline via CareerApplicationService.
