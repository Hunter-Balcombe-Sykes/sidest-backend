-- 1) Create runtime role
create role app_backend login password 'blaze@@1967HBS' bypassrls;

-- 2) Allow it to connect
grant connect on database postgres to app_backend;

-- 3) Schema usage
grant usage on schema core, public, analytics to app_backend;

-- 4) Table privileges (tighten to only what Laravel needs)
grant select, insert, update, delete on all tables in schema core to app_backend;
grant select, insert, update, delete on all tables in schema public to app_backend;
grant select on all tables in schema analytics to app_backend;

-- 5) Sequences (for inserts that use serial/identity)
grant usage, select on all sequences in schema core to app_backend;
grant usage, select on all sequences in schema public to app_backend;

-- 6) Make future tables get privileges automatically (optional but recommended)
alter default privileges in schema core
  grant select, insert, update, delete on tables to app_backend;
alter default privileges in schema public
  grant select, insert, update, delete on tables to app_backend;
alter default privileges in schema analytics
  grant select on tables to app_backend;

alter default privileges in schema core
  grant usage, select on sequences to app_backend;
alter default privileges in schema public
  grant usage, select on sequences to app_backend;
