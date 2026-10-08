-- Client #1 migration diagnostic — READ-ONLY (SELECT statements only).
--
-- Run against a RESTORED COPY of the Client #1 database, never against production:
--   mysql -u <read-only user> -p -h <rehearsal host> <copy database> --table < docs/runbooks/client-1-migration-diagnostic.sql > client-1-diagnostic.txt
--
-- Reports roles, permissions, assignment counts, migration status, schema state and the data the
-- remaining migrations depend on. It prints role and permission names and counts only: no person,
-- candidate, email or credential data. MySQL 8.
-- Expected values for a Client #1 copy at the failed state are given beside each section.

SELECT 'C1 identity' AS section, DATABASE() AS database_name, VERSION() AS mysql_version, NOW() AS collected_at;

-- 1. Migration status. Expected at the failed state: 81 rows, last 2026_09_25_134640_add_pipeline_columns_to_recruitment_tables;
--    2026_09_25_135221_grant_phase_four_permissions NOT recorded; nothing from 2026_09_26 onwards.
SELECT 'C1.1 migrations' AS section, COUNT(*) AS migrations, MAX(migration) AS last_migration, MAX(batch) AS last_batch FROM migrations;
SELECT 'C1.1 migrations after the 75-migration release line' AS section, id, migration, batch
FROM migrations WHERE migration > '2026_09_14_124342_create_interviewers_table' ORDER BY id;
SELECT 'C1.1 failed migration recorded?' AS section, COUNT(*) AS recorded_expected_0 FROM migrations WHERE migration = '2026_09_25_135221_grant_phase_four_permissions';

-- 2. Schema state. Expected: roles without tenant_id and key; no tenants / tenant_memberships tables;
--    the five Phase 4 tables present (their migrations ran before the failure).
SELECT 'C1.2 roles columns' AS section, GROUP_CONCAT(column_name ORDER BY ordinal_position) AS columns
FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'roles';
SELECT 'C1.2 team columns present' AS section, table_name, column_name
FROM information_schema.columns
WHERE table_schema = DATABASE() AND column_name IN ('tenant_id', 'team_id') AND table_name IN ('roles', 'model_has_roles', 'model_has_permissions');
SELECT 'C1.2 SaaS tables present (expected none)' AS section, table_name
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('tenants', 'tenant_memberships', 'tenant_invitations');
SELECT 'C1.2 Phase 4 tables present (expected 5)' AS section, table_name
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN
('recruitment_stages', 'recruitment_stage_transitions', 'recruitment_pipeline_templates', 'recruitment_pipeline_template_stages', 'requisition_pipeline_stages');
SELECT 'C1.2 tables and rows (estimate)' AS section, COUNT(*) AS tables, SUM(table_rows) AS approximate_rows
FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE';

-- 3. Roles: ids, names, guards, assignment and permission counts. Expected: no 'employee' role;
--    every role on guard 'web'.
SELECT 'C1.3 roles' AS section, r.id, r.name, r.guard_name,
       (SELECT COUNT(*) FROM model_has_roles mr WHERE mr.role_id = r.id) AS assignments,
       (SELECT COUNT(*) FROM role_has_permissions rp WHERE rp.role_id = r.id) AS permissions
FROM roles r ORDER BY r.id;
SELECT 'C1.3 employee role' AS section, COUNT(*) AS employee_roles FROM roles WHERE name = 'employee';
SELECT 'C1.3 roles not on guard web (expected none)' AS section, id, name, guard_name FROM roles WHERE guard_name <> 'web';
SELECT 'C1.3 duplicate role names (expected none)' AS section, LOWER(name) AS name, guard_name, COUNT(*) AS roles
FROM roles GROUP BY LOWER(name), guard_name HAVING COUNT(*) > 1;

-- 4. Permissions. The 2026_09_27_075958 (Phase 8.5) migration requires offers.manage and
--    performance.view to exist whenever any role exists: both rows must be present (expected 2).
SELECT 'C1.4 permissions' AS section, COUNT(*) AS permissions, SUM(guard_name = 'web') AS web_guard FROM permissions;
SELECT 'C1.4 Phase 8.5 prerequisites (expected 2)' AS section, COUNT(*) AS present
FROM permissions WHERE guard_name = 'web' AND name IN ('offers.manage', 'performance.view');
SELECT 'C1.4 duplicate permissions (expected none)' AS section, name, guard_name, COUNT(*) AS rows_
FROM permissions GROUP BY name, guard_name HAVING COUNT(*) > 1;

