UPDATE employees
SET priority = CASE slack_user_id
    WHEN 'U0B6HH47VCP' THEN 2
    WHEN 'U08JC09FDK3' THEN 1
END
WHERE slack_user_id IN ('U0B6HH47VCP', 'U08JC09FDK3');
