-- 0003 Role hierarchy and the Phase 1 role set (10 roles).
-- Data-preserving upgrade from Phase 0 roles:
--   admin  -> super_admin   (Phase 0 'admin' held every permission, i.e. it was the Owner; privileges are unchanged)
--   staff  -> manager       (Phase 1 merges "Manager / Salaried Staff" into one role; users keep access)
-- 'administrator' and the new roles are created by `permissions:sync`.

ALTER TABLE roles
    ADD COLUMN level SMALLINT UNSIGNED NOT NULL DEFAULT 10 AFTER description,
    ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER is_system;

ALTER TABLE permissions
    ADD COLUMN is_sensitive TINYINT(1) NOT NULL DEFAULT 0 AFTER description;

UPDATE roles SET slug = 'super_admin', name = 'Super Admin / Owner'
 WHERE slug = 'admin' AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM roles WHERE slug = 'super_admin') existing);

INSERT INTO roles (slug, name, description, is_system, created_at, updated_at)
SELECT 'manager', 'Manager / Salaried Staff', 'Operational role', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()
 WHERE EXISTS (SELECT 1 FROM (SELECT id FROM roles WHERE slug = 'staff') s)
   AND NOT EXISTS (SELECT 1 FROM (SELECT id FROM roles WHERE slug = 'manager') m);

INSERT IGNORE INTO user_roles (user_id, role_id, assigned_by, created_at)
SELECT ur.user_id, m.id, ur.assigned_by, ur.created_at
  FROM user_roles ur
  JOIN roles s ON s.id = ur.role_id AND s.slug = 'staff'
  JOIN roles m ON m.slug = 'manager';

DELETE ur FROM user_roles ur JOIN roles s ON s.id = ur.role_id AND s.slug = 'staff';

DELETE FROM roles WHERE slug = 'staff';
