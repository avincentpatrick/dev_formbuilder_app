-- Throw-away passwords for the LOCAL stack only (docker-compose.yml runs this after
-- bootstrap-roles.sql). The testing box never runs this file; its operator sets real passwords by
-- hand. Matching values are in .env.example's local section.
ALTER ROLE bhw_app PASSWORD 'bhw-local';
ALTER ROLE authenticator PASSWORD 'bhw-local';