-- 5. Partial Phase 4 state left by the failed run. Expected at the failed state: the 9 Phase 4
--    permissions exist; vp_hr, manager, assistant_manager and recruiter hold them; chro not yet.
SELECT 'C1.5 Phase 4 permissions present' AS section, COUNT(*) AS present_of_9 FROM permissions
WHERE guard_name = 'web' AND name IN ('pipeline.configure', 'pipeline.override', 'talent-pools.viewAny', 'talent-pools.manage',
  'referrals.submit', 'referrals.review', 'candidates.override-duplicate', 'portal.manage', 'interview-slots.manage');
SELECT 'C1.5 Phase 4 grants per role' AS section, r.id, r.name, COUNT(p.id) AS phase_4_permissions_held
FROM roles r
LEFT JOIN role_has_permissions rp ON rp.role_id = r.id
LEFT JOIN permissions p ON p.id = rp.permission_id AND p.name IN ('pipeline.configure', 'pipeline.override', 'talent-pools.viewAny',
  'talent-pools.manage', 'referrals.submit', 'referrals.review', 'candidates.override-duplicate', 'portal.manage', 'interview-slots.manage')
GROUP BY r.id, r.name ORDER BY r.id;

-- 6. Role and direct permission assignments, and orphans (expected 0 orphans).
SELECT 'C1.6 role assignments' AS section, model_type, COUNT(*) AS assignments, COUNT(DISTINCT model_id) AS holders
FROM model_has_roles GROUP BY model_type;
SELECT 'C1.6 direct permission assignments' AS section, model_type, COUNT(*) AS assignments FROM model_has_permissions GROUP BY model_type;
SELECT 'C1.6 orphaned role assignments' AS section,
       (SELECT COUNT(*) FROM model_has_roles mr LEFT JOIN roles r ON r.id = mr.role_id WHERE r.id IS NULL) AS without_role,
       (SELECT COUNT(*) FROM model_has_roles mr LEFT JOIN users u ON u.id = mr.model_id WHERE mr.model_type = 'App\\Models\\User' AND u.id IS NULL) AS without_login;

-- 7. Staff shape (counts only). Every login becomes a Tenant #1 member, linked to its employee.
SELECT 'C1.7 logins' AS section, COUNT(*) AS logins, SUM(employee_id IS NOT NULL) AS linked_to_employee, SUM(employee_id IS NULL) AS not_linked FROM users;
SELECT 'C1.7 logins sharing an employee (expected none)' AS section, employee_id, COUNT(*) AS logins
FROM users WHERE employee_id IS NOT NULL GROUP BY employee_id HAVING COUNT(*) > 1;
SELECT 'C1.7 employees' AS section, status, COUNT(*) AS employees, SUM(reports_to_id IS NOT NULL) AS with_manager, SUM(deleted_at IS NOT NULL) AS soft_deleted
FROM employees GROUP BY status;
SELECT 'C1.7 reporting line anomalies (expected 0)' AS section,
       (SELECT COUNT(*) FROM employees WHERE reports_to_id = id) AS reports_to_self,
       (SELECT COUNT(*) FROM employees e LEFT JOIN employees m ON m.id = e.reports_to_id WHERE e.reports_to_id IS NOT NULL AND m.id IS NULL) AS manager_missing;
SELECT 'C1.7 hierarchy closure' AS section, COUNT(*) AS rows_, MAX(depth) AS max_depth FROM employee_hierarchy;

-- 8. Business volumes (counts only), to compare before and after the rehearsal.
SELECT 'C1.8 volumes' AS section,
       (SELECT COUNT(*) FROM departments) AS departments,
       (SELECT COUNT(*) FROM designations) AS designations,
       (SELECT COUNT(*) FROM locations) AS locations,
       (SELECT COUNT(*) FROM candidates) AS candidates,
       (SELECT COUNT(*) FROM recruitment_requisitions) AS requisitions,
       (SELECT COUNT(*) FROM candidate_applications) AS applications,
       (SELECT COUNT(*) FROM interviews) AS interviews,
       (SELECT COUNT(*) FROM offers) AS offers,
       (SELECT COUNT(*) FROM candidate_stage_histories) AS stage_histories,
       (SELECT COUNT(*) FROM audit_logs) AS audit_logs;
