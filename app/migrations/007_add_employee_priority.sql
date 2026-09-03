ALTER TABLE employees
    ADD COLUMN priority SMALLINT NOT NULL DEFAULT 0;

ALTER TABLE employees
    ADD CONSTRAINT chk_employees_priority_unsigned_tinyint
        CHECK (priority BETWEEN 0 AND 255);

UPDATE employees
SET priority = 10
WHERE slack_user_id = 'U45GVAC2C';
